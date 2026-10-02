import { test, expect, type Browser, type Page } from '@playwright/test';
import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import fs from 'node:fs';
import path from 'node:path';
import { connecter } from './connexion';

/**
 * Oracle du lot V2-04 — métadonnées, types, champs personnalisés, vues. Un persona crée SANS AIDE
 * un champ « Échéance » (date) sur le type Contrat, pose sa valeur sur un contrat du fonds, puis
 * enregistre la vue « Contrats à échéance < 90 j ». La bibliothèque montre les vraies vues et les
 * vrais documents du fonds ; une vue est un filtre, jamais un déplacement de fichier.
 *
 * Comme l'oracle « À traiter », le parcours démarre son propre serveur PHP sur son propre fonds
 * d'essai (var/oracles/socle/documents) : la base gedv2_test reste partagée, le fonds non.
 */

const RACINE = process.cwd();
const FONDS = path.join(RACINE, 'var', 'oracles', 'socle', 'documents');

type Preparation = { preparation: string; ids: { essai: number; maintenance: number; facture: number }; titre_essai: string };

let serveur: ChildProcess | undefined;
let base = '';
let prep: Preparation;

async function portLibre(): Promise<number> {
  return new Promise((resolve, reject) => {
    const sonde = createServer();
    sonde.once('error', reject);
    sonde.listen(0, '127.0.0.1', () => {
      const adresse = sonde.address();
      const port = typeof adresse === 'object' && adresse !== null ? adresse.port : 0;
      sonde.close(() => resolve(port));
    });
  });
}

async function attendreSante(url: string): Promise<void> {
  for (let essai = 0; essai < 100; essai++) {
    try {
      const reponse = await fetch(`${url}/sante`);
      if (reponse.ok) return;
    } catch {
      // le serveur PHP est encore en amorçage
    }
    await new Promise((resoudre) => setTimeout(resoudre, 100));
  }
  throw new Error('le serveur de l’oracle « Bibliothèque » n’a pas démarré');
}

/** Remise à neuf déterministe : fonds recréé, nomenclature reposée, aucun champ ni vue d’essai. */
function preparer(): Preparation {
  const sortie = execFileSync('php', ['tests/oracles/socle/preparer-bibliotheque.php'], {
    cwd: RACINE,
    env: { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: FONDS },
  }).toString();
  return JSON.parse(sortie) as Preparation;
}

async function pageConnectee(browser: Browser): Promise<{ page: Page; fermer: () => Promise<void> }> {
  const contexte = await browser.newContext({ baseURL: base, viewport: { width: 1440, height: 900 }, locale: 'fr-CH' });
  const page = await contexte.newPage();
  page.setDefaultTimeout(90_000);
  page.setDefaultNavigationTimeout(90_000);
  await connecter(page);

  return { page, fermer: () => contexte.close() };
}

/** Date ISO (YYYY-MM-DD) à J+n : le seul calcul du persona, fait comme au clavier. */
function dans(jours: number): string {
  return new Date(Date.now() + jours * 86_400_000).toISOString().slice(0, 10);
}

function surveillerConsole(page: Page): string[] {
  const erreurs: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') erreurs.push(m.text()); });
  page.on('pageerror', (e) => erreurs.push(e.message));
  return erreurs;
}

test.describe.configure({ mode: 'serial' });

test.beforeAll(async () => {
  fs.mkdirSync(path.dirname(FONDS), { recursive: true });
  const port = await portLibre();
  base = `http://127.0.0.1:${port}`;
  serveur = spawn('php', ['-d', 'opcache.enable=0', '-S', `127.0.0.1:${port}`, '-t', 'public', 'public/index.php'], {
    cwd: RACINE,
    env: { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: FONDS },
    stdio: 'ignore',
  });
  await attendreSante(base);
});

test.afterAll(() => {
  serveur?.kill();
});

test.beforeEach(() => {
  prep = preparer();
});

test('la bibliothèque montre les vraies vues et les vrais documents du fonds', async ({ browser }) => {
  test.setTimeout(90_000);   // la préparation attend le verrou de gedv2_test (base partagée entre agents)
  const { page, fermer } = await pageConnectee(browser);
  const erreurs = surveillerConsole(page);
  try {
    await page.goto('/bibliotheque');

    await expect(page.getByTestId('ecran-bibliotheque')).toBeVisible();
    // La base de test est partagée entre parcours : on n'asserte QUE les documents de CE fonds
    // (protocole « À traiter ») — jamais un compte global, que les restes d'un autre parcours fausseraient.
    await expect(page.getByTestId(`ligne-${prep.ids.essai}`)).toBeVisible();
    await expect(page.getByTestId(`ligne-${prep.ids.maintenance}`)).toBeVisible();
    await expect(page.getByTestId(`ligne-${prep.ids.facture}`)).toBeVisible();
    await expect(page.getByTestId('liste-documents')).toContainText('Contrat de maintenance CM-2026-04');
    await expect(page.getByTestId('liste-documents')).toContainText('Facture AN-5001');
    await expect(page.getByTestId('vues')).toContainText('Tous les documents');
    expect(erreurs, 'aucune erreur console').toEqual([]);
  } finally {
    await fermer();
  }
});

test('un persona crée le champ Échéance et la vue Contrats à échéance < 90 j, sans aide', async ({ browser }) => {
  test.setTimeout(90_000);   // la préparation attend le verrou de gedv2_test (base partagée entre agents)
  const { page, fermer } = await pageConnectee(browser);
  const erreurs = surveillerConsole(page);
  try {
    // 1. L'administrateur crée le champ « Échéance » (nature date) sur le type Contrat.
    await page.goto('/metadonnees');
    await page.getByTestId('champ-type-doc').selectOption({ label: 'Contrat' });
    await page.getByTestId('champ-nom-champ').fill('Échéance');
    await page.getByTestId('champ-nature').selectOption({ label: 'date' });
    await page.getByTestId('action-ajouter-champ').click();
    await expect(page.getByTestId('champs-contrat')).toContainText('Échéance');

    // 2. La valeur du champ se pose SUR LE DOCUMENT, dans le panneau de la bibliothèque.
    await page.goto('/bibliotheque');
    // L'index de test est partagé : le persona ouvre sa pièce du fonds préparé, même si un autre
    // oracle a produit un titre ressemblant dans son propre fonds.
    const lienEssai = page.getByTestId(`ligne-${prep.ids.essai}`).getByRole('link');
    await expect(lienEssai).toHaveAttribute('href', `/bibliotheque?doc=${prep.ids.essai}`);
    await lienEssai.click();
    await expect(page).toHaveURL(new RegExp(`/bibliotheque\\?doc=${prep.ids.essai}$`));
    await expect(page.getByTestId('apercu-meta')).toContainText('Contrat');
    const cheminAvant = ((await page.getByTestId('apercu-chemin').textContent()) ?? '').trim();

    await page.getByTestId('saisie-champ-echeance').fill(dans(45));
    await page.getByTestId('action-enregistrer-champs').click();
    await expect(page.getByTestId('saisie-champ-echeance')).toHaveValue(dans(45),
      'après enregistrement, la valeur revient du .meta.json (le dossier fait foi)');

    // 3. Filtres typés : type Contrat, champ Échéance dans moins de 90 jours.
    await page.getByTestId('filtres').click();
    await page.getByTestId('filtre-type-select').selectOption({ label: 'Contrat' });
    await page.getByTestId('filtre-champ-cle').selectOption({ label: 'Échéance' });
    await page.getByTestId('filtre-champ-op').selectOption({ label: 'dans moins de … jours' });
    await page.getByTestId('filtre-champ-valeur').fill('90');
    await page.getByTestId('action-appliquer-filtres').click();

    await expect(page.getByTestId('liste-documents')).toContainText(prep.titre_essai);
    await expect(page.getByTestId('liste-documents')).not.toContainText('Facture AN-5001',
      'la facture ne satisfait pas le filtre de type');
    await expect(page.getByTestId('filtre-type')).toContainText('Contrat');

    // 4. Enregistrer cette vue, la nommer comme le persona le ferait.
    await page.getByTestId('action-enregistrer-vue').click();
    await page.getByTestId('nom-nouvelle-vue').fill('Contrats à échéance < 90 j');
    await page.getByTestId('action-creer-vue').click();

    const lienVue = page.getByTestId('vues').getByRole('link', { name: /Contrats à échéance/ });
    await expect(lienVue).toBeVisible();
    await expect(lienVue).toContainText('1', 'un seul contrat du fonds a son échéance dans moins de 90 jours');

    // 5. La vue appliquée ne montre QUE les documents qui la satisfont — et aucun fichier n'a bougé.
    await lienVue.click();
    await expect(page).toHaveURL(/\/bibliotheque\?vue=\d+/);
    await expect(page.getByTestId(`ligne-${prep.ids.essai}`)).toBeVisible();
    await expect(page.getByTestId('liste-documents')).not.toContainText('Facture AN-5001');
    await expect(page.getByTestId('liste-documents')).not.toContainText('Contrat de maintenance',
      'le contrat sans échéance posée n’est pas retenu par la vue');

    const cheminApres = ((await page.getByTestId('apercu-chemin').textContent()) ?? '').trim();
    expect(cheminApres, 'une vue est un filtre : le fichier reste à sa place').toBe(cheminAvant);

    expect(erreurs, 'aucune erreur console').toEqual([]);
    await page.screenshot({ path: 'var/captures/bibliotheque-vue-echeance.png', fullPage: false });
  } finally {
    await fermer();
  }
});

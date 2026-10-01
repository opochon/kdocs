import { test, expect, type Browser, type Page } from '@playwright/test';
import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Oracle R1-ecrans — les écrans capturés sur l'instance installée mentaient : valeurs de maquette codées
 * en dur sur Application, type « Facture » pré-rempli sans proposition, « 1 pages », compteur de rail
 * incohérent, vues de la barre latérale figées. Ce parcours se connecte avec un compte RÉEL (créé par
 * l'installateur : adresse de courriel + mot de passe haché), sur son propre fonds synthétique
 * (`var/oracles/ecrans-reels/documents`), et confronte chaque valeur de l'écran à la configuration et à
 * l'état réellement posés par `tests/e2e/preparer-ecrans-reels.php`.
 *
 * Aucune donnée réelle : corpus synthétique, compte fictif, base gedv2_test.
 */

const RACINE = process.cwd();
const FONDS = 'var/oracles/ecrans-reels/documents';
const ENV = {
  ...process.env,
  DB_NAME: 'gedv2_test',
  APP_ENV: 'test',
  STORAGE_ROOT: FONDS,
  INGESTION_DOSSIER: 'var/oracles/ecrans-reels/entree',
  INGESTION_TRAVAIL: 'var/oracles/ecrans-reels/ingestion',
  INGESTION_IMAP_HOTE: 'mail.test',
  INGESTION_IMAP_PORT: '993',
  INGESTION_IMAP_UTILISATEUR: 'ingestion-oracle',
  INFOMANIAK_AI_MODEL_CLASSIFY: 'oracle/model-classify-1',
};

type Preparation = {
  preparation: string;
  compte: string;
  mot_de_passe: string;
  fonds: string;
  ids: { sans_proposition: number; avec_proposition: number; facture: number; contrat: number };
  vues: { factures: number; contrats: number };
  compteur: number;
  modele: string;
  racine: string;
  tache: string;
};

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
  throw new Error('le serveur de l’oracle « écrans réels » n’a pas démarré');
}

function preparer(): Preparation {
  const sortie = execFileSync('php', ['tests/e2e/preparer-ecrans-reels.php'], { cwd: RACINE, env: ENV }).toString();
  return JSON.parse(sortie) as Preparation;
}

async function pageConnectee(browser: Browser): Promise<{ page: Page; fermer: () => Promise<void> }> {
  const contexte = await browser.newContext({ baseURL: base, viewport: { width: 1440, height: 900 }, locale: 'fr-CH' });
  const page = await contexte.newPage();
  await page.goto('/connexion');
  await page.getByTestId('identifiant-connexion').fill(prep.compte);
  await page.getByTestId('mot-de-passe-connexion').fill(prep.mot_de_passe);
  await page.getByTestId('action-connexion').click();
  await expect(page).toHaveURL(/\/a-traiter$/);

  return { page, fermer: () => contexte.close() };
}

test.describe.configure({ mode: 'serial', timeout: 90_000 });

test.beforeAll(async () => {
  fs.mkdirSync(path.join(RACINE, 'var', 'oracles', 'ecrans-reels'), { recursive: true });
  const port = await portLibre();
  base = `http://127.0.0.1:${port}`;
  serveur = spawn('php', ['-d', 'opcache.enable=0', '-S', `127.0.0.1:${port}`, '-t', 'public', 'public/index.php'],
    { cwd: RACINE, env: ENV, stdio: 'ignore' });
  await attendreSante(base);
});

test.afterAll(() => {
  serveur?.kill();
});

test.beforeEach(() => {
  prep = preparer();
});

test('Application : chaque valeur vient de la configuration et de l’état réels', async ({ browser }) => {
  const { page, fermer } = await pageConnectee(browser);
  try {
    await page.goto('/application');
    await expect(page.getByTestId('ecran-application')).toBeVisible();

    // Rien de la maquette ne subsiste.
    const corps = await page.locator('body').innerText();
    expect(corps).not.toContain('documents@exemple.ch');
    expect(corps).not.toContain('scanner-accueil');
    expect(corps).not.toContain('il y a 12 s');
    expect(corps).not.toContain('storage/documents');

    // Racine réelle, dossier d'ingestion configuré, adresse réelle (jamais le dossier d'ingestion montré).
    await expect(page.getByTestId('reglage-racine')).toContainText(prep.racine);
    await expect(page.getByTestId('reglage-dossier-ingestion')).toContainText('configuré');
    await expect(page.getByTestId('reglage-adresse-ingestion')).toContainText('ingestion-oracle@mail.test');

    // Dernière tâche d'ingestion RÉELLE et son horodatage (pas un « connecté il y a 12 s » inventé).
    await expect(page.getByTestId('reglage-derniere-tache')).toContainText(prep.tache);
    await expect(page.getByTestId('reglage-derniere-tache')).toContainText(/\d{2}\.\d{2}\.\d{4}/);

    // Modèle IA réel de la configuration.
    await expect(page.getByTestId('reglage-modele-ia')).toContainText(prep.modele);

    // État réel des appairages Jonctions : ClearMyMails relié, K-Time non (chip exact, pas une sous-chaîne).
    await expect(page.getByTestId('etat-cmm').locator('.chip')).toHaveText('relié');
    await expect(page.getByTestId('etat-ktime').locator('.chip')).toHaveText('non relié');
  } finally {
    await fermer();
  }
});

test('À traiter : sans proposition, le type est vide et Valider est refusé', async ({ browser }) => {
  const { page, fermer } = await pageConnectee(browser);
  try {
    await page.goto(`/a-traiter?doc=${prep.ids.sans_proposition}`);
    await expect(page.getByTestId('champ-type')).toHaveValue('');

    // Le badge de source n'apparaît que s'il y a une source réelle : ici, aucune.
    await expect(page.getByTestId('formulaire-validation').locator('.source')).toHaveCount(0);
    await expect(page.getByTestId('raison-controle')).toContainText('aucune proposition');

    // Entrée (validation au clavier) ne range pas un faux type : la pièce reste dans la file.
    await page.keyboard.press('Enter');
    await expect(page.getByTestId(`file-item-${prep.ids.sans_proposition}`)).toBeVisible();
    await expect(page.getByTestId('champ-type')).toHaveValue('');

    // Le refus est aussi posé côté serveur, et il est nommé.
    const csrf = (await page.context().cookies()).find((c) => c.name === 'gedv2_csrf')?.value ?? '';
    const refus = await page.request.post(`/a-traiter/${prep.ids.sans_proposition}/valider`,
      { headers: { 'X-CSRF-Token': csrf }, form: { type: '', titre: 'scans-dossier-103p (p. 1)' }, maxRedirects: 0 });
    expect(refus.status()).toBe(422);
    expect(await refus.text()).toContain('type');
  } finally {
    await fermer();
  }
});

test('À traiter : la proposition réelle est affichée et le compteur du rail est unique', async ({ browser }) => {
  const { page, fermer } = await pageConnectee(browser);
  try {
    await page.goto(`/a-traiter?doc=${prep.ids.avec_proposition}`);
    await expect(page.getByTestId('champ-type')).toHaveValue('Facture');
    await expect(page.getByTestId('formulaire-validation').locator('.source').first()).toContainText('Règle');
    await expect(page.getByTestId('raison-controle')).toContainText('confiance IA insuffisante');
    await expect(page.getByTestId(`file-item-${prep.ids.avec_proposition}`)).toContainText('Facture oracle proposition');

    // Le titre proposé n'est jamais le nom de fichier technique.
    await expect(page.getByTestId('file-a-traiter')).not.toContainText('oracle-ecrans-reels');

    // Un compteur, une source : l'écran À traiter et les autres écrans lisent le même nombre réel.
    await expect(page.getByTestId('bilan-a-controler').locator('.valeur')).toHaveText(String(prep.compteur));
    const compteurs: string[] = [];
    for (const url of ['/a-traiter', '/bibliotheque', '/recherche', '/workflows', '/application']) {
      await page.goto(url);
      compteurs.push(((await page.getByTestId('menu-a-traiter').locator('.compte').textContent()) ?? '').trim());
    }
    expect(new Set(compteurs).size, `compteurs observés : ${compteurs.join(', ')}`).toBe(1);
    expect(compteurs[0]).toBe(String(prep.compteur));
  } finally {
    await fermer();
  }
});

test('Barre latérale : les vues sont les vraies vues enregistrées du fonds', async ({ browser }) => {
  const { page, fermer } = await pageConnectee(browser);
  try {
    await page.goto('/a-traiter');

    const factures = page.getByTestId('menu-vue-factures-a-payer');
    await expect(factures).toHaveAttribute('href', `/bibliotheque?vue=${prep.vues.factures}`);
    await expect(page.getByTestId('menu-vue-contrats-a-echeance'))
      .toHaveAttribute('href', `/bibliotheque?vue=${prep.vues.contrats}`);

    // Aucun lien figé vers une vue inexistante.
    await expect(page.locator('a[href="/bibliotheque?vue=factures"]')).toHaveCount(0);
    await expect(page.locator('a[href="/bibliotheque?vue=contrats"]')).toHaveCount(0);

    // La vue réelle filtre vraiment le fonds.
    await factures.click();
    await expect(page).toHaveURL(new RegExp(`/bibliotheque\\?vue=${prep.vues.factures}$`));
    await expect(page.getByTestId(`ligne-${prep.ids.facture}`)).toBeVisible();
    await expect(page.getByTestId(`ligne-${prep.ids.contrat}`)).toHaveCount(0);
  } finally {
    await fermer();
  }
});

test('Accords : « 1 page », « 2 documents », jamais « 1 pages »', async ({ browser }) => {
  const { page, fermer } = await pageConnectee(browser);
  try {
    await page.goto(`/a-traiter?doc=${prep.ids.sans_proposition}`);
    await expect(page.getByTestId('apercu-pages')).toHaveText('1 page');

    await page.goto(`/a-traiter?doc=${prep.ids.avec_proposition}`);
    await expect(page.getByTestId('apercu-pages')).toHaveText('3 pages');

    const corps = await page.locator('body').innerText();
    expect(corps).not.toMatch(/\b1 pages\b/);
    expect(corps).not.toMatch(/\b1 documents\b/);
  } finally {
    await fermer();
  }
});

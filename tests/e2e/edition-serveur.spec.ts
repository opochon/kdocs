import { test, expect, type Browser, type Page } from '@playwright/test';
import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Oracle E2E du lot ged-edition-serveur (F13, côté serveur) — tout se voit par l'ÉCRAN.
 *
 * Le parcours démarre son propre serveur PHP sur son propre fonds
 * (`var/oracles/edition-serveur/documents`) et son propre espace de secours : la base `gedv2_test`
 * reste partagée, les fichiers non. Deux comptes RÉELS (mot de passe haché, la voie de l'installateur)
 * sur le même dossier : un MANAGER (lire + déposer + modifier) et un LECTEUR (lire seulement).
 *
 * L'écran prouve : le bouton « Ouvrir dans Word/Excel » (`kdocs://ouvrir/<id>`), « ouvert par X sur
 * POSTE depuis HH:MM » dans la fiche et la Bibliothèque après une ouverture, la liste des copies de
 * secours et le bouton « Récupérer » (après confirmation) ; le LECTEUR ne voit ni le bouton ni la
 * récupération. Le geste de l'AGENT (ouverture, dépôt de la copie de secours) est joué par le VRAI
 * HTTP sous jeton d'appareil : aucun écran ne le produit, et les assertions restent sur ce que
 * l'utilisateur VOIT ensuite. Aucun geste n'appelle une API à la place de l'écran.
 */

const RACINE = process.cwd();
const FONDS = path.join(RACINE, 'var', 'oracles', 'edition-serveur', 'documents');
const SECOURS = path.join(RACINE, 'var', 'oracles', 'edition-serveur', 'secours');

type Preparation = {
  manager: string;
  mot_de_passe_manager: string;
  lecteur: string;
  mot_de_passe_lecteur: string;
  docx: number;
  xlsx: number;
  jeton: string;
  poste: string;
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
  throw new Error('le serveur de l’oracle « édition depuis le poste » n’a pas démarré');
}

function preparer(): Preparation {
  const sortie = execFileSync('php', ['tests/e2e/preparer-edition-serveur.php'], {
    cwd: RACINE,
    env: { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: FONDS, SECOURS_ROOT: SECOURS },
  }).toString();

  return JSON.parse(sortie) as Preparation;
}

async function pageConnectee(browser: Browser, identifiant: string, motDePasse: string): Promise<{ page: Page; fermer: () => Promise<void> }> {
  const contexte = await browser.newContext({ baseURL: base, viewport: { width: 1440, height: 900 }, locale: 'fr-CH' });
  const page = await contexte.newPage();
  await page.goto('/connexion', { timeout: 15_000 });
  await page.getByTestId('identifiant-connexion').fill(identifiant, { timeout: 15_000 });
  await page.getByTestId('mot-de-passe-connexion').fill(motDePasse, { timeout: 15_000 });
  await page.getByTestId('action-connexion').click({ timeout: 15_000 });
  await expect(page).toHaveURL(/\/a-traiter$/, { timeout: 15_000 });

  return { page, fermer: () => contexte.close() };
}

function surveillerConsole(page: Page): string[] {
  const erreurs: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') erreurs.push(m.text()); });
  page.on('pageerror', (e) => erreurs.push(e.message));
  return erreurs;
}

/** Ouvre la fiche d'un document depuis la Bibliothèque, comme un utilisateur (ligne puis « Ouvrir »). */
async function ouvrirFicheParEcran(page: Page, id: number): Promise<void> {
  await page.goto('/bibliotheque');
  await expect(page.getByTestId('ecran-bibliotheque')).toBeVisible();
  await expect(page.getByTestId(`ligne-${id}`)).toBeVisible();
  await page.getByTestId(`ligne-${id}`).getByRole('link').first().click();
  await page.getByTestId('ouvrir-fiche').click();
  await expect(page).toHaveURL(new RegExp(`/documents/${id}$`));
  await expect(page.getByTestId('ecran-document')).toBeVisible();
}

test.describe.configure({ mode: 'serial' });

test.beforeAll(async () => {
  fs.mkdirSync(path.dirname(FONDS), { recursive: true });
  const port = await portLibre();
  base = `http://127.0.0.1:${port}`;
  serveur = spawn('php', ['-d', 'opcache.enable=0', '-S', `127.0.0.1:${port}`, '-t', 'public', 'public/index.php'], {
    cwd: RACINE,
    env: { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: FONDS, SECOURS_ROOT: SECOURS },
    stdio: 'ignore',
  });
  await attendreSante(base);
  prep = preparer();
});

test.afterAll(() => {
  serveur?.kill();
});

test('le manager ouvre Word depuis l’écran, la GED le montre « ouvert par … », puis il récupère une copie de secours', async ({ browser }) => {
  test.setTimeout(120_000);
  const { page, fermer } = await pageConnectee(browser, prep.manager, prep.mot_de_passe_manager);
  const erreurs = surveillerConsole(page);
  try {
    // 1. Le bouton d'ouverture est là, il annonce Word pour un .docx, et rien n'est encore ouvert.
    await ouvrirFicheParEcran(page, prep.docx);
    const bouton = page.getByTestId('action-ouvrir-poste');
    await expect(bouton).toBeVisible();
    await expect(bouton).toContainText('Word');
    await expect(bouton).toHaveAttribute('href', `kdocs://ouvrir/${prep.docx}`);
    await expect(page.getByTestId('document-ouverture')).toHaveCount(0);

    // Un .xlsx annonce Excel (le libellé suit le type : Word, Excel, PowerPoint, sinon le poste).
    await page.goto(`/documents/${prep.xlsx}`);
    await expect(page.getByTestId('action-ouvrir-poste')).toContainText('Excel');
    await expect(page.getByTestId('action-ouvrir-poste')).toHaveAttribute('href', `kdocs://ouvrir/${prep.xlsx}`);

    // 2. L'AGENT ouvre le document (vrai HTTP, jeton d'appareil : aucun écran ne produit ce geste).
    const ouverture = await page.request.post(`/api/v1/edition/documents/${prep.docx}/ouverture`, {
      headers: { Authorization: `Bearer ${prep.jeton}` },
    });
    expect(ouverture.status()).toBe(200);

    // 3. La fiche montre « ouvert par … sur POSTE … depuis HH:MM ».
    await page.goto(`/documents/${prep.docx}`);
    const etat = page.getByTestId('document-ouverture');
    await expect(etat).toBeVisible();
    await expect(etat).toContainText(`Ouvert par ${prep.manager} sur ${prep.poste}`);
    await expect(etat).toContainText(/depuis \d{2}:\d{2}/);

    // 4. La Bibliothèque le montre aussi, sans avoir à ouvrir la fiche.
    await page.goto('/bibliotheque');
    const ligne = page.getByTestId(`ouvert-${prep.docx}`);
    await expect(ligne).toBeVisible();
    await expect(ligne).toContainText(`Ouvert par ${prep.manager} sur ${prep.poste}`);

    // 5. L'agent dépose une copie de secours ; rien n'est encore une version.
    const secours = await page.request.post(`/api/v1/edition/documents/${prep.docx}/secours`, {
      headers: { Authorization: `Bearer ${prep.jeton}` },
      multipart: {
        fichier: {
          name: 'copie.docx',
          mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
          buffer: Buffer.from('copie de secours jamais enregistrée — E2E'),
        },
      },
    });
    expect(secours.status()).toBe(200);

    // 6. La fiche, onglet Versions, liste la copie et propose de la récupérer APRÈS confirmation.
    await page.goto(`/documents/${prep.docx}`);
    await page.getByTestId('onglet-versions').click();
    const copie = page.getByTestId('secours-copie');
    await expect(copie).toBeVisible();
    await expect(copie).toContainText(prep.poste);
    const confirmer = page.getByTestId('secours-confirmer');
    await confirmer.check();
    await page.getByTestId('secours-recuperer').click();

    // La récupération crée une NOUVELLE version (le contenu courant est archivé, rien n'est écrasé).
    await expect(page).toHaveURL(new RegExp(`/documents/${prep.docx}\\?secours_succes=\\d+`));
    await expect(page.getByTestId('secours-succes')).toBeVisible();
    await page.getByTestId('onglet-versions').click();
    await expect(page.getByTestId('panneau-versions')).toContainText('v2');

    expect(erreurs, 'aucune erreur console').toEqual([]);
  } finally {
    await fermer();
  }
});

test('le lecteur voit qui a ouvert, mais ni bouton d’ouverture ni récupération', async ({ browser }) => {
  test.setTimeout(90_000);
  const { page, fermer } = await pageConnectee(browser, prep.lecteur, prep.mot_de_passe_lecteur);
  const erreurs = surveillerConsole(page);
  try {
    await ouvrirFicheParEcran(page, prep.docx);

    // Le lecteur VOIT l'ouverture en cours…
    await expect(page.getByTestId('document-ouverture')).toContainText(`Ouvert par ${prep.manager} sur ${prep.poste}`);
    // … mais ne peut ni ouvrir en édition, ni récupérer la copie de secours.
    await expect(page.getByTestId('action-ouvrir-poste')).toHaveCount(0);
    await page.getByTestId('onglet-versions').click();
    await expect(page.getByTestId('secours-copie')).toBeVisible();
    await expect(page.getByTestId('secours-recuperer')).toHaveCount(0);

    expect(erreurs, 'aucune erreur console').toEqual([]);
  } finally {
    await fermer();
  }
});

import { test, expect, type Browser, type Page } from '@playwright/test';
import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Oracle E2E du lot ged-parite-masse — modification en masse par l'écran, et écrans jamais parcourus.
 *
 * Le parcours démarre son propre serveur PHP sur son propre fonds (`var/oracles/parite-masse/documents`) :
 * la base `gedv2_test` reste partagée, le fonds non. Deux comptes RÉELS (mot de passe haché, la voie de
 * l'installateur) : un MANAGER (dossier « Modifiable » en modification, dossier « Lecture » en lecture
 * seule) et un ADMINISTRATEUR (écrans d'administration). Aucune donnée réelle, aucun nom de client.
 *
 * Aucun geste n'appelle une API interne : tout se fait par l'écran (cases, menu, boutons, formulaires).
 */

const RACINE = process.cwd();
const FONDS = path.join(RACINE, 'var', 'oracles', 'parite-masse', 'documents');

type Preparation = {
  manager: string;
  mot_de_passe_manager: string;
  admin: string;
  mot_de_passe_admin: string;
  modifiables: number[];
  lecture: number;
  partage: number;
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
  throw new Error('le serveur de l’oracle « modification en masse » n’a pas démarré');
}

function preparer(): Preparation {
  const sortie = execFileSync('php', ['tests/e2e/preparer-parite-masse.php'], {
    cwd: RACINE,
    env: { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: FONDS },
  }).toString();

  return JSON.parse(sortie) as Preparation;
}

async function pageConnectee(browser: Browser, identifiant: string, motDePasse: string): Promise<{ page: Page; fermer: () => Promise<void> }> {
  const contexte = await browser.newContext({ baseURL: base, viewport: { width: 1440, height: 900 }, locale: 'fr-CH' });
  const page = await contexte.newPage();
  // Budgets d'action explicites, comme `tests/e2e/connexion.ts` : sous la charge du harness complet,
  // le serveur `php -S` mono-processus fait la queue devant la mesure et 5 s ne suffisent pas.
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
  prep = preparer();
});

test.afterAll(() => {
  serveur?.kill();
});

test('« Partagés avec moi » est atteignable par le menu, comme un utilisateur', async ({ browser }) => {
  test.setTimeout(90_000);
  const { page, fermer } = await pageConnectee(browser, prep.manager, prep.mot_de_passe_manager);
  const erreurs = surveillerConsole(page);
  try {
    await expect(page.getByTestId('menu-partages')).toBeVisible();
    await page.getByTestId('menu-partages').click();
    await expect(page).toHaveURL(/\/partages-avec-moi$/);
    await expect(page.getByTestId('partages-avec-moi')).toBeVisible();
    await expect(page.getByTestId(`partage-document-${prep.partage}`)).toBeVisible();
    expect(erreurs, 'aucune erreur console').toEqual([]);
  } finally {
    await fermer();
  }
});

test('la modification en masse annonce, traite, refuse, et la liste puis la fiche suivent', async ({ browser }) => {
  test.setTimeout(90_000);
  const { page, fermer } = await pageConnectee(browser, prep.manager, prep.mot_de_passe_manager);
  const erreurs = surveillerConsole(page);
  const modifiable = prep.modifiables[0];
  try {
    await page.goto('/bibliotheque');
    await expect(page.getByTestId('barre-masse')).toBeVisible();

    // 1. « Toute la page » coche les lignes, puis on affine à deux documents : la confirmation
    //    ANNONCE le nombre de documents visés.
    await page.getByTestId('coche-tout-page').check();
    await expect(page.getByTestId('masse-confirmation')).toContainText('4 documents');
    await page.getByTestId('coche-tout-page').uncheck();
    await page.getByTestId(`coche-doc-${modifiable}`).check();
    await page.getByTestId(`coche-doc-${prep.lecture}`).check();
    await expect(page.getByTestId('masse-confirmation')).toContainText('2 documents');

    // 2. Choisir l'action, puis confirmer.
    await page.getByTestId('masse-action').selectOption('type');
    await page.getByTestId('masse-type').selectOption({ label: 'Contrat' });
    await expect(page.getByTestId('masse-confirmation')).toContainText('Changer le type');
    await page.getByTestId('action-appliquer-masse').click();

    // 3. Le bilan dit combien ont été traités / refusés, et pourquoi.
    await expect(page).toHaveURL(/\/bibliotheque\?masse=\d+/);
    await expect(page.getByTestId('masse-bilan')).toBeVisible();
    await expect(page.getByTestId('masse-bilan-chiffres')).toContainText('Traités : 1');
    await expect(page.getByTestId('masse-bilan-chiffres')).toContainText('Refusés : 1');
    await expect(page.getByTestId('masse-refus')).toContainText('droits insuffisants');

    // 4. La liste montre le type sur la ligne traitée ; celle du document refusé n'a pas bougé.
    await expect(page.getByTestId(`ligne-${modifiable}`)).toContainText('Contrat');
    await expect(page.getByTestId(`ligne-${prep.lecture}`)).toContainText('Courrier');

    // 5. La fiche du document traité suit.
    await page.goto(`/documents/${modifiable}`);
    await expect(page.getByTestId('ecran-document')).toBeVisible();
    await expect(page.locator('body')).toContainText('Contrat');

    expect(erreurs, 'aucune erreur console').toEqual([]);
  } finally {
    await fermer();
  }
});

test('« tout le résultat filtré » sélectionne au-delà de la page affichée', async ({ browser }) => {
  test.setTimeout(90_000);
  const { page, fermer } = await pageConnectee(browser, prep.manager, prep.mot_de_passe_manager);
  try {
    await page.goto('/bibliotheque');
    await page.getByTestId('coche-tout-resultat').check();
    // Le fonds de l'oracle compte les 3 documents modifiables + le document en lecture seule.
    await expect(page.getByTestId('masse-confirmation')).toContainText('4 documents');

    await page.getByTestId('masse-action').selectOption('etiquettes_ajouter');
    await page.getByTestId('masse-etiquettes').fill('Parite-Masse');
    await page.getByTestId('action-appliquer-masse').click();

    await expect(page).toHaveURL(/\/bibliotheque\?masse=\d+/);
    await expect(page.getByTestId('masse-bilan-chiffres')).toContainText('Traités : 3');
    await expect(page.getByTestId('masse-bilan-chiffres')).toContainText('Refusés : 1');
  } finally {
    await fermer();
  }
});

test('les écrans d’administration s’ouvrent par le menu, comme un utilisateur', async ({ browser }) => {
  test.setTimeout(90_000);
  const { page, fermer } = await pageConnectee(browser, prep.admin, prep.mot_de_passe_admin);
  const erreurs = surveillerConsole(page);
  try {
    await expect(page.getByTestId('menu-section-administration')).toBeVisible();

    await page.getByTestId('menu-groupes').click();
    await expect(page).toHaveURL(/\/administration\/groupes$/);
    await expect(page.getByTestId('formulaire-groupe')).toBeVisible();

    await page.getByTestId('menu-roles').click();
    await expect(page).toHaveURL(/\/administration\/roles$/);
    await expect(page.getByTestId('role-administrateur')).toBeVisible();

    await page.getByTestId('menu-metadonnees').click();
    await expect(page).toHaveURL(/\/metadonnees$/);
    await expect(page.getByTestId('ecran-metadonnees')).toBeVisible();

    // Les droits d'un dossier s'ouvrent depuis la Bibliothèque (dossier actif), pas par URL nue.
    await page.goto('/bibliotheque');
    await page.getByTestId('dossier-parite-masse-Modifiable').click();
    await expect(page).toHaveURL(/\/bibliotheque\?dossier=/);
    await expect(page.getByTestId('lien-droits-dossier')).toBeVisible();
    await page.getByTestId('lien-droits-dossier').click();
    await expect(page).toHaveURL(/\/dossiers\/\d+\/droits$/);
    await expect(page.getByTestId('droit-groupe')).toBeVisible();

    expect(erreurs, 'aucune erreur console').toEqual([]);
  } finally {
    await fermer();
  }
});

test('le catalogue des workflows s’ouvre par le menu, comme un utilisateur', async ({ browser }) => {
  test.setTimeout(90_000);
  const { page, fermer } = await pageConnectee(browser, prep.admin, prep.mot_de_passe_admin);
  const erreurs = surveillerConsole(page);
  try {
    await page.getByTestId('menu-workflows').click();
    await expect(page).toHaveURL(/\/workflows$/);
    await page.getByTestId('lien-catalogue').click();
    await expect(page).toHaveURL(/\/workflows\/catalogue$/);
    await expect(page.getByTestId('ecran-workflows-catalogue')).toBeVisible();
    await expect(page.getByTestId('ecran-workflows-catalogue')).toContainText('Déclencheurs');
    expect(erreurs, 'aucune erreur console').toEqual([]);
  } finally {
    await fermer();
  }
});

import { test, expect, type Browser, type Page } from '@playwright/test';
import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Oracle E2E du lot ged-droits-deplacement — F10 par l'écran.
 *
 * Le parcours démarre son propre serveur PHP sur son propre fonds (`var/oracles/droits-deplacement/documents`) :
 * la base `gedv2_test` reste partagée, le fonds non. Un compte MANAGER RÉEL (mot de passe haché) a
 * `modifier` sur deux dossiers « Facture » mais des droits d'ARRIVÉE différents :
 * - « refusé » : RIEN sur le dossier « Contrat » d'arrivée → la modification en masse affiche le refus ;
 * - « accepté » : `deposer` sur le dossier « Contrat » d'arrivée → la modification aboutit.
 *
 * Aucun geste n'appelle une API interne : tout passe par l'écran (cases, menus, boutons, formulaires).
 */

const RACINE = process.cwd();
const FONDS = path.join(RACINE, 'var', 'oracles', 'droits-deplacement', 'documents');

type Preparation = {
  manager: string;
  mot_de_passe: string;
  refus: number;
  accepte: number;
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
  throw new Error('le serveur de l’oracle « droits déplacement » n’a pas démarré');
}

function preparer(): Preparation {
  const sortie = execFileSync('php', ['tests/e2e/preparer-droits-deplacement.php'], {
    cwd: RACINE,
    env: { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: FONDS },
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

/** Saisit sur la barre en masse : un document, l'action « type », la cible « Contrat ». */
async function demanderChangementDeType(page: Page, document: number): Promise<void> {
  await page.goto('/bibliotheque');
  await expect(page.getByTestId('barre-masse')).toBeVisible();
  await page.getByTestId(`coche-doc-${document}`).check();
  await page.getByTestId('masse-action').selectOption('type');
  await page.getByTestId('masse-type').selectOption({ label: 'Contrat' });
  await page.getByTestId('action-appliquer-masse').click();
  await expect(page).toHaveURL(/\/bibliotheque\?masse=\d+/);
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

test('la modification en masse refuse une arrivée sans droit de dépôt et le dit', async ({ browser }) => {
  test.setTimeout(90_000);
  const { page, fermer } = await pageConnectee(browser, prep.manager, prep.mot_de_passe);
  const erreurs: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') erreurs.push(m.text()); });
  page.on('pageerror', (e) => erreurs.push(e.message));
  try {
    await demanderChangementDeType(page, prep.refus);

    await expect(page.getByTestId('masse-bilan')).toBeVisible();
    await expect(page.getByTestId('masse-bilan-chiffres')).toContainText('Traités : 0');
    await expect(page.getByTestId('masse-bilan-chiffres')).toContainText('Refusés : 1');
    await expect(page.getByTestId('masse-refus')).toContainText('droits insuffisants');
    await expect(page.getByTestId('masse-refus')).toContainText('deposer');
    // Le document refusé n'a pas changé de dossier : sa ligne reste en Facture.
    await expect(page.getByTestId(`ligne-${prep.refus}`)).toContainText('Facture');

    expect(erreurs, 'aucune erreur console').toEqual([]);
  } finally {
    await fermer();
  }
});

test('la modification en masse aboutit quand le droit de dépôt existe sur l’arrivée', async ({ browser }) => {
  test.setTimeout(90_000);
  const { page, fermer } = await pageConnectee(browser, prep.manager, prep.mot_de_passe);
  try {
    await demanderChangementDeType(page, prep.accepte);

    await expect(page.getByTestId('masse-bilan-chiffres')).toContainText('Traités : 1');
    await expect(page.getByTestId('masse-bilan-chiffres')).toContainText('Refusés : 0');
    await expect(page.getByTestId(`ligne-${prep.accepte}`)).toContainText('Contrat');
  } finally {
    await fermer();
  }
});

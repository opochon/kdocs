import { test, expect, type Browser, type Page } from '@playwright/test';
import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Oracle R1-droits (défaut 1 et 2) — un compte RÉEL, créé comme en production (jeton de l'installateur :
 * adresse de courriel + mot de passe haché, rôle admin), ouvre l'aperçu et la fiche d'un document
 * réellement INGÉRÉ par le Pilote (lot synthétique → découpe → rangement par le VRAI DepotFichiers).
 *
 * Ce parcours attrape le défaut constaté en recette : les parcours existants se connectent avec le
 * compte SYNTHÉTIQUE (id null) qui contourne les droits par dossier ; lui ne voit rien des documents
 * rangés tant qu'ils n'ont pas leur dossier ACL. Le fonds d'essai vit dans var/oracles/droits/documents
 * et le parcours démarre son propre serveur : la base gedv2_test reste partagée, le fonds non.
 */

const RACINE = process.cwd();
const FONDS = path.join(RACINE, 'var', 'oracles', 'droits', 'documents');

type Preparation = {
  preparation: string;
  compte: string;
  mot_de_passe: string;
  document: number;
  chemin: string;
  dossier_acl: number;
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
  throw new Error('le serveur de l’oracle « Droits réels » n’a pas démarré');
}

function preparer(): Preparation {
  const sortie = execFileSync('php', ['tests/e2e/preparer-droits.php'], {
    cwd: RACINE,
    env: { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: FONDS },
  }).toString();
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

test('un document rangé par le Pilote a un dossier de droits et son aperçu s’ouvre pour un compte réel', async ({ browser }) => {
  test.setTimeout(90_000);   // la préparation attend le verrou de gedv2_test (base partagée entre agents)

  // Sans session, l'aperçu est refusé : la garde ne laisse jamais passer un anonyme.
  const anonyme = await browser.newContext({ baseURL: base });
  const pageAnonyme = await anonyme.newPage();
  const refus = await pageAnonyme.goto(`/documents/${prep.document}/apercu`);
  expect(refus?.status()).toBe(403);
  await expect(pageAnonyme.getByTestId('refus-acces')).toBeVisible();
  await anonyme.close();

  const { page, fermer } = await pageConnectee(browser);
  const erreurs = surveillerConsole(page);
  try {
    // Le vrai compte (id en base) voit le document ingéré, preuve que son dossier ACL existe.
    expect(prep.dossier_acl).toBeGreaterThan(0);
    expect(prep.chemin).toContain('/');

    await page.goto(`/documents/${prep.document}/apercu`);
    await expect(page.getByTestId('ecran-apercu')).toBeVisible();
    await expect(page.getByTestId('visionneuse')).toBeVisible();
    await page.waitForSelector('[data-testid="visionneuse-canvas-1"][data-rendu="1"]', { state: 'visible', timeout: 15_000 });

    // La fiche du même document passe aussi par la garde des droits (le gabarit est encore une
    // maquette, mais la route réelle est refusée à l'anonyme ci-dessus).
    await page.goto(`/documents/${prep.document}`);
    await expect(page.getByTestId('ecran-document')).toBeVisible();

    expect(erreurs, 'aucune erreur console').toEqual([]);
  } finally {
    await fermer();
  }
});

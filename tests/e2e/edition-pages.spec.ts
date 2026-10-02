import { test, expect, type Browser, type Page } from '@playwright/test';
import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import fs from 'node:fs';
import path from 'node:path';
import { connecter } from './connexion';

const RACINE = process.cwd();
const FONDS = path.join(RACINE, 'var', 'oracles', 'edition-pages', 'documents');
let serveur: ChildProcess | undefined;
let base = '';
let preparation: { id: number; lot: number; original: string };

async function portLibre(): Promise<number> {
  return new Promise((resolve, reject) => {
    const socket = createServer(); socket.once('error', reject);
    socket.listen(0, '127.0.0.1', () => { const a = socket.address(); socket.close(() => resolve(typeof a === 'object' && a ? a.port : 0)); });
  });
}
async function attendre(url: string): Promise<void> {
  for (let essai = 0; essai < 100; essai++) { try { if ((await fetch(`${url}/sante`)).ok) return; } catch {} await new Promise(r => setTimeout(r, 100)); }
  throw new Error('serveur V2-12a indisponible');
}
function preparer() {
  return JSON.parse(execFileSync('php', ['tests/e2e/preparer-edition-pages.php'], { cwd: RACINE,
    env: { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: FONDS } }).toString()) as typeof preparation;
}
async function connecterPage(browser: Browser, mobile = false): Promise<{ page: Page; fermer: () => Promise<void> }> {
  const contexte = await browser.newContext({ baseURL: base, viewport: mobile ? { width: 390, height: 844 } : { width: 1440, height: 900 }, locale: 'fr-CH' });
  const page = await contexte.newPage(); page.setDefaultTimeout(90_000); page.setDefaultNavigationTimeout(90_000); await connecter(page); return { page, fermer: () => contexte.close() };
}
async function pageEdition(page: Page, valeur: number) {
  await page.locator('[data-edition-page]').evaluateAll((champs, page) => champs.forEach((c: HTMLInputElement) => { c.value = String(page); }), valeur);
}

// La préparation attend le verrou gedv2_test : le budget couvre ce protocole d'isolement, pas les assertions.
test.describe.configure({ mode: 'serial', timeout: 90_000 });
test.beforeAll(async () => { fs.mkdirSync(path.dirname(FONDS), { recursive: true }); const port = await portLibre(); base = `http://127.0.0.1:${port}`;
  serveur = spawn('php', ['-d', 'opcache.enable=0', '-S', `127.0.0.1:${port}`, '-t', 'public', 'public/index.php'], { cwd: RACINE,
    env: { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: FONDS }, stdio: 'ignore' }); await attendre(base); });
test.afterAll(() => serveur?.kill());
test.beforeEach(() => { preparation = preparer(); });

test('le vrai compte retouche puis valide six pages sans perte ni doublon', async ({ browser }) => {
  const { page, fermer } = await connecterPage(browser);
  try {
    await page.goto(`/a-traiter?doc=${preparation.id}`);
    await expect(page.getByTestId('edition-tourner-90')).toBeVisible();
    await page.getByTestId('edition-tourner-90').click();
    await pageEdition(page, 3); await page.getByTestId('edition-scinder').click();
    await expect(page.getByTestId('edition-fusionner')).toBeEnabled(); await page.getByTestId('edition-fusionner').click();
    await pageEdition(page, 6); await page.getByTestId('edition-retirer').click();
    await page.getByTestId('edition-annuler').click();
    await page.getByTestId('action-valider').click();
    const fin = JSON.parse(execFileSync('php', ['tests/e2e/verifier-edition-pages.php', String(preparation.lot)], { cwd: RACINE,
      env: { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: FONDS } }).toString());
    expect(fin.pages).toEqual([1, 2, 3, 4, 5, 6]); expect(fin.statuts).toEqual(['valide']);
  } finally { await fermer(); }
});

test('téléphone : pivoter et scinder restent des cibles tactiles', async ({ browser }) => {
  const { page, fermer } = await connecterPage(browser, true);
  try { await page.goto(`/a-traiter?doc=${preparation.id}`);
    for (const id of ['edition-tourner-90', 'edition-scinder']) { const boite = await page.getByTestId(id).boundingBox(); expect(boite?.height).toBeGreaterThanOrEqual(44); }
  } finally { await fermer(); }
});

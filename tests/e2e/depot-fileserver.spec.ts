import { test, expect, type Page, type Browser } from '@playwright/test';
import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Parcours « Dépôt fichier » (famille archivage / versions, lot ged-mfiles-oracle-1).
 *
 * L'écran agit comme un vrai utilisateur : on clique les entrées de menu et les boutons réels, on lit
 * ce qui est AFFICHÉ. Le fonds est synthétique (var/oracles/depot-fileserver) et le serveur est isolé ;
 * aucune API interne n'est appelée à la place de l'écran. Un compte lecteur prouve le refus.
 */
const RACINE = process.cwd();
const FONDS = path.join(RACINE, 'var', 'oracles', 'depot-fileserver', 'documents');
const GARANTIE = path.join(RACINE, 'var', 'oracles', 'depot-fileserver', 'garantie');
const EXPORT = path.join(RACINE, 'var', 'oracles', 'depot-fileserver', 'export');
const ENV = { DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: FONDS, DEPOT_SEPARE: GARANTIE, DEPOT_EXPORT: EXPORT };

type Preparation = { fonds: string; fichier: string; admin: string; lecteur: string; mot_de_passe: string };
let serveur: ChildProcess | undefined;
let base = '';
let prep: Preparation;

async function portLibre(): Promise<number> {
  return new Promise((ok, ko) => { const s = createServer(); s.once('error', ko); s.listen(0, '127.0.0.1', () => { const a = s.address(); const p = typeof a === 'object' && a ? a.port : 0; s.close(() => ok(p)); }); });
}
async function attente(url: string): Promise<void> {
  for (let i = 0; i < 100; i++) { try { if ((await fetch(`${url}/sante`)).ok) return; } catch {} await new Promise((r) => setTimeout(r, 100)); }
  throw new Error('serveur dépôt fichier indisponible');
}
function preparer(): Preparation {
  return JSON.parse(execFileSync('php', ['tests/e2e/preparer-depot-fileserver.php'], { cwd: RACINE, env: { ...process.env, ...ENV } }).toString()) as Preparation;
}
async function connecter(page: Page, identifiant: string, motDePasse: string): Promise<void> {
  await page.goto('/connexion');
  await page.getByTestId('identifiant-connexion').fill(identifiant);
  await page.getByTestId('mot-de-passe-connexion').fill(motDePasse);
  await page.getByTestId('action-connexion').click({ timeout: 15_000 });
  await expect(page).toHaveURL(/\/a-traiter$/, { timeout: 15_000 });
}

test.describe.configure({ mode: 'serial' });
test.beforeAll(async () => {
  const port = await portLibre();
  base = `http://127.0.0.1:${port}`;
  serveur = spawn('php', ['-d', 'opcache.enable=0', '-S', `127.0.0.1:${port}`, '-t', 'public', 'public/index.php'], { cwd: RACINE, env: { ...process.env, ...ENV }, stdio: 'ignore' });
  await attente(base);
  prep = preparer();
});
test.afterAll(() => serveur?.kill());

async function ouvrirEcran(page: Page): Promise<void> {
  await page.getByTestId('menu-depot-fileserver').click();
  await expect(page).toHaveURL(/\/depot-fileserver$/, { timeout: 15_000 });
  await expect(page.getByTestId('ecran-depot-fileserver')).toBeVisible();
}

test('l’administrateur ouvre l’écran par le menu, scanne le dépôt et voit le manifeste', async ({ browser }: { browser: Browser }) => {
  const contexte = await browser.newContext({ baseURL: base, viewport: { width: 1440, height: 900 }, locale: 'fr-CH' });
  const page = await contexte.newPage();
  try {
    await connecter(page, prep.admin, prep.mot_de_passe);
    await ouvrirEcran(page);
    await expect(page.getByTestId('depot-fileserver-journal-vide')).toBeVisible();
    await page.getByTestId('depot-fileserver-scanner').click();
    await expect(page.getByTestId('depot-fileserver-message')).toContainText('Scan terminé');
    await expect(page.getByTestId('depot-fileserver-message')).toContainText('2 fichier(s)');
    await expect(page.getByTestId('depot-fileserver-presents')).toContainText('2 présent');
  } finally { await contexte.close(); }
});

test('une modification faite hors GED est détectée, la garantie est vérifiée, l’arborescence est exportée', async ({ browser }: { browser: Browser }) => {
  const contexte = await browser.newContext({ baseURL: base, viewport: { width: 1440, height: 900 }, locale: 'fr-CH' });
  const page = await contexte.newPage();
  try {
    await connecter(page, prep.admin, prep.mot_de_passe);
    fs.appendFileSync(path.join(FONDS, prep.fichier), 'octet ajouté hors GED\n');
    await ouvrirEcran(page);
    await page.getByTestId('depot-fileserver-scanner').click();
    await expect(page.getByTestId('depot-fileserver-message')).toContainText('1 modifié(s) hors GED');
    await expect(page.getByTestId('depot-fileserver-ecart-modifie').first()).toContainText('Modifié hors GED');

    await page.getByTestId('depot-fileserver-verifier').click();
    await expect(page.getByTestId('depot-fileserver-message')).toContainText('Garantie vérifiée');

    await page.getByTestId('depot-fileserver-exporter').click();
    await expect(page.getByTestId('depot-fileserver-message')).toContainText('Arborescence classique exportée');
    expect(fs.existsSync(path.join(FONDS, '.depot-fileserver'))).toBe(true);
    expect(fs.existsSync(path.join(EXPORT, '.depot-fileserver'))).toBe(false);
  } finally { await contexte.close(); }
});

test('un compte réel sans droit ne voit pas l’entrée et la route est refusée côté serveur', async ({ browser }: { browser: Browser }) => {
  const contexte = await browser.newContext({ baseURL: base });
  const page = await contexte.newPage();
  try {
    await connecter(page, prep.lecteur, prep.mot_de_passe);
    await page.goto('/bibliotheque');
    await expect(page.getByTestId('menu-depot-fileserver')).toHaveCount(0);
    const refus = await page.goto('/depot-fileserver');
    expect(refus?.status()).toBe(403);
  } finally { await contexte.close(); }
});

test('iPhone 13 : cibles tactiles et aucun débordement horizontal', async ({ browser }: { browser: Browser }) => {
  const contexte = await browser.newContext({ baseURL: base, viewport: { width: 390, height: 844 }, isMobile: true, locale: 'fr-CH' });
  const page = await contexte.newPage();
  try {
    await connecter(page, prep.admin, prep.mot_de_passe);
    // Sur téléphone, le rail est remplacé par les onglets du bas : on ouvre « Plus » puis l'entrée.
    await page.getByTestId('onglet-plus').click();
    await page.getByTestId('onglet-depot-fileserver').click();
    await expect(page).toHaveURL(/\/depot-fileserver$/, { timeout: 15_000 });
    await expect(page.getByTestId('ecran-depot-fileserver')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    const boite = await page.getByTestId('depot-fileserver-scanner').boundingBox();
    expect(boite?.height ?? 0).toBeGreaterThanOrEqual(44);
  } finally { await contexte.close(); }
});

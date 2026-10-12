import { test, expect, type Browser, type Page } from '@playwright/test';
import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import path from 'node:path';

/**
 * Lot ged-parite-modele-2 : l'administration du courrier vue par l'ÉCRAN.
 *
 * Un administrateur ouvre Application → Courrier, crée un compte (le mot de passe affiché est le masque,
 * jamais le clair), TESTE la connexion sur le faux serveur en mémoire, crée une règle de tri (objet
 * « Invoice » → déplacer dans « Factures »), traite la boîte, lit le journal des courriels traités, et
 * constate qu'un second passage ne retraite rien. On agit par des clics et on assène sur ce que l'utilisateur
 * VOIT (data-testid), jamais sur une API interne.
 */

const RACINE = process.cwd();
type Prep = { compte: string; mot_de_passe: string };

async function portLibre(): Promise<number> {
  return new Promise((ok, ko) => {
    const sonde = createServer();
    sonde.once('error', ko);
    sonde.listen(0, '127.0.0.1', () => {
      const adresse = sonde.address();
      sonde.close(() => ok(typeof adresse === 'object' && adresse !== null ? adresse.port : 0));
    });
  });
}

async function attente(base: string): Promise<void> {
  for (let i = 0; i < 100; i++) {
    try {
      if ((await fetch(`${base}/sante`)).ok) return;
    } catch {
      // le serveur PHP est encore en amorçage
    }
    await new Promise((r) => setTimeout(r, 100));
  }
  throw new Error('serveur courrier indisponible');
}

async function connecter(page: Page, prep: Prep): Promise<void> {
  await page.goto('/connexion');
  await page.getByTestId('identifiant-connexion').fill(prep.compte);
  await page.getByTestId('mot-de-passe-connexion').fill(prep.mot_de_passe);
  await page.getByTestId('action-connexion').click({ timeout: 15_000 });
  await expect(page).toHaveURL(/\/a-traiter$/, { timeout: 15_000 });
}

async function ouvrirCourrier(page: Page): Promise<void> {
  await page.getByTestId('menu-application').click({ timeout: 20_000 });
  await expect(page.getByTestId('ecran-application')).toBeVisible();
  await page.getByTestId('lien-courrier').click({ timeout: 20_000 });
  await expect(page.getByTestId('ecran-courrier')).toBeVisible();
}

async function jouer(browser: Browser, corps: (page: Page, prep: Prep) => Promise<void>): Promise<void> {
  const donnees = path.join(RACINE, 'var', 'oracles', 'courrier', 'donnees');
  const fonds = path.join(RACINE, 'var', 'oracles', 'courrier', 'documents');
  const env = {
    ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test',
    STORAGE_ROOT: fonds, COURRIER_DONNEES: donnees,
  };
  const prep = JSON.parse(execFileSync('php', ['tests/e2e/preparer-courrier.php'], { cwd: RACINE, env }).toString()) as Prep;

  const port = await portLibre();
  const base = `http://127.0.0.1:${port}`;
  const serveur: ChildProcess = spawn('php', ['-d', 'opcache.enable=0', '-S', `127.0.0.1:${port}`, '-t', 'public', 'public/index.php'],
    { cwd: RACINE, env, stdio: 'ignore' });

  try {
    await attente(base);
    const contexte = await browser.newContext({ baseURL: base, viewport: { width: 1440, height: 900 }, locale: 'fr-CH' });
    const page = await contexte.newPage();
    try {
      await connecter(page, prep);
      await corps(page, prep);
    } finally {
      await contexte.close();
    }
  } finally {
    serveur.kill();
  }
}

test.describe.configure({ mode: 'serial', timeout: 60_000 });

test('Administration du courrier : compte, test, règle, traitement et journal', async ({ browser }) => {
  await jouer(browser, async (page) => {
    await ouvrirCourrier(page);
    await expect(page.getByTestId('courrier-faux')).toBeVisible();

    // Création du compte : le mot de passe est chiffré au repos et masqué à l'écran.
    const clair = 'MotDePasse-Imap-2026!';
    await page.getByTestId('compte-nom').fill('Boîte E2E');
    await page.getByTestId('compte-serveur').fill('imap.faux.test');
    await page.getByTestId('compte-port').fill('993');
    await page.getByTestId('compte-utilisateur').fill('boite-e2e@exemple.test');
    await page.getByTestId('compte-mot-de-passe').fill(clair);
    await page.getByTestId('action-creer-compte').click({ timeout: 20_000 });
    await expect(page.getByTestId('compte-1')).toBeVisible();
    expect(await page.content()).not.toContain(clair);

    // Test de connexion sur le faux serveur en mémoire.
    await page.getByTestId('tester-compte-1').click({ timeout: 20_000 });
    await expect(page.getByTestId('courrier-essai-ok')).toBeVisible();

    // Règle : objet « Invoice » → déplacer les pièces .pdf et ranger le courriel dans « Factures ».
    await page.getByTestId('regle-nom').fill('Factures E2E');
    await page.getByTestId('regle-compte').selectOption('Boîte E2E');
    await page.getByTestId('regle-filtre-objet').fill('Invoice');
    await page.getByTestId('regle-filtre-fichiers').fill('*.pdf');
    await page.getByTestId('regle-action').selectOption('2');
    await page.getByTestId('regle-parametre-action').fill('Factures');
    await page.getByTestId('action-creer-regle').click({ timeout: 20_000 });
    await expect(page.getByTestId('regle-1')).toBeVisible();

    // Traitement : une facture retenue, une publicité ignorée, le journal la montre.
    await page.getByTestId('traiter-compte-1').click({ timeout: 30_000 });
    await expect(page.getByTestId('courrier-rapport-traites')).toContainText('1 courriel(s) traité(s)');
    await expect(page.getByTestId('table-journal')).toContainText('Invoice E2E');
    await expect(page.locator('[data-testid^="journal-statut-"]').first()).toHaveText('SUCCESS');

    // Second passage : plus rien à traiter (le journal évite tout double traitement).
    await page.getByTestId('traiter-compte-1').click({ timeout: 30_000 });
    await expect(page.getByTestId('courrier-rapport-traites')).toContainText('0 courriel(s) traité(s)');
  });
});

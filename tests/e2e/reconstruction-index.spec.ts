import { test, expect, type Browser, type Page } from '@playwright/test';
import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import path from 'node:path';

/**
 * Lot ged-socle-reconstruction, geste E2E : reconstruire l'index depuis l'écran Application.
 *
 * Le fonds de préparation compte 27 documents dont UN absent du disque : le total relisible est donc
 * 26, et le document absent est compté à part et NOMMÉ. L'utilisateur clique la reconstruction et les
 * tranches comme n'importe qui ; la barre doit atteindre 100 % (26 / 26) sans tranche fantôme.
 */

const RACINE = process.cwd();
type Prep = { compte: string; mot_de_passe: string; total: number; absents: number; nom_absent: string };

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
  throw new Error('serveur reconstruction-index indisponible');
}

async function connecter(page: Page, prep: Prep): Promise<void> {
  await page.goto('/connexion');
  await page.getByTestId('identifiant-connexion').fill(prep.compte);
  await page.getByTestId('mot-de-passe-connexion').fill(prep.mot_de_passe);
  await page.getByTestId('action-connexion').click();
  await expect(page).toHaveURL(/\/a-traiter$/);
}

test.describe.configure({ mode: 'serial' });

test('Application : la reconstruction va à 100 % et le bilan nomme les absents', async ({ browser }: { browser: Browser }) => {
  const fonds = path.join(RACINE, 'var', 'oracles', 'reconstruction-index', 'documents');
  const audit = path.join(RACINE, 'var', 'oracles', 'reconstruction-index', 'audit');
  const env = { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: fonds, SECURITE_DOSSIER: audit };
  const prep = JSON.parse(execFileSync('php', ['tests/e2e/preparer-reconstruction-index.php'], { cwd: RACINE, env }).toString()) as Prep;

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

      // Comme un vrai utilisateur : par le menu visible, jamais par URL directe.
      await page.getByTestId('menu-application').click();
      await expect(page.getByTestId('ecran-application')).toBeVisible();

      await page.getByTestId('reconstruction-lancer').click();
      await expect(page.getByTestId('reconstruction-progression')).toHaveText(`0 / ${prep.total}`);
      // L'absent est nommé dès le lancement, jamais noyé dans le total.
      await expect(page.getByTestId('reconstruction-absents')).toContainText(prep.nom_absent);

      // Les tranches, jusqu'à la fin ; aucune tranche fantôme ne doit rester après.
      for (let tranche = 0; tranche < 5; tranche++) {
        if (await page.getByTestId('reconstruction-terminee').isVisible().catch(() => false)) break;
        await page.getByTestId('reconstruction-tranche').click();
        await page.waitForLoadState('load');
      }

      await expect(page.getByTestId('reconstruction-terminee')).toBeVisible();
      await expect(page.getByTestId('reconstruction-progression')).toHaveText(`${prep.total} / ${prep.total}`);
      await expect(page.getByTestId('reconstruction-pourcentage')).toHaveText('100 %');
      const barre = page.getByTestId('reconstruction-barre');
      await expect(barre).toHaveAttribute('value', String(prep.total));
      await expect(barre).toHaveAttribute('max', String(prep.total));
      await expect(page.getByTestId('reconstruction-tranche')).toHaveCount(0);
      await expect(page.getByTestId('reconstruction-absents')).toContainText(prep.nom_absent);
    } finally {
      await contexte.close();
    }
  } finally {
    serveur.kill();
  }
});

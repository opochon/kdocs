import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { connecter } from './connexion';

/**
 * Budgets de temps de l'aperçu (lot PERF-2). Ils vivent dans leur PROPRE projet/serveur Playwright parce que
 * `php -S` est mono-processus : un worker parallèle qui tient une requête lente fait la queue devant la mesure
 * et la fausse (1 548 ms mesurés alors que la page tient une cinquantaine de millisecondes de serveur au repos).
 * La mesure reste le vrai parcours d'écran (serveur réel, pdf.js réel, cache chaud) : c'est la CONCURRENCE des
 * autres parcours qui est retirée, pas la difficulté de la mesure.
 */
test.beforeAll(() => {
  execFileSync('php', ['tests/e2e/preparer-recherche.php'], {
    cwd: process.cwd(),
    env: { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: 'tests/corpus/apercu/documents' },
  });
});
test.beforeEach(async ({ page }) => { await connecter(page); });

test('banc : les budgets tournent sur un serveur dédié, hors du pool des parcours', async ({}, testInfo) => {
  // Garde-fou du banc : sans ce projet dédié, la mesure repartage la file du serveur mono-processus et redevient
  // un tirage au sort. Ce test échoue si le projet « budgets » disparaît ou si on le remet sur le serveur partagé.
  // (`workers: 1`, qui empêche aussi les budgets de se gêner entre eux, vit dans playwright.config.ts : Playwright
  // n'expose pas cette limite au worker, on vérifie donc ici ce qui est observable depuis le test.)
  expect(testInfo.project.name, 'la mesure de budget vit dans le projet « budgets »').toBe('budgets');

  const parcours = testInfo.config.projects.find((p) => p.name === 'parcours');
  expect(parcours, 'le pool des parcours existe : c’est lui qu’on isole').toBeTruthy();
  expect(testInfo.project.use.baseURL, 'serveur dédié, jamais la file du serveur partagé des parcours')
    .not.toBe(parcours?.use.baseURL);
});

test('aperçu PDF : 3 pages affichées, première page en moins d’une seconde, 0 erreur', async ({ page }) => {
  const erreurs: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') erreurs.push(m.text()); });
  page.on('pageerror', (e) => erreurs.push(e.message));

  // Deux mesures distinctes : ouverture à froid (pdf.js pas encore en cache) < 2 s, puis ouverture courante < 1 s.
  const froid = Date.now();
  await page.goto('/documents/204/apercu');
  await page.waitForSelector('[data-testid="visionneuse-canvas-1"][data-rendu="1"]', { state: 'visible', timeout: 8_000 });
  const msFroid = Date.now() - froid;

  const courant = Date.now();
  await page.goto('/documents/204/apercu');
  await page.waitForSelector('[data-testid="visionneuse-canvas-1"][data-rendu="1"]', { state: 'visible', timeout: 8_000 });
  const msCourant = Date.now() - courant;

  // Les mesures restent lisibles dans le rapport : une mesure muette ne prouve rien.
  test.info().annotations.push({
    type: 'budget',
    description: `aperçu première page : à froid ${msFroid} ms (< 2 000), courante ${msCourant} ms (< 1 000)`,
  });

  expect(msFroid, 'première page du PDF, à froid, en moins de 2 s').toBeLessThan(2000);
  expect(msCourant, 'première page du PDF affichée en moins de 1 s').toBeLessThan(1000);

  await page.waitForSelector('[data-testid="visionneuse-canvas-3"][data-rendu="1"]', { state: 'visible', timeout: 8_000 });
  await expect(page.getByTestId('visionneuse-page')).toContainText('page 1');

  expect(erreurs, 'aucune erreur console').toEqual([]);
});

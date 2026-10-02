import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { connecter } from './connexion';

/**
 * Budget de temps de l'écran /recherche (lot PERF-2). Comme l'aperçu, il vit dans le projet « budgets » :
 * `php -S` est mono-processus, donc mesuré dans la file du pool parallèle il tombait au hasard selon le
 * worker qui tenait le serveur au même moment. L'index plein texte reste bâti HORS requête (PERF-1).
 */
test.beforeAll(() => {
  execFileSync('php', ['tests/e2e/preparer-recherche.php'], {
    cwd: process.cwd(),
    env: { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: 'tests/corpus/apercu/documents' },
  });
});

test.beforeEach(async ({ page }) => { await connecter(page); });

test('recherche : l’écran répond en moins d’une seconde', async ({ page }) => {
  const debut = Date.now();
  await page.goto('/recherche?q=maintenance');
  await expect(page.getByTestId('resultat-204')).toBeVisible();
  const mesure = Date.now() - debut;

  // Les mesures restent lisibles dans le rapport : une mesure muette ne prouve rien.
  test.info().annotations.push({ type: 'budget', description: `écran /recherche : ${mesure} ms (< 1 000)` });

  expect(mesure, 'écran de recherche sous 1 s').toBeLessThan(1000);
});

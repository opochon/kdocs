import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { connecter } from './connexion';

/**
 * Porte F5 du lot V2-05b — l'écran /recherche est branché sur les VRAIS documents du fonds (corpus
 * synthétique d'aperçu, STORAGE_ROOT des tests) : le terme est surligné, les filtres restreignent la
 * liste, une absence se dit, jamais une liste vide muette. Aucune donnée réelle.
 *
 * PERF-1 : l'index plein texte est bâti HORS requête par le job `tools/indexer-recherche.php` (comme le
 * planificateur en production) ; l'écran ne le synchronise plus jamais lui-même.
 */
test.beforeAll(() => {
  execFileSync('php', ['tools/indexer-recherche.php', '--tout'], {
    cwd: process.cwd(),
    env: { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: 'tests/corpus/apercu/documents' },
  });
});

test.beforeEach(async ({ page }) => { await connecter(page); });

function surveillerConsole(page: Page): string[] {
  const erreurs: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') erreurs.push(m.text()); });
  page.on('pageerror', (e) => erreurs.push(e.message));
  return erreurs;
}

test('recherche : les documents du fonds remontent, terme surligné, 0 erreur', async ({ page }) => {
  const erreurs = surveillerConsole(page);
  const rep = await page.goto('/recherche?q=maintenance');

  expect(rep?.status()).toBe(200);
  await expect(page.getByTestId('resultat-204')).toBeVisible();
  await expect(page.getByTestId('resultat-204').locator('mark').first()).toHaveText(/maintenance/i);
  await expect(page.getByTestId('recherche-compte')).toContainText('résultat');
  expect(erreurs, 'aucune erreur console').toEqual([]);
});

test('recherche : le filtre type restreint la liste', async ({ page }) => {
  await page.goto('/recherche?q=facture&type=Facture');

  await expect(page.getByTestId('resultat-201')).toBeVisible();
  await expect(page.getByTestId('resultat-204')).toHaveCount(0);
});

test('recherche : une absence se nomme, jamais un écran muet', async ({ page }) => {
  await page.goto('/recherche?q=zzzintrouvable');

  await expect(page.getByTestId('recherche-vide')).toBeVisible();
  await expect(page.getByTestId('recherche-erreur')).toHaveCount(0);
});

// Le budget « écran < 1 s » vit dans `budget-recherche.spec.ts` (projet « budgets », serveur dédié) :
// mesuré dans la file du serveur mono-processus partagé par les workers, il tombait au hasard (PERF-2).

import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { connecter } from './connexion';

/**
 * Lot ged-parite-ecrans-1 : le TABLEAU DE BORD (modèle Paperless `DashboardComponent`).
 *
 * Un utilisateur ouvre le menu, clique « Tableau de bord » et voit ce qu'il doit traiter : documents à
 * classer, traitements en échec, courriers récents et vues épinglées. Tout passe par l'écran (menu,
 * liens) : aucun appel d'API interne. Base gedv2_test, aucune donnée réelle.
 */

const RACINE = process.cwd();
const ENV = { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: 'tests/corpus/apercu/documents' };

function surveillerConsole(page: Page): string[] {
  const erreurs: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') erreurs.push(m.text()); });
  page.on('pageerror', (e) => erreurs.push(e.message));
  return erreurs;
}

test.beforeAll(() => {
  execFileSync('php', ['tests/e2e/preparer-ecrans.php'], { cwd: RACINE, env: ENV });
});

test.afterAll(() => {
  execFileSync('php', ['tests/e2e/nettoyer-ecrans.php'], { cwd: RACINE, env: ENV });
});

test('un utilisateur voit ce qu il doit traiter', async ({ page }) => {
  test.setTimeout(60_000);
  const erreurs = surveillerConsole(page);

  await connecter(page);

  await page.getByTestId('menu-tableau-de-bord').click({ timeout: 20_000 });
  await expect(page).toHaveURL(/\/tableau-de-bord$/);
  await expect(page.getByTestId('ecran-tableau-de-bord')).toBeVisible();

  // Documents à classer : le compte est réel, non nul, et la pièce déposée est visible.
  await expect(page.getByTestId('tb-a-classer-total')).not.toHaveText('0');
  await expect(page.getByTestId('tb-a-classer-liste')).toContainText('E2E document à classer');

  // Traitements en échec et courriers récents.
  await expect(page.getByTestId('tb-taches-liste')).toContainText('e2e-ecrans-');
  await expect(page.getByTestId('tb-courriers-liste')).toContainText('E2E courrier synthétique');

  // Vues épinglées : la vue enregistrée au tableau de bord est là, et son lien mène à la bibliothèque.
  await expect(page.getByTestId('tb-vues-liste')).toContainText('E2E vue épinglée');
  const lienVue = page.locator('[data-testid="tb-vues-liste"] a').first();
  await expect(lienVue).toHaveAttribute('href', /\/bibliotheque\?vue=\d+/);

  // Le bloc « à traiter » mène au bon écran.
  await page.getByTestId('tb-tout-a-traiter').click({ timeout: 20_000 });
  await expect(page).toHaveURL(/\/a-traiter$/);

  expect(erreurs).toEqual([]);
});

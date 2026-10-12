import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { connecter } from './connexion';

/**
 * Lot ged-parite-ecrans-1 : les JOURNAUX (modèle Paperless `LogsComponent`, réservé aux administrateurs).
 *
 * Un administrateur ouvre le menu Administration puis « Journaux », voit le journal d'audit, filtre par
 * action et borne le nombre de lignes. Tout passe par l'écran ; aucune écriture (le journal est en ajout
 * seul côté serveur). Base gedv2_test, aucune donnée réelle.
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

test('un administrateur consulte les journaux', async ({ page }) => {
  test.setTimeout(60_000);
  const erreurs = surveillerConsole(page);

  await connecter(page);

  await page.getByTestId('menu-journaux').click({ timeout: 20_000 });
  await expect(page).toHaveURL(/\/journaux$/);
  await expect(page.getByTestId('ecran-journaux')).toBeVisible();

  // Le filtre par action est proposé et la liste suit : on choisit l'action posée par la préparation.
  await page.getByTestId('journaux-action').selectOption('e2e-journal');
  await page.getByTestId('journaux-appliquer').click({ timeout: 20_000 });
  await expect(page).toHaveURL(/action=e2e-journal/);
  await expect(page.getByTestId('journaux-liste')).toContainText('e2e-journal');

  // La borne de lignes est un vrai réglage d'écran.
  await page.getByTestId('journaux-lignes').fill('10');
  await page.getByTestId('journaux-appliquer').click({ timeout: 20_000 });
  await expect(page).toHaveURL(/lignes=10/);
  await expect(page.getByTestId('ecran-journaux')).toBeVisible();

  expect(erreurs).toEqual([]);
});

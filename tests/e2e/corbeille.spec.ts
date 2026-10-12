import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { connecter } from './connexion';

/**
 * Lot ged-parite-ecrans-1 : la CORBEILLE (modèle Paperless `TrashComponent`).
 *
 * Un administrateur ouvre le menu Administration puis « Corbeille », voit la pièce mise de côté et la
 * RESTAURE. GEDv2 ne supprime jamais de donnée utilisateur : la suppression définitive n'existe pas et
 * l'écran le dit (le dossier fait foi). Tout passe par l'écran. Base gedv2_test, aucune donnée réelle.
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

test('un administrateur restaure un document de la corbeille', async ({ page }) => {
  test.setTimeout(60_000);
  const erreurs = surveillerConsole(page);

  await connecter(page);

  await page.getByTestId('menu-corbeille').click({ timeout: 20_000 });
  await expect(page).toHaveURL(/\/corbeille$/);
  await expect(page.getByTestId('ecran-corbeille')).toBeVisible();

  // La pièce mise à la corbeille est visible, avec sa ligne de restauration.
  const ligne = page.locator('[data-testid^="corbeille-document-"]', { hasText: 'E2E document corbeille' });
  await expect(ligne).toBeVisible();

  // Le produit ne supprime jamais : la suppression définitive est annoncée indisponible, pas cachée.
  await expect(page.getByTestId('corbeille-suppression-desactivee')).toBeDisabled();
  await expect(page.getByTestId('corbeille-regle')).toContainText('ne supprime jamais');

  // Restauration par le bouton de la ligne.
  await ligne.getByTestId(/action-restaurer-\d+/).click({ timeout: 20_000 });
  await expect(page).toHaveURL(/\/corbeille$/);
  await expect(page.getByTestId('corbeille-liste')).not.toContainText('E2E document corbeille');

  expect(erreurs).toEqual([]);
});

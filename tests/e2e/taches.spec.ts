import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { connecter } from './connexion';

/**
 * Lot ged-parite-modele-3 : le SUIVI DES TRAITEMENTS vu par l'écran.
 *
 * Un administrateur ouvre Application → Suivi des traitements, voit une tâche de consommation (statut,
 * horodatage, fichier) et l'acquitte. Tout passe par l'écran (menu, lien, bouton) : aucun appel d'API
 * interne. Base gedv2_test, aucune donnée réelle.
 */

const RACINE = process.cwd();

function surveillerConsole(page: Page): string[] {
  const erreurs: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') erreurs.push(m.text()); });
  page.on('pageerror', (e) => erreurs.push(e.message));
  return erreurs;
}

test.beforeAll(() => {
  execFileSync('php', ['tests/e2e/preparer-taches.php'], {
    cwd: RACINE,
    env: { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: 'tests/corpus/apercu/documents' },
  });
});

test('un administrateur suit et acquitte un traitement', async ({ page }) => {
  test.setTimeout(60_000);
  const erreurs = surveillerConsole(page);

  await connecter(page);

  await page.getByTestId('menu-application').click({ timeout: 20_000 });
  await expect(page.getByTestId('ecran-application')).toBeVisible();
  await page.getByTestId('lien-taches').click({ timeout: 20_000 });
  await expect(page).toHaveURL(/\/taches$/);
  await expect(page.getByTestId('ecran-taches')).toBeVisible();

  // La tâche synthétique est visible : fichier, tâche, statut.
  await expect(page.getByTestId('taches-liste')).toContainText('e2e-facture.pdf');
  await expect(page.getByTestId('taches-liste')).toContainText('CONSUME_FILE');
  await expect(page.getByTestId('taches-liste')).toContainText('PENDING');

  // L'utilisateur acquitte la tâche par le bouton de sa ligne.
  await page.locator('[data-testid^="action-accuser-"]').first().click({ timeout: 20_000 });
  await expect(page).toHaveURL(/\/taches$/);
  await expect(page.getByTestId('taches-liste')).toContainText('acquittée');

  expect(erreurs).toEqual([]);
});

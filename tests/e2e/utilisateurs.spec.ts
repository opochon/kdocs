import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { connecter } from './connexion';

/**
 * Lot ged-parite-ecrans-1 : les UTILISATEURS ET GROUPES (modèle Paperless `UsersAndGroupsComponent`).
 *
 * Un administrateur ouvre le menu Administration puis « Comptes » et « Groupes » : il voit les comptes
 * réels, crée un groupe et le voit dans la liste. Tout passe par l'écran. Le groupe créé est synthétique
 * et retiré par le nettoyage. Base gedv2_test, aucune donnée réelle.
 */

const RACINE = process.cwd();
const ENV = { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: 'tests/corpus/apercu/documents' };
const NOM_GROUPE = 'E2E groupe parcours';

function surveillerConsole(page: Page): string[] {
  const erreurs: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') erreurs.push(m.text()); });
  page.on('pageerror', (e) => erreurs.push(e.message));
  return erreurs;
}

// `nettoyer-ecrans.php` retire les groupes synthétiques « E2E groupe… » même sans état de fonds.
test.afterAll(() => {
  execFileSync('php', ['tests/e2e/nettoyer-ecrans.php'], { cwd: RACINE, env: ENV });
});

test('un administrateur gere les comptes et les groupes', async ({ page }) => {
  test.setTimeout(60_000);
  const erreurs = surveillerConsole(page);

  await connecter(page);

  // Comptes : la liste réelle est visible.
  await page.getByTestId('menu-comptes').click({ timeout: 20_000 });
  await expect(page).toHaveURL(/\/administration\/comptes$/);
  await expect(page.getByTestId('formulaire-compte')).toBeVisible();
  await expect(page.locator('body')).toContainText('testeur@gedv2.test');

  // Groupes : l'administrateur crée un groupe et le voit dans la liste.
  await page.getByTestId('menu-groupes').click({ timeout: 20_000 });
  await expect(page).toHaveURL(/\/administration\/groupes$/);
  await page.getByTestId('groupe-nom').fill(NOM_GROUPE);
  await page.getByTestId('groupe-role').selectOption('utilisateur');
  await page.getByTestId('action-creer-groupe').click({ timeout: 20_000 });
  await expect(page).toHaveURL(/\/administration\/groupes$/);
  await expect(page.locator('body')).toContainText(NOM_GROUPE);

  expect(erreurs).toEqual([]);
});

import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { connecter } from './connexion';

/**
 * Lot ged-parite-ecrans-1 : les RÉGLAGES de l'utilisateur (modèle Paperless `SettingsComponent`).
 *
 * Un utilisateur règle son interface (général), ses notifications, consulte ses permissions et ouvre une
 * section précise par son adresse. Tout passe par l'écran (menu, onglets, champs, bouton). Les réglages
 * vivent dans le fonds (.ged/preferences.json). Base gedv2_test, aucune donnée réelle.
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

async function ouvrirReglages(page: Page): Promise<void> {
  await page.getByTestId('menu-reglages').click({ timeout: 20_000 });
  await expect(page).toHaveURL(/\/reglages$/);
  await expect(page.getByTestId('ecran-reglages')).toBeVisible();
}

test('un utilisateur regle ses preferences generales', async ({ page }) => {
  test.setTimeout(60_000);
  const erreurs = surveillerConsole(page);

  await connecter(page);
  await ouvrirReglages(page);

  await page.getByTestId('reglage-theme').selectOption('sombre');
  await page.getByTestId('reglage-taille').fill('25');
  await page.getByTestId('action-enregistrer-reglages').click({ timeout: 20_000 });

  await expect(page).toHaveURL(/\/reglages\/reglages-generaux\?message=1$/);
  await expect(page.getByTestId('reglage-message')).toBeVisible();

  // Les réglages sont relus du fonds à l'ouverture suivante.
  await page.reload();
  await expect(page.getByTestId('reglage-theme')).toHaveValue('sombre');
  await expect(page.getByTestId('reglage-taille')).toHaveValue('25');

  expect(erreurs).toEqual([]);
});

test('un utilisateur choisit ses notifications', async ({ page }) => {
  test.setTimeout(60_000);
  const erreurs = surveillerConsole(page);

  await connecter(page);
  await ouvrirReglages(page);
  await page.getByTestId('reglage-onglet-notifications').click({ timeout: 20_000 });
  await expect(page).toHaveURL(/\/reglages\/notifications$/);

  await page.getByTestId('notification-echec').check();
  await page.getByTestId('action-enregistrer-notifications').click({ timeout: 20_000 });
  await expect(page).toHaveURL(/\/reglages\/notifications\?message=1$/);
  await expect(page.getByTestId('reglage-message')).toBeVisible();

  await page.reload();
  await expect(page.getByTestId('notification-echec')).toBeChecked();

  expect(erreurs).toEqual([]);
});

test('un utilisateur consulte ses permissions et les comptes', async ({ page }) => {
  test.setTimeout(60_000);
  const erreurs = surveillerConsole(page);

  await connecter(page);
  await ouvrirReglages(page);
  await page.getByTestId('reglage-onglet-permissions').click({ timeout: 20_000 });
  await expect(page).toHaveURL(/\/reglages\/permissions$/);

  await expect(page.getByTestId('reglage-permissions-liste')).toBeVisible();
  await expect(page.getByTestId('reglage-permission-1')).toBeVisible();
  await expect(page.getByTestId('reglage-permissions-comptes')).toHaveAttribute('href', '/administration/comptes');

  expect(erreurs).toEqual([]);
});

test('une section de reglages s ouvre par son adresse', async ({ page }) => {
  test.setTimeout(60_000);
  const erreurs = surveillerConsole(page);

  await connecter(page);
  await page.goto('/reglages/notifications');
  await expect(page.getByTestId('ecran-reglages')).toBeVisible();
  await expect(page.getByTestId('reglage-section')).toHaveText('Notifications');
  await expect(page.getByTestId('reglage-onglet-notifications')).toHaveAttribute('aria-current', 'page');

  expect(erreurs).toEqual([]);
});

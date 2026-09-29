import { expect, type Page } from '@playwright/test';

/** Connexion avec le compte synthétique des tests (voir tests/e2e/README.md) : la garde refuse tout sans session. */
export async function connecter(page: Page): Promise<void> {
  await page.goto('/connexion');
  await page.getByTestId('identifiant-connexion').fill('testeur@gedv2.test');
  await page.getByTestId('mot-de-passe-connexion').fill('MotDePasse-Test-2026!');
  await page.getByTestId('action-connexion').click();
  await expect(page).toHaveURL(/\/a-traiter$/);
}
import { expect, type Page } from '@playwright/test';

/**
 * Connexion avec le compte synthétique des tests (voir tests/e2e/README.md) : la garde refuse tout sans session.
 *
 * Les budgets d'action (5 s) sont trop courts quand plusieurs parcours partagent le serveur `php -S`
 * mono-processus : la file d'attente dépasse la limite et fait tomber des parcours sans défaut produit.
 * Le clic et l'attente d'URL reçoivent donc un délai explicite, comme le fait déjà la connexion réelle.
 */
export async function connecter(page: Page): Promise<void> {
  await page.goto('/connexion');
  await page.getByTestId('identifiant-connexion').fill('testeur@gedv2.test');
  await page.getByTestId('mot-de-passe-connexion').fill('MotDePasse-Test-2026!');
  await page.getByTestId('action-connexion').click({ timeout: 15_000 });
  await expect(page).toHaveURL(/\/a-traiter$/, { timeout: 15_000 });
}
import { test, expect, type Page } from '@playwright/test';

const identifiant = 'testeur@gedv2.test';
const motDePasse = 'MotDePasse-Test-2026!';

async function connecter(page: Page): Promise<void> {
  await page.goto('/connexion');
  await page.getByTestId('identifiant-connexion').fill(identifiant);
  await page.getByTestId('mot-de-passe-connexion').fill(motDePasse);
  await page.getByTestId('action-connexion').click();
  await expect(page).toHaveURL(/\/a-traiter$/);
}

test('connexion : une session durcie protège les écrans', async ({ page }) => {
  const refus = await page.goto('/bibliotheque');
  expect(refus?.status()).toBe(403);
  await expect(page.getByTestId('refus-acces')).toBeVisible();

  await connecter(page);
  await expect(page.getByTestId('ecran-a-traiter')).toBeVisible();
  const cookie = (await page.context().cookies()).find((item) => item.name === 'gedv2_session');
  expect(cookie?.httpOnly).toBe(true);
  expect(cookie?.sameSite).toBe('Lax');
});

test('déconnexion : POST et CSRF sont obligatoires', async ({ page }) => {
  await connecter(page);
  const sansCsrf = await page.request.post('/deconnexion', { maxRedirects: 0 });
  expect(sansCsrf.status()).toBe(403);

  const csrf = (await page.context().cookies()).find((item) => item.name === 'gedv2_csrf')?.value;
  expect(csrf).toBeTruthy();
  const deconnexion = await page.request.post('/deconnexion', { headers: { 'X-CSRF-Token': csrf! }, maxRedirects: 0 });
  expect(deconnexion.status()).toBe(303);

  const refus = await page.goto('/a-traiter');
  expect(refus?.status()).toBe(403);
});

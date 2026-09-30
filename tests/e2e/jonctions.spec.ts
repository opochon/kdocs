import { test, expect } from '@playwright/test';
import { connecter } from './connexion';

/**
 * Oracle V2-08b — parcours Playwright connecté de l'appairage à l'écran « Appareils reliés ».
 *
 * CMM ouvre `/appairage` (session normale), l'utilisateur accepte, la GED redirige vers le retour CMM
 * avec un code à usage unique ; l'appareil apparaît ensuite dans Application › Appareils reliés et se
 * révoque.
 */
const port = Number(process.env.GED_PORT_TESTS ?? 8871);

test('appairage : de la demande CMM à l’écran Appareils reliés, puis révocation', async ({ page }) => {
  await connecter(page);

  const poste = `cmm-e2e-${Date.now()}`;
  const retour = `http://127.0.0.1:${port}/retour-cmm`;
  const url = `/appairage?application=cmm&nom=${encodeURIComponent(poste)}&retour=${encodeURIComponent(retour)}&etat=alea-e2e`;

  await page.goto(url);
  await expect(page.getByTestId('ecran-appairage')).toBeVisible();
  await expect(page.getByTestId('appairage-application')).toContainText('ClearMyMails');
  await expect(page.getByTestId('appairage-poste')).toContainText(poste);

  await page.getByTestId('action-appairage-accepter').click();
  await page.waitForURL(/\/retour-cmm\?/);
  expect(page.url()).toContain('code=');
  expect(page.url()).toContain('etat=alea-e2e');

  // Rôle de CMM : échanger le code à usage unique contre le jeton lié au compte.
  const code = new URL(page.url()).searchParams.get('code') ?? '';
  expect(code).not.toBe('');
  const echange = await page.request.post('/api/v1/appairage/jeton', { data: { code } });
  expect(echange.status()).toBe(200);
  expect((await echange.json()).compte).toBeTruthy();

  // Rejouer le même code doit être refusé, nommé.
  const rejeu = await page.request.post('/api/v1/appairage/jeton', { data: { code } });
  expect(rejeu.status()).toBe(400);

  await page.goto('/application/appareils');
  await expect(page.getByTestId('ecran-appareils')).toBeVisible();
  const ligne = page.locator('[data-testid="appareil"]').filter({ hasText: poste });
  await expect(ligne).toBeVisible();
  await expect(ligne).toContainText('cmm');
  await ligne.getByTestId('action-revoquer-appareil').click();

  await expect(page).toHaveURL(/\/application\/appareils$/);
  await expect(page.locator('[data-testid="appareil"]').filter({ hasText: poste })).toContainText('révoqué');
});

test('appairage : sans session, la GED refuse et nomme', async ({ page }) => {
  const refus = await page.goto('/appairage?application=cmm&retour=https%3A%2F%2Fexemple.test%2Fretour');
  expect(refus?.status()).toBe(403);
  await expect(page.getByTestId('refus-acces')).toBeVisible();
});

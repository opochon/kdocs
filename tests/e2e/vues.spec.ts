import { test, expect, type Page } from '@playwright/test';
import { connecter } from './connexion';

/**
 * Lot ged-parite-modele-3 : les VUES ENREGISTRÉES vues par l'écran (modèle SavedView).
 *
 * Un utilisateur ouvre Application → Vues enregistrées, crée une vue nommée en choisissant les champs
 * affichés, le mode, la taille de page, le tri et sa présence au tableau de bord / dans le menu, puis
 * modifie ses réglages. Tout passe par l'écran (menu, champs, boutons) : aucun appel d'API interne.
 */

function surveillerConsole(page: Page): string[] {
  const erreurs: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') erreurs.push(m.text()); });
  page.on('pageerror', (e) => erreurs.push(e.message));
  return erreurs;
}

async function ouvrirVues(page: Page): Promise<void> {
  await page.getByTestId('menu-application').click({ timeout: 20_000 });
  await expect(page.getByTestId('ecran-application')).toBeVisible();
  await page.getByTestId('lien-vues').click({ timeout: 20_000 });
  await expect(page).toHaveURL(/\/vues$/);
  await expect(page.getByTestId('ecran-vues')).toBeVisible();
}

test('un utilisateur crée et règle une vue enregistrée', async ({ page }) => {
  test.setTimeout(60_000);
  const erreurs = surveillerConsole(page);
  const nom = `Vue E2E ${Date.now()}`;

  await connecter(page);
  await ouvrirVues(page);

  await page.getByTestId('vue-nom').fill(nom);
  await page.getByTestId('vue-champ-title').check();
  await page.getByTestId('vue-champ-tags').check();
  await page.getByTestId('vue-mode').selectOption('small_cards');
  await page.getByTestId('vue-taille').fill('20');
  await page.getByTestId('vue-tri').selectOption('created');
  await page.getByTestId('vue-tri-inverse').check();
  await page.getByTestId('vue-tableau-bord').check();
  await page.getByTestId('action-creer-vue').click({ timeout: 20_000 });

  await expect(page).toHaveURL(/\/vues$/);
  await expect(page.getByTestId('ecran-vues')).toBeVisible();
  await expect(page.locator('body')).toContainText(nom);

  // La vue créée porte bien ses réglages, relus du fonds.
  const vue = page.locator('form[data-testid^="vue-"]', { hasText: nom });
  await expect(vue).toContainText('small_cards');
  await expect(vue).toContainText('20');
  await expect(vue.getByTestId(/vue-\d+-tableau-bord/)).toBeChecked();

  // Modification : on relâche le tableau de bord et on garde le menu latéral.
  await vue.getByTestId(/action-enregistrer-vue-\d+/).click({ timeout: 20_000 });
  await expect(page).toHaveURL(/\/vues$/);
  await expect(page.locator('body')).toContainText(nom);

  expect(erreurs).toEqual([]);
});

import { test, expect, type Page } from '@playwright/test';
import { connecter } from './connexion';

/**
 * Oracle E2E du lot ged-mfiles-permissions-1 — famille M-Files « permissions dynamiques ».
 *
 * Le parcours AGIT comme un administrateur réel : il ouvre le menu Administration → « Permissions »,
 * crée une règle nommée par le formulaire (classe + rôle autorisé), vérifie qu'elle apparaît ACTIVE,
 * la désactive puis la réactive. Tout passe par l'écran (menu, champs, boutons) : aucun appel d'API
 * interne. Compte de test synthétique, base gedv2_test, aucune donnée réelle.
 */

function surveillerConsole(page: Page): string[] {
  const erreurs: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') erreurs.push(m.text()); });
  page.on('pageerror', (e) => erreurs.push(e.message));
  return erreurs;
}

test('un administrateur crée une règle de permission, la désactive et la réactive', async ({ page }) => {
  test.setTimeout(60_000);
  const erreurs = surveillerConsole(page);
  const nom = `Règle E2E ${Date.now()}`;

  await connecter(page);

  // Le menu Administration mène à l'écran des règles (barre à gauche sous le titre).
  await page.getByTestId('menu-permissions').click({ timeout: 20_000 });
  await expect(page).toHaveURL(/\/permissions$/);
  await expect(page.getByTestId('ecran-permissions')).toBeVisible();

  // Création par le formulaire : classe Facture, seuls les « utilisateur » voient.
  await page.getByTestId('regle-nom').fill(nom);
  await page.getByTestId('regle-classe').selectOption('Facture');
  await page.getByTestId('regle-role').selectOption('utilisateur');
  await page.getByTestId('action-creer-regle').click({ timeout: 20_000 });

  await expect(page).toHaveURL(/\/permissions$/);
  await expect(page.locator('body')).toContainText(nom);

  // La règle apparaît active, avec sa cible et sa condition.
  const section = page.locator('[data-testid^="regle-"]').filter({ hasText: nom }).first();
  await expect(section).toBeVisible();
  await expect(section).toContainText('active');
  await expect(section).toContainText('classe Facture');
  await expect(section).toContainText('role utilisateur');

  // Désactivation par le bouton de la règle : jamais de suppression, l'état change.
  const identifiant = (await section.getAttribute('data-testid'))?.replace('regle-', '') ?? '';
  expect(Number(identifiant)).toBeGreaterThan(0);
  await page.getByTestId(`action-basculer-${identifiant}`).click({ timeout: 20_000 });
  await expect(page).toHaveURL(/\/permissions$/);
  await expect(page.getByTestId(`regle-etat-${identifiant}`)).toHaveText('désactivée');

  // Réactivation : la règle revient active.
  await page.getByTestId(`action-basculer-${identifiant}`).click({ timeout: 20_000 });
  await expect(page.getByTestId(`regle-etat-${identifiant}`)).toHaveText('active');

  expect(erreurs, `erreurs console : ${erreurs.join(' | ')}`).toEqual([]);
});

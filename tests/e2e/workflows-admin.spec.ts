import { test, expect, type Page } from '@playwright/test';
import { connecter } from './connexion';

/**
 * Oracle E2E du lot ged-parite-modele-1 — administration des workflows du modèle Paperless.
 *
 * Le parcours AGIT comme un administrateur réel : il ouvre le menu Workflows, suit le lien
 * « Administration des workflows », crée un workflow par le formulaire, l'ouvre, change son ordre et son
 * activation, puis lit l'historique des exécutions. Tout passe par l'écran (menu, champs, boutons) :
 * aucun appel d'API interne. Compte de test réel, base gedv2_test, aucune donnée réelle.
 */

function surveillerConsole(page: Page): string[] {
  const erreurs: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') erreurs.push(m.text()); });
  page.on('pageerror', (e) => erreurs.push(e.message));
  return erreurs;
}

test('un administrateur crée, édite, active et lit l’historique d’un workflow', async ({ page }) => {
  test.setTimeout(60_000);
  const erreurs = surveillerConsole(page);
  const nom = `Workflow E2E ${Date.now()}`;

  await connecter(page);

  // Le menu mène à l'écran Workflows, puis au lien d'administration (barre d'actions à gauche sous le titre).
  await page.getByTestId('menu-workflows').click();
  await expect(page).toHaveURL(/\/workflows$/);
  await page.getByTestId('lien-admin').click();
  await expect(page).toHaveURL(/\/workflows\/admin$/);
  await expect(page.getByTestId('ecran-workflows-admin')).toBeVisible();

  // Création par le formulaire.
  await page.getByTestId('workflow-nom').fill(nom);
  await page.getByTestId('workflow-ordre').fill('2');
  await page.getByTestId('workflow-declencheur').selectOption('2');
  await page.getByTestId('workflow-filtre').selectOption('filter_filename');
  await page.getByTestId('workflow-filtre-valeur').fill('*e2e*');
  await page.getByTestId('workflow-action').selectOption('1');
  await page.getByTestId('workflow-champ').selectOption('titre');
  await page.getByTestId('workflow-valeur').fill('Titre E2E');
  await page.getByTestId('action-creer-workflow').click();

  await expect(page).toHaveURL(/\/workflows\/admin\/\d+$/);
  await expect(page.getByTestId('ecran-workflows-admin-edition')).toBeVisible();
  await expect(page.locator('body')).toContainText(nom);
  const id = Number((page.url().match(/\/workflows\/admin\/(\d+)$/) ?? [])[1]);
  expect(id).toBeGreaterThan(0);

  // Édition : ordre et mise en veille, puis historique vide visible.
  await page.getByTestId('edition-ordre').fill('5');
  await page.getByTestId('edition-actif').uncheck();
  await page.getByTestId('action-enregistrer-workflow').click();
  await expect(page).toHaveURL(new RegExp(`/workflows/admin/${id}$`));
  await expect(page.locator('body')).toContainText('en veille');
  await expect(page.getByTestId('historique-executions')).toBeVisible();
  await expect(page.getByTestId('aucune-execution')).toBeVisible();

  // La liste montre le workflow et son état.
  await page.getByTestId('lien-admin-liste').click();
  await expect(page).toHaveURL(/\/workflows\/admin$/);
  await expect(page.getByTestId(`workflow-${id}`)).toBeVisible();
  await expect(page.getByTestId(`workflow-nom-${id}`)).toContainText(nom);
  await expect(page.getByTestId(`workflow-etat-${id}`)).toContainText('en veille');

  // Réactivation par le bouton de la ligne.
  await page.getByTestId(`basculer-workflow-${id}`).click();
  await expect(page.getByTestId(`workflow-etat-${id}`)).toContainText('actif');

  expect(erreurs, 'aucune erreur console').toEqual([]);
});

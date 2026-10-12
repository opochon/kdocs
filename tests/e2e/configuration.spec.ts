import { test, expect, type Page } from '@playwright/test';
import { connecter } from './connexion';

/**
 * Lot ged-parite-ecrans-1 : la CONFIGURATION globale et les CHEMINS DE STOCKAGE (modèles Paperless
 * `ConfigComponent` et `StoragePathListComponent`).
 *
 * Un administrateur ouvre Application et lit la configuration RÉELLE de l'installation (racine, règle de
 * rangement, modèle IA). Aucune valeur inventée : une information absente le dit. Base gedv2_test.
 */

function surveillerConsole(page: Page): string[] {
  const erreurs: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') erreurs.push(m.text()); });
  page.on('pageerror', (e) => erreurs.push(e.message));
  return erreurs;
}

test('un administrateur lit la configuration et les chemins de stockage', async ({ page }) => {
  test.setTimeout(60_000);
  const erreurs = surveillerConsole(page);

  await connecter(page);

  await page.getByTestId('menu-application').click({ timeout: 20_000 });
  await expect(page).toHaveURL(/\/application$/);
  await expect(page.getByTestId('ecran-application')).toBeVisible();

  // Configuration globale : la racine documentaire et le modèle de classement sont réels.
  await expect(page.getByTestId('reglage-racine')).toContainText('le dossier fait foi');
  await expect(page.getByTestId('reglage-modele-ia')).toBeVisible();

  // Chemins de stockage : la règle de rangement du fonds est celle du moteur.
  await expect(page.getByTestId('reglage-stockage')).toBeVisible();
  await expect(page.getByTestId('reglage-rangement')).toContainText('année / type / émetteur');

  expect(erreurs).toEqual([]);
});

/**
 * Lot ged-parite-final-1 : les RÉFÉRENTIELS de l'écran Application (émetteurs = Correspondent, emplacements
 * = StoragePath) se créent et se retirent en veille depuis l'écran, et l'identité de l'application est
 * modifiable. Le test agit comme un vrai administrateur : il remplit le formulaire, clique, et vérifie ce
 * qu'il VOIT (le nom dans la liste, l'état « en veille »).
 */
test('un administrateur crée un émetteur, le met en veille et modifie l’identité', async ({ page }) => {
  test.setTimeout(60_000);
  const erreurs = surveillerConsole(page);
  const nom = `Émetteur E2E ${Date.now()}`;

  await connecter(page);
  await page.getByTestId('menu-application').click({ timeout: 20_000 });
  await expect(page.getByTestId('reglage-referentiels')).toBeVisible();

  // Créer un émetteur : il apparaît dans la liste avec sa règle.
  await page.getByTestId('emetteur-nom').fill(nom);
  await page.getByTestId('emetteur-motif').fill('règle e2e');
  await page.getByTestId('emetteur-ajouter').click();
  const ligne = page.locator('[data-testid^="emetteur-"]', { hasText: nom }).first();
  await expect(ligne).toContainText(nom);
  await expect(ligne).toContainText('règle e2e');

  // Mettre en veille : l'état affiché change, l'émetteur reste visible (jamais supprimé).
  await ligne.locator('button[data-testid$="-basculer"]').click();
  await expect(ligne).toContainText('en veille');

  // Identité de l'application : la surcharge du fonds est prise, puis rendue au `.env`.
  const titre = `GED E2E ${Date.now()}`;
  await page.getByTestId('configuration-titre').fill(titre);
  await page.getByTestId('configuration-enregistrer').click();
  await page.reload();
  await expect(page.getByTestId('configuration-titre')).toHaveValue(titre);
  await expect(page.getByTestId('configuration-source')).toContainText('surcharge du fonds');
  await page.getByTestId('configuration-reinitialiser').click();
  await expect(page.getByTestId('configuration-source')).toContainText('environnement');

  expect(erreurs).toEqual([]);
});

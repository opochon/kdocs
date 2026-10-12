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

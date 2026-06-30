import { test, expect } from '@playwright/test';

// Bug G — verification persistance du Type de document apres sauvegarde + rechargement.
// On utilise le formulaire dedie /documents/{id}/edit (POST) et on verifie qu'au
// rechargement le select #document_type_id reaffiche la valeur sauvegardee.

const BASE_PATH = process.env.KDOCS_BASE_PATH ?? '/kdocs';
const HOST = process.env.KDOCS_HOST ?? '127.0.0.1';
const PORT = process.env.KDOCS_PORT ?? '8765';
const BASE = `http://${HOST}:${PORT}${BASE_PATH}`;

const DOC_ID = process.env.KDOCS_BUGG_DOCID ?? '88';

test.describe('Bug G — persistance Type de document', () => {
  test('sauvegarder un type puis recharger affiche toujours ce type', async ({ page }) => {
    // 1. Ouvrir le formulaire d'edition du document.
    await page.goto(`${BASE}/documents/${DOC_ID}/edit`, { waitUntil: 'domcontentloaded' });

    const typeSelect = page.locator('#document_type_id');
    await expect(typeSelect).toBeVisible();

    // Valeur initiale (avant test) — on retient l'etat pour restituer.
    const initialValue = await typeSelect.inputValue();

    // 2. Choisir un type non vide (le 2e option reel, apres "-- Aucun --").
    const options = typeSelect.locator('option');
    const count = await options.count();
    expect(count, 'au moins un type doit exister').toBeGreaterThan(1);
    const chosenValue = await options.nth(1).getAttribute('value');
    const chosenLabel = (await options.nth(1).textContent())?.trim() ?? '';
    expect(chosenValue, 'le type choisi doit avoir un id non vide').toBeTruthy();

    await typeSelect.selectOption(chosenValue!);

    // 3. Soumettre le formulaire (clic natif sur le bouton Enregistrer).
    await page.click('button[type="submit"]', { hasText: /Enregistrer|Sauvegarder|Modifier/i });
    await page.waitForLoadState('domcontentloaded');

    // 4. Recharger le formulaire et verifier que le type est conserve.
    await page.goto(`${BASE}/documents/${DOC_ID}/edit`, { waitUntil: 'domcontentloaded' });
    const reloadedValue = await page.locator('#document_type_id').inputValue();
    expect(reloadedValue, 'le type doit etre conserve apres rechargement').toBe(chosenValue);

    // 5. Restitution de l'etat initial (pour ne pas modifier la donnee de prod).
    if (initialValue !== reloadedValue) {
      await page.locator('#document_type_id').selectOption({ value: initialValue || '' });
      await page.click('button[type="submit"]', { hasText: /Enregistrer|Sauvegarder|Modifier/i });
      await page.waitForLoadState('domcontentloaded');
    }

    // Trace lisible dans les logs.
    console.log(`Bug G: type initial=[${initialValue}] -> choisi=[${chosenValue}]=${chosenLabel} -> recharge=[${reloadedValue}]`);
  });
});

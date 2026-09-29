import { test, expect } from '@playwright/test';
import { connecter } from './connexion';

/**
 * Porte 5 : au clavier, créer une règle, la tester à blanc, l'activer, puis la voir agir sur un dépôt.
 * Le document d'essai est un document synthétique du fonds de test (aucune donnée réelle).
 */
test.beforeEach(async ({ page }) => { await connecter(page); });

test('workflows : créer une règle, l’essayer à blanc, l’activer, la voir agir', async ({ page }) => {
  // Nom unique : le socle de test garde les règles entre deux exécutions, le parcours doit rester idempotent.
  const nomRegle = `Règle e2e — notifier les factures ${Date.now()}`;
  await page.goto('/workflows');
  await expect(page.getByTestId('ecran-workflows')).toBeVisible();

  // 1. Créer une règle au clavier (le panneau s'ouvre via l'élément focus + Entrée).
  await page.getByTestId('action-nouvelle-regle').focus();
  await page.keyboard.press('Enter');
  await page.getByTestId('regle-nom').fill(nomRegle);
  await page.getByTestId('regle-declencheur').selectOption('document_depose');
  await page.getByTestId('regle-condition-champ').selectOption('type');
  await page.getByTestId('regle-condition-operateur').selectOption('egal');
  await page.getByTestId('regle-condition-valeur').fill('Facture');
  await page.getByTestId('regle-action-type').selectOption('notifier');
  await page.getByTestId('regle-action-destinataire').fill('responsable');
  await page.getByTestId('regle-action-message').fill('Règle e2e : {titre}');
  await page.getByTestId('action-creer-regle').focus();
  await page.keyboard.press('Enter');

  const carte = page.locator('[data-testid^="regle-"]').filter({ hasText: nomRegle }).first();
  await expect(carte).toBeVisible();

  // 2. Essai à blanc sur un document réel du fonds, sans rien écrire.
  await page.getByTestId('essai-document').selectOption('201');
  await page.getByTestId('essai-declencheur').selectOption('document_depose');
  await page.getByTestId('action-essai').click();
  await expect(page.getByTestId('essai-resultat')).toBeVisible();
  await expect(page.getByTestId('essai-zero-ecriture')).toContainText('zéro écriture');
  await expect(page.getByTestId('essai-resultat')).toContainText(nomRegle);

  // 3. Activer la règle (au clavier).
  await carte.getByTestId(/basculer$/).focus();
  await page.keyboard.press('Enter');
  await expect(carte).toContainText('actif');

  // 4. La voir agir sur un dépôt : l'événement « document déposé » déclenche la règle, la trace apparaît.
  await page.getByTestId('evenement-document').selectOption('201');
  await page.getByTestId('evenement-declencheur').selectOption('document_depose');
  await page.getByTestId('action-evenement').click();
  await expect(page.getByTestId('journal')).toContainText('Règle e2e : Facture AN-5001');

  // 5. Le gabarit « facture à seuil » s'installe d'un geste et montre son circuit, ses états et ses droits.
  await page.getByTestId('action-installer-facture_a_seuil').click();
  const circuit = page.locator('[data-testid^="circuit-"]').filter({ hasText: 'Facture à seuil' }).first();
  await expect(circuit).toContainText('Facture à seuil');
  await expect(circuit).toContainText('À valider');
  await expect(circuit).toContainText('workflow.transition.valider');
  await expect(circuit).toContainText('workflow.transition.refuser');
});

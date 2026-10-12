import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { connecter } from './connexion';

/**
 * Lot ged-parite-ecrans-1 : l'ACCÈS PAR NUMÉRO D'ARCHIVE (modèle Paperless `DocumentAsnComponent`).
 *
 * Un utilisateur saisit un numéro dans le champ d'accès direct et valide : le document s'ouvre. Un numéro
 * inconnu est refusé NOMMÉMENT. Tout passe par l'écran. Base gedv2_test, aucune donnée réelle.
 */

const RACINE = process.cwd();
const ENV = { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: 'tests/corpus/apercu/documents' };

function surveillerConsole(page: Page): string[] {
  const erreurs: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') erreurs.push(m.text()); });
  page.on('pageerror', (e) => erreurs.push(e.message));
  return erreurs;
}

let idArchive = 0;
let titreArchive = '';

test.beforeAll(() => {
  execFileSync('php', ['tests/e2e/preparer-ecrans.php'], { cwd: RACINE, env: ENV });
  const etat = JSON.parse(readFileSync(path.join(RACINE, 'var/e2e-ecrans.json'), 'utf8')) as {
    archive: { id: number };
  };
  idArchive = etat.archive.id;
  titreArchive = 'E2E document archive';
});

test.afterAll(() => {
  execFileSync('php', ['tests/e2e/nettoyer-ecrans.php'], { cwd: RACINE, env: ENV });
});

test('un utilisateur ouvre un document par son numero d archive', async ({ page }) => {
  test.setTimeout(60_000);
  const erreurs = surveillerConsole(page);

  await connecter(page);

  await page.getByTestId('menu-asn').click({ timeout: 20_000 });
  await expect(page).toHaveURL(/\/asn$/);
  await expect(page.getByTestId('ecran-asn')).toBeVisible();

  // Un numéro connu ouvre la fiche du document.
  await page.getByTestId('asn-numero').fill(String(idArchive));
  await page.getByTestId('action-ouvrir-asn').click({ timeout: 20_000 });
  await expect(page).toHaveURL(new RegExp(`/documents/${idArchive}$`));
  await expect(page.locator('body')).toContainText(titreArchive);

  // Un numéro inconnu est refusé nommément, jamais un 500 muet.
  await page.getByTestId('menu-asn').click({ timeout: 20_000 });
  await page.getByTestId('asn-numero').fill('999999');
  await page.getByTestId('action-ouvrir-asn').click({ timeout: 20_000 });
  await expect(page).toHaveURL(/numero=999999/);
  await expect(page.getByTestId('asn-erreur')).toContainText('999999');

  expect(erreurs).toEqual([]);
});

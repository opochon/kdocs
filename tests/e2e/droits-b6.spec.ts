import { test, expect, type Browser, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';

type Preparation = { mot_de_passe: string; comptes: Record<string, { id: number; identifiant: string }>; dossiers: Record<string, number>; documents: Record<string, number>; groupe_utilisateurs: number };
let prep: Preparation;

function preparer(): Preparation {
  return JSON.parse(execFileSync('php', ['tests/e2e/preparer-droits-b6.php'], { env: { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test' } }).toString()) as Preparation;
}
async function connecter(browser: Browser, role: string): Promise<{ page: Page; fermer: () => Promise<void> }> {
  const contexte = await browser.newContext();
  const page = await contexte.newPage();
  await page.goto('/connexion');
  await page.getByTestId('identifiant-connexion').fill(prep.comptes[role].identifiant);
  await page.getByTestId('mot-de-passe-connexion').fill(prep.mot_de_passe);
  await page.getByTestId('action-connexion').click();
  await expect(page).toHaveURL(/\/a-traiter$/);
  return { page, fermer: () => contexte.close() };
}

test.describe.configure({ mode: 'serial' });
test.beforeAll(() => { prep = preparer(); });

test('B6 : trois comptes réels respectent les droits sur les dossiers A et B', async ({ browser }) => {
  const utilisateur = await connecter(browser, 'utilisateur');
  try {
    expect((await utilisateur.page.goto(`/documents/${prep.documents.A}`))?.status()).toBe(200);
    expect((await utilisateur.page.goto(`/documents/${prep.documents.B}`))?.status()).toBe(403);
    expect((await utilisateur.page.goto(`/documents/${prep.documents.B}/apercu`))?.status()).toBe(403);
    expect((await utilisateur.page.goto(`/dossiers/${prep.dossiers.A}/droits`))?.status()).toBe(403);
    expect((await utilisateur.page.goto('/administration/comptes'))?.status()).toBe(403);
  } finally { await utilisateur.fermer(); }

  const manager = await connecter(browser, 'manager');
  try {
    expect((await manager.page.goto(`/dossiers/${prep.dossiers.A}/droits`))?.status()).toBe(200);
    await expect(manager.page.getByTestId('action-enregistrer-droit')).toBeVisible();
  } finally { await manager.fermer(); }

  const administrateur = await connecter(browser, 'administrateur');
  try {
    expect((await administrateur.page.goto('/administration/comptes'))?.status()).toBe(200);
    expect((await administrateur.page.goto(`/documents/${prep.documents.B}`))?.status()).toBe(200);
  } finally { await administrateur.fermer(); }
});

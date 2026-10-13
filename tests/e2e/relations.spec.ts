import { test, expect, type Browser, type Page } from '@playwright/test';
import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import path from 'node:path';

/**
 * Famille M-Files nº 2 (lot ged-mfiles-relations-1) : les relations typées de la fiche, jouées depuis
 * l'écran réel — lier un document à un autre, voir le lien dans les deux sens, naviguer vers l'objet
 * lié, détacher un sous-objet puis retirer. Aucun appel d'API interne : tout passe par les onglets et
 * les formulaires de la fiche.
 */
const RACINE = process.cwd();
type Prep = { compte: string; mot_de_passe: string; document: number; cible: number };

async function portLibre(): Promise<number> {
  return new Promise((ok, ko) => { const s = createServer(); s.once('error', ko); s.listen(0, '127.0.0.1', () => { const a = s.address(); const p = typeof a === 'object' && a ? a.port : 0; s.close(() => ok(p)); }); });
}

async function attente(base: string): Promise<void> {
  for (let i = 0; i < 100; i++) { try { if ((await fetch(base + '/sante')).ok) return; } catch { /* serveur pas prêt */ } await new Promise((r) => setTimeout(r, 100)); }
  throw new Error('serveur relations indisponible');
}

async function connecter(page: Page, prep: Prep): Promise<void> {
  await page.goto('/connexion');
  await page.getByTestId('identifiant-connexion').fill(prep.compte);
  await page.getByTestId('mot-de-passe-connexion').fill(prep.mot_de_passe);
  await page.getByTestId('action-connexion').click();
  await expect(page).toHaveURL(/\/a-traiter$/);
}

async function avecRelations<T>(fn: (base: string, prep: Prep) => Promise<T>): Promise<T> {
  const fonds = path.join(RACINE, 'var', 'oracles', 'relations', 'documents');
  const securite = path.join(RACINE, 'var', 'oracles', 'relations', 'securite');
  const env = { ...process.env, DB_NAME: 'gedv2_test', APP_ENV: 'test', STORAGE_ROOT: fonds, SECURITE_DOSSIER: securite };
  const prep = JSON.parse(execFileSync('php', ['tests/e2e/preparer-relations.php'], { cwd: RACINE, env }).toString()) as Prep;
  const port = await portLibre();
  const base = `http://127.0.0.1:${port}`;
  const serveur: ChildProcess = spawn('php', ['-d', 'opcache.enable=0', '-S', `127.0.0.1:${port}`, '-t', 'public', 'public/index.php'], { cwd: RACINE, env, stdio: 'ignore' });
  try { await attente(base); return await fn(base, prep); } finally { serveur.kill(); }
}

test.describe.configure({ mode: 'serial' });

test('Relations, bureau : lier, naviguer dans les deux sens, retirer', async ({ browser }) => {
  await avecRelations(async (base, prep) => {
    const c = await browser.newContext({ baseURL: base });
    const page = await c.newPage();
    try {
      await connecter(page, prep);
      await page.goto(`/documents/${prep.document}`);
      await page.getByTestId('onglet-relations').click();
      await expect(page.getByTestId('panneau-relations')).toBeVisible();
      await page.getByTestId('relation-type').selectOption('reference');
      await page.getByTestId('relation-cible').fill(String(prep.cible));
      await page.getByTestId('relation-enregistrer').click();

      await page.getByTestId('onglet-relations').click();
      await expect(page.getByTestId('relation-succes')).toBeVisible();
      await expect(page.getByTestId('panneau-relations-liste')).toContainText('Référence');
      await expect(page.getByTestId('panneau-relations-liste')).toContainText('Contrat Relations');

      // Naviguer vers l'objet lié depuis l'écran, pas par URL directe.
      await page.getByTestId('panneau-relations-liste').getByRole('link', { name: 'Contrat Relations' }).click();
      await expect(page).toHaveURL(new RegExp(`/documents/${prep.cible}$`));
      await page.getByTestId('onglet-relations').click();
      await expect(page.getByTestId('panneau-relations-liste')).toContainText('Facture Relations');
      await expect(page.getByTestId('panneau-relations-liste')).toContainText('lié depuis');

      // Retirer le lien depuis la cible : les deux fiches redeviennent sans relation.
      await page.locator('[data-testid^="relation-retirer-"]').first().click();
      await page.getByTestId('onglet-relations').click();
      await expect(page.getByTestId('panneau-relations-liste')).toContainText('Aucune relation');
      await page.goto(`/documents/${prep.document}`);
      await page.getByTestId('onglet-relations').click();
      await expect(page.getByTestId('panneau-relations-liste')).toContainText('Aucune relation');
    } finally { await c.close(); }
  });
});

test('Relations, bureau : composition parent-enfant et détachement', async ({ browser }) => {
  await avecRelations(async (base, prep) => {
    const c = await browser.newContext({ baseURL: base });
    const page = await c.newPage();
    try {
      await connecter(page, prep);
      await page.goto(`/documents/${prep.document}`);
      await page.getByTestId('onglet-relations').click();
      await page.getByTestId('relation-type').selectOption('sous-objet');
      await page.getByTestId('relation-cible').fill(String(prep.cible));
      await page.getByTestId('relation-enregistrer').click();

      await page.getByTestId('onglet-relations').click();
      await expect(page.getByTestId('panneau-relations-liste')).toContainText('Sous-objet');
      await expect(page.getByTestId('panneau-relations-liste')).toContainText('Contrat Relations');

      // L'enfant voit qu'il appartient au dossier.
      await page.getByTestId('panneau-relations-liste').getByRole('link', { name: 'Contrat Relations' }).click();
      await page.getByTestId('onglet-relations').click();
      await expect(page.getByTestId('panneau-relations-liste')).toContainText('Facture Relations');
      await expect(page.getByTestId('panneau-relations-liste')).toContainText('lié depuis');

      // Détacher depuis le parent : la pièce redevient indépendante, des deux côtés.
      await page.goto(`/documents/${prep.document}`);
      await page.getByTestId('onglet-relations').click();
      await page.locator('[data-testid^="relation-detacher-"]').first().click();
      await page.getByTestId('onglet-relations').click();
      await expect(page.getByTestId('panneau-relations-liste')).toContainText('Aucune relation');
      await page.goto(`/documents/${prep.cible}`);
      await page.getByTestId('onglet-relations').click();
      await expect(page.getByTestId('panneau-relations-liste')).toContainText('Aucune relation');
    } finally { await c.close(); }
  });
});

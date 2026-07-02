import { test, expect, type Page, type APIRequestContext } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { BASE } from './helpers/personas';
import { expectNoPhpError } from './helpers/page-guards';

const SHOTS = path.join(__dirname, '..', 'shots');
const STORAGE = path.join(__dirname, '..', 'storageState.json');
const SAMPLE_PDF = path.resolve(__dirname, '..', '..', 'samples', 'test.pdf');
/** Aligné sur eval-full (storage.documents) — fichier physique non indexé pour F-LIB-04. */
const STORAGE_DOCS = process.env.KDOCS_STORAGE_DOCUMENTS ?? 'C:/wamp64/www/kdocs/storage/documents';
fs.mkdirSync(SHOTS, { recursive: true });

async function apiCreateFolder(api: APIRequestContext, parentPath: string, name: string): Promise<string> {
  const resp = await api.post(`${BASE}/api/folders/create`, {
    headers: { 'Content-Type': 'application/json' },
    data: { parent_path: parentPath, name },
  });
  expect(resp.ok(), `create folder HTTP ${resp.status()}`).toBeTruthy();
  const json = await resp.json();
  expect(json.success, json.error ?? 'create failed').toBe(true);
  return json.path as string;
}

/** Clic droit sur un nœud dossier de l'arborescence. */
async function openFolderContextMenu(page: Page, folderPath: string) {
  await page.goto(`${BASE}/documents?path=${encodeURIComponent(folderPath)}`, {
    waitUntil: 'domcontentloaded',
  });
  await page.waitForLoadState('networkidle');

  const parts = folderPath.split('/').filter(Boolean);
  let acc = '';
  for (let i = 0; i < parts.length - 1; i++) {
    acc = acc ? `${acc}/${parts[i]}` : parts[i];
    const parentItem = page.locator(`.folder-item[data-folder-path="${acc}"]`).first();
    const children = parentItem.locator(':scope > .folder-children');
    if ((await children.count()) > 0) {
      const hidden = await children.evaluate((el) => getComputedStyle(el).display === 'none');
      if (hidden) {
        await parentItem.locator('.folder-arrow').first().click();
      }
    }
  }

  const item = page.locator(`.folder-item[data-folder-path="${folderPath}"]`).first();
  await expect(item, `dossier absent de l'arborescence: ${folderPath}`).toBeVisible({ timeout: 15_000 });
  await item.click({ button: 'right' });
  const menu = page.locator('#folder-context-menu');
  await expect(menu).toBeVisible();
  return menu;
}

/**
 * Lot B — Opérations bibliothèque (session root, oracles UI stricts).
 * Crée des dossiers sous eval/ puis les nettoie en fin de suite.
 */
test.describe('lib-operations (Lot B)', () => {
  test.describe.configure({ mode: 'serial' });
  test.setTimeout(60_000);

  const stamp = Date.now();
  let folderA = '';
  let folderB = '';
  let renamedPath = '';

  test.beforeAll(async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: STORAGE });
    const api = ctx.request;
    folderA = await apiCreateFolder(api, 'eval', `pw_lib_a_${stamp}`);
    folderB = await apiCreateFolder(api, 'eval', `pw_lib_b_${stamp}`);
    await ctx.close();
  });

  test('F-LIB-08: tri et vue grille/liste', async ({ page }) => {
    await page.goto(`${BASE}/documents?path=${encodeURIComponent(folderA)}`, { waitUntil: 'domcontentloaded' });
    await expectNoPhpError(page);

    await page.selectOption('#sort-select', 'title-asc');
    await page.waitForURL(/sort=title/);
    expect(page.url()).toMatch(/order=ASC/i);

    await page.locator('button.view-toggle[title="Liste"]').click();
    await page.waitForURL(/view=list/);
    expect(page.url()).toContain('view=list');

    await page.screenshot({ path: path.join(SHOTS, 'lib-sort-view.png'), fullPage: true });
  });

  test('F-LIB-04: indexer ce dossier', async ({ page }) => {
    // Déposer un fichier physique sans index BDD → bouton « Indexer ce dossier » visible
    const probeName = `probe_${stamp}.pdf`;
    const probeDir = path.join(STORAGE_DOCS, ...folderA.split('/'));
    fs.mkdirSync(probeDir, { recursive: true });
    fs.copyFileSync(SAMPLE_PDF, path.join(probeDir, probeName));

    await page.goto(`${BASE}/documents?path=${encodeURIComponent(folderA)}`, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle');
    const indexBtn = page.locator('#index-folder-btn');
    await expect(indexBtn).toBeVisible({ timeout: 15_000 });

    const indexPromise = page.waitForResponse(
      (r) => r.url().includes('/api/folders/index') && r.request().method() === 'POST',
      { timeout: 60_000 },
    );
    await indexBtn.click();
    const indexResp = await indexPromise;
    expect(indexResp.ok(), `index HTTP ${indexResp.status()}`).toBeTruthy();
    const indexJson = await indexResp.json();
    expect(indexJson.success, indexJson.error ?? 'index failed').toBe(true);
  });

  test('F-LIB-05: renommer dossier (menu contextuel)', async ({ page }) => {
    const newName = `pw_lib_a_ren_${stamp}`;
    await openFolderContextMenu(page, folderA);
    await page.locator('#folder-context-menu button[data-action="rename"]').click();
    await expect(page.locator('#rename-modal')).toBeVisible();
    await page.fill('#rename-input', newName);

    const renamePromise = page.waitForResponse(
      (r) => r.url().includes('/api/folders/rename') && r.request().method() === 'POST',
      { timeout: 30_000 },
    );
    await page.locator('#rename-modal button:has-text("Renommer")').click();
    const renameResp = await renamePromise;
    expect(renameResp.ok(), `rename HTTP ${renameResp.status()}`).toBeTruthy();
    const renameJson = await renameResp.json();
    expect(renameJson.success, renameJson.error ?? 'rename failed').toBe(true);
    renamedPath = renameJson.new_path ?? `eval/${newName}`;
    folderA = renamedPath;
  });

  test('F-LIB-06: déplacer dossier sous destination', async ({ page }) => {
    await openFolderContextMenu(page, folderA);
    await page.locator('#folder-context-menu button[data-action="move"]').click();
    await expect(page.locator('#move-modal')).toBeVisible();

    const destItem = page.locator(`#move-tree .move-target[data-path="${folderB}"]`).first();
    await expect(destItem).toBeVisible({ timeout: 10_000 });
    await destItem.click();
    await expect(page.locator('#move-confirm-btn')).toBeEnabled();

    const movePromise = page.waitForResponse(
      (r) => r.url().includes('/api/folders/move') && r.request().method() === 'POST',
      { timeout: 30_000 },
    );
    await page.locator('#move-confirm-btn').click();
    const moveResp = await movePromise;
    expect(moveResp.ok(), `move HTTP ${moveResp.status()}`).toBeTruthy();
    const moveJson = await moveResp.json();
    expect(moveJson.success, moveJson.error ?? 'move failed').toBe(true);
    folderA = moveJson.new_path ?? `${folderB}/${folderA.split('/').pop()}`;
  });

  test('F-LIB-07: supprimer dossier (corbeille)', async ({ page }) => {
    await openFolderContextMenu(page, folderA);
    await page.locator('#folder-context-menu button[data-action="delete"]').click();
    await expect(page.locator('#delete-modal')).toBeVisible();

    const deletePromise = page.waitForResponse(
      (r) => r.url().includes('/api/folders/delete') && r.request().method() === 'POST',
      { timeout: 30_000 },
    );
    await page.locator('#delete-modal button:has-text("Supprimer")').click();
    const deleteResp = await deletePromise;
    expect(deleteResp.ok(), `delete HTTP ${deleteResp.status()}`).toBeTruthy();
    const deleteJson = await deleteResp.json();
    expect(deleteJson.success, deleteJson.error ?? 'delete failed').toBe(true);

    // Nettoyer le dossier destination B
    const api = page.context().request;
    const delB = await api.post(`${BASE}/api/folders/delete`, {
      headers: { 'Content-Type': 'application/json' },
      data: { path: folderB },
    });
    expect(delB.ok()).toBeTruthy();
  });
});

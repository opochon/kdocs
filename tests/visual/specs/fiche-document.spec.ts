import { test, expect, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { BASE } from './helpers/personas';
import { expectNoPhpError } from './helpers/page-guards';

const SHOTS = path.join(__dirname, '..', 'shots');
const DOC_PATH = 'eval/lot-original';
fs.mkdirSync(SHOTS, { recursive: true });

async function openPreview(page: Page, docId: number, folderPath = DOC_PATH) {
  await page.goto(`${BASE}/documents?open=${docId}&path=${encodeURIComponent(folderPath)}`, {
    waitUntil: 'domcontentloaded',
  });
  await expect(page.locator('#preview-type-select')).toBeVisible({ timeout: 15_000 });
  await expectNoPhpError(page);
}

async function switchTab(page: Page, tab: string, panelId: string) {
  await page.locator('#preview-tabs button').filter({ hasText: new RegExp(tab, 'i') }).first().click();
  const panel = page.locator(`#${panelId}`);
  await expect(panel).toBeVisible();
  await expect(panel).not.toHaveClass(/hidden/);
}

async function pickEvalDocument(api: Page['context']['request']): Promise<number> {
  const resp = await api.get(`${BASE}/api/folders/documents?path=${encodeURIComponent(DOC_PATH)}`);
  expect(resp.ok()).toBeTruthy();
  const json = await resp.json();
  const doc = (json.documents as any[])?.find((d) => d?.id);
  expect(doc?.id, 'aucun document indexé dans eval/lot-original — lancer eval-full').toBeTruthy();
  return Number(doc.id);
}

const DISPOSABLE_FOLDER = 'eval/lot-doc-c';

async function seedDisposableDoc(): Promise<number> {
  const { execFile } = await import('node:child_process');
  const { promisify } = await import('node:util');
  const execFileAsync = promisify(execFile);
  const repoRoot = path.resolve(__dirname, '..', '..', '..');
  const { stdout } = await execFileAsync('php', [path.join(repoRoot, 'tools', 'seed-lot-c-doc.php')], {
    cwd: repoRoot,
    timeout: 15_000,
  });
  const parsed = JSON.parse(stdout.trim()) as { id?: number };
  expect(parsed.id, stdout).toBeTruthy();
  return Number(parsed.id);
}

/**
 * Lot C — Fiche document (modale preview).
 */
test.describe('fiche-document (Lot C)', () => {
  test.describe.configure({ mode: 'serial' });
  test.setTimeout(120_000);

  let docId = 0;
  let disposableId = 0;

  test.beforeAll(async ({ browser }) => {
    test.setTimeout(60_000);
    const ctx = await browser.newContext({
      storageState: path.join(__dirname, '..', 'storageState.json'),
    });
    docId = await pickEvalDocument(ctx.request);
    disposableId = await seedDisposableDoc();
    await ctx.close();
  });

  test('F-DOC-08: onglets fiche (Détails, Notes, Contenu, Info, Historique, Versions)', async ({ page }) => {
    await openPreview(page, docId);
    await switchTab(page, 'Détails', 'preview-tab-details');
    await switchTab(page, 'Notes', 'preview-tab-notes');
    await switchTab(page, 'Contenu', 'preview-tab-content');
    await switchTab(page, 'Info', 'preview-tab-info');
    await switchTab(page, 'Historique', 'preview-tab-history');
    const versionsTab = page.locator('#preview-tabs button', { hasText: 'Versions' });
    if (await versionsTab.isVisible()) {
      await versionsTab.click();
      await expect(page.locator('#preview-tab-versions')).toBeVisible();
    }
    await page.screenshot({ path: path.join(SHOTS, 'fiche-doc-tabs.png'), fullPage: true });
  });

  test('F-DOC-09: ajouter une note', async ({ page }) => {
    const api = page.context().request;
    const noteText = `note_lot_c_${Date.now()}`;
    await openPreview(page, docId);
    await page.locator('#preview-tabs button').filter({ hasText: 'Notes' }).first().click();
    const noteInput = page.locator('#preview-new-note');
    await expect(noteInput).toBeVisible();

    const notePromise = page.waitForResponse(
      (r) => r.url().includes(`/api/documents/${docId}/notes`) && r.request().method() === 'POST',
      { timeout: 15_000 },
    );
    await noteInput.fill(noteText);
    await noteInput.press('Enter');
    const noteResp = await notePromise;
    expect(noteResp.ok(), `notes POST HTTP ${noteResp.status()}`).toBeTruthy();

    const showJson = await (await api.get(`${BASE}/api/documents/${docId}`)).json();
    const notes: any[] = showJson.data?.notes ?? showJson.notes ?? [];
    expect(notes.some((n) => String(n.note ?? n.content ?? '').includes(noteText))).toBe(true);
  });

  test('F-DOC-06: télécharger le document', async ({ page }) => {
    await openPreview(page, docId);
    const downloadHref = await page.locator('#preview-download-btn').getAttribute('href');
    expect(downloadHref, 'lien téléchargement absent').toBeTruthy();
    const origin = `http://${process.env.KDOCS_HOST ?? '127.0.0.1'}:${process.env.KDOCS_PORT ?? '8765'}`;
    const downloadUrl = downloadHref!.startsWith('http') ? downloadHref! : `${origin}${downloadHref}`;
    const resp = await page.request.get(downloadUrl);
    expect(resp.status(), 'download HTTP').toBeLessThan(400);
    expect(Number(resp.headers()['content-length'] ?? 1)).toBeGreaterThan(0);
  });

  test('F-DOC-05: soumettre validation (API — pas de bouton UI dédié)', async ({ page }) => {
    const api = page.context().request;
    await openPreview(page, disposableId, DISPOSABLE_FOLDER);
    const submitResp = await api.post(`${BASE}/api/validation/${disposableId}/submit`, {
      headers: { 'Content-Type': 'application/json' },
      data: {},
    });
    expect(submitResp.ok(), `submit HTTP ${submitResp.status()}`).toBeTruthy();
    const submitJson = await submitResp.json();
    expect(submitJson.success, JSON.stringify(submitJson)).toBe(true);
    const showJson = await (await api.get(`${BASE}/api/documents/${disposableId}`)).json();
    const status = showJson.data?.validation_status ?? showJson.validation_status;
    expect(status, 'validation_status après submit').toBe('pending');
  });

  test('F-DOC-07: supprimer document (doc jetable)', async ({ page }) => {
    page.on('dialog', (d) => d.accept());
    await openPreview(page, disposableId, DISPOSABLE_FOLDER);
    const deletePromise = page.waitForResponse(
      (r) => r.url().includes(`/api/documents/${disposableId}`) && r.request().method() === 'DELETE',
      { timeout: 15_000 },
    );
    await page.locator('#preview-delete-btn').click();
    expect((await deletePromise).ok()).toBeTruthy();
  });

  test('F-DOC-03: retraiter OCR', async ({ page }) => {
    page.on('dialog', (d) => d.accept());
    await openPreview(page, docId);
    const ocrPromise = page.waitForResponse(
      (r) => r.url().includes(`/api/documents/${docId}/ocr`) && r.request().method() === 'POST',
      { timeout: 90_000 },
    );
    await page.locator('#preview-reprocess-btn').click();
    const ocrResp = await ocrPromise;
    expect(ocrResp.ok(), `ocr HTTP ${ocrResp.status()}`).toBeTruthy();
    const ocrJson = await ocrResp.json();
    expect(ocrJson.success, ocrJson.message ?? ocrJson.error ?? 'ocr failed').toBe(true);
  });
});

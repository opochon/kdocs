import { test, expect, type Page } from '@playwright/test';
import path from 'node:path';
import { BASE } from './helpers/personas';
import { expectNoPhpError } from './helpers/page-guards';

const SHOTS = path.join(__dirname, '..', 'shots');

async function seedValidationDoc(): Promise<number> {
  const { execFile } = await import('node:child_process');
  const { promisify } = await import('node:util');
  const execFileAsync = promisify(execFile);
  const repoRoot = path.resolve(__dirname, '..', '..', '..');
  const { stdout } = await execFileAsync('php', [path.join(repoRoot, 'tools', 'seed-lot-d-validation.php')], {
    cwd: repoRoot,
    timeout: 15_000,
  });
  const parsed = JSON.parse(stdout.trim()) as { id?: number };
  expect(parsed.id, stdout).toBeTruthy();
  return Number(parsed.id);
}

/**
 * Lot D — Recherche avancée, sémantique, tâches, consume admin.
 * Seeds PHP uniquement (pas d'upload OCR synchrone).
 */
test.describe('search-tasks (Lot D)', () => {
  test.describe.configure({ mode: 'serial' });
  test.setTimeout(90_000);

  let pendingDocId = 0;

  test.beforeAll(async () => {
    pendingDocId = await seedValidationDoc();
  });

  test('F-SEARCH-02: recherche avancée avec filtres', async ({ page }) => {
    await page.goto(`${BASE}/search`, { waitUntil: 'domcontentloaded' });
    await expectNoPhpError(page);

    const qInput = page.locator('#search-q');
    await expect(qInput).toBeVisible();
    await expect(page.locator('#search-type')).toBeVisible();
    await expect(page.locator('#search-correspondent')).toBeVisible();
    await expect(page.locator('#search-scope')).toBeVisible();

    await qInput.click();
    await qInput.fill('tribunal');
    await expect(qInput).toHaveValue('tribunal');
    await page.selectOption('#search-scope', 'content');

    await page.locator('form[action*="search"] button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page).toHaveURL(/q=tribunal/i);
    await expect(page).toHaveURL(/scope=content/i);

    await expectNoPhpError(page);
    const bodyText = await page.locator('main').innerText();
    expect(bodyText, 'résultats recherche').toMatch(/résultat/i);
    expect(bodyText).not.toMatch(/Aucun document ne correspond/);
    await expect(page.locator('main a[href*="/documents/"]').first()).toBeVisible();
    await page.screenshot({ path: path.join(SHOTS, 'search-advanced.png'), fullPage: true });
  });

  test('F-SEARCH-03: sémantique / hybride (API — route vivante)', async ({ page }) => {
    const api = page.context().request;

    const statusResp = await api.get(`${BASE}/api/semantic-search/status`);
    expect(statusResp.ok(), `semantic status HTTP ${statusResp.status()}`).toBeTruthy();
    const statusJson = await statusResp.json();
    expect(statusJson.success, JSON.stringify(statusJson)).toBe(true);
    expect(statusJson.data, JSON.stringify(statusJson)).toBeTruthy();

    const semResp = await api.post(`${BASE}/api/search/semantic`, {
      headers: { 'Content-Type': 'application/json' },
      data: { query: 'tribunal', limit: 5 },
    });
    expect(
      [200, 503].includes(semResp.status()),
      `semantic search HTTP ${semResp.status()} — attendu 200 ou 503 (Qdrant off), pas 500`,
    ).toBe(true);

    const hybridResp = await api.post(`${BASE}/api/search/hybrid`, {
      headers: { 'Content-Type': 'application/json' },
      data: { query: 'tribunal', limit: 5, semantic_weight: 0.5 },
    });
    expect(
      [200, 503].includes(hybridResp.status()),
      `hybrid search HTTP ${hybridResp.status()} — attendu 200 ou 503, pas 500`,
    ).toBe(true);
  });

  test('F-TASK-01: liste tâches (/mes-taches + API)', async ({ page }) => {
    const api = page.context().request;
    const tasksResp = await api.get(`${BASE}/api/tasks`);
    expect(tasksResp.ok(), `tasks HTTP ${tasksResp.status()}`).toBeTruthy();
    const tasksJson = await tasksResp.json();
    expect(tasksJson.success, JSON.stringify(tasksJson)).toBe(true);
    expect(Array.isArray(tasksJson.tasks)).toBe(true);
    expect(tasksJson.counts).toBeTruthy();

    await page.goto(`${BASE}/mes-taches`, { waitUntil: 'domcontentloaded' });
    await expectNoPhpError(page);
    await expect(page.locator('main h1')).toContainText(/traiter/i);
    await expect(page.locator('nav[aria-label="Tabs"]')).toContainText('Toutes');
    await expect(page.locator('nav[aria-label="Tabs"]')).toContainText(/valider/i);
  });

  test('F-TASK-02: approuver depuis tâche validation', async ({ browser }) => {
    const ctx = await browser.newContext();
    const loginResp = await ctx.request.post(`${BASE}/login`, {
      form: { username: 'eval_employeur', password: '' },
    });
    expect(loginResp.status(), 'login employeur').toBeLessThan(400);

    const page = await ctx.newPage();
    page.on('dialog', (d) => d.accept());

    await page.goto(`${BASE}/mes-taches?tab=validation`, { waitUntil: 'domcontentloaded' });
    await expectNoPhpError(page);

    const approveBtn = page.locator(`button[onclick="approveDocument(${pendingDocId})"]`).first();
    await expect(approveBtn, 'tâche validation seed absente').toBeVisible({ timeout: 15_000 });

    const approvePromise = page.waitForResponse(
      (r) => r.url().includes(`/api/validation/${pendingDocId}/approve`) && r.request().method() === 'POST',
      { timeout: 15_000 },
    );
    await approveBtn.click();
    const approveResp = await approvePromise;
    expect(approveResp.ok(), `approve HTTP ${approveResp.status()}`).toBeTruthy();

    await page.waitForLoadState('domcontentloaded');
    const showResp = await ctx.request.get(`${BASE}/api/documents/${pendingDocId}`);
    const showJson = await showResp.json();
    const status = showJson.data?.validation_status ?? showJson.validation_status;
    expect(status, 'validation_status après approve').toBe('approved');
    await ctx.close();
  });

  test('F-IMP-02: consume admin — page + scan', async ({ page }) => {
    await page.goto(`${BASE}/admin/consume`, { waitUntil: 'domcontentloaded' });
    await expectNoPhpError(page);
    await expect(page.locator('main h1, .max-w-7xl h1').first()).toContainText(/Validation des Documents/i);

    const scanBtn = page.locator('form[action*="consume/scan"] button[type="submit"]');
    await expect(scanBtn).toBeVisible();

    await Promise.all([
      page.waitForURL(/\/admin\/consume/, { timeout: 30_000 }),
      scanBtn.click(),
    ]);
    await expectNoPhpError(page);
    await page.screenshot({ path: path.join(SHOTS, 'admin-consume-scan.png'), fullPage: true });
  });
});

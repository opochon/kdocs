import { test, expect } from '@playwright/test';
import path from 'node:path';
import { REDX_EXPERT, BASE } from './helpers/personas';
import { loginAs } from './helpers/auth';

const SHOTS = path.join(__dirname, '..', 'shots');

/**
 * Workflow identification documentaire (sans WinBiz).
 * Oracle : types ECM assignables en UI + persistance métadonnées via API.
 */
test.describe('workflow-doc-identification', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('type ECM sélectionnable en UI + persistance API document_type_id', async ({ page }) => {
    test.setTimeout(60_000);
    await loginAs(page, REDX_EXPERT.username);
    const api = page.context().request;

    const docsResp = await api.get(`${BASE}/api/folders/documents?path=${encodeURIComponent('eval/lot-original')}`);
    expect(docsResp.ok()).toBeTruthy();
    const docsJson = await docsResp.json();
    const doc = (docsJson.documents as any[])?.[0];
    expect(doc?.id).toBeTruthy();
    const docId = doc.id as number;

    const typesResp = await api.get(`${BASE}/api/document-types`);
    const typesJson = await typesResp.json();
    const types: any[] = typesJson.data ?? [];
    const contrat = types.find((t) => (t.label ?? '').toLowerCase() === 'contrat');
    expect(contrat?.id, 'type Contrat absent — eval-full ensureDocumentTypes').toBeTruthy();

    await page.goto(`${BASE}/documents?open=${docId}&path=${encodeURIComponent('eval/lot-original')}`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForLoadState('networkidle');

    const typeSelect = page.locator('#preview-type-select');
    await expect(typeSelect).toBeVisible({ timeout: 15_000 });

    // Oracle UI : l'expert identifie le document et sélectionne le type Contrat.
    await typeSelect.selectOption(String(contrat.id));
    await expect(typeSelect).toHaveValue(String(contrat.id));

    // Oracle persistance : save preview (PUT /api/documents/{id} — parcours persona réel).
    const saveBtn = page.locator('[title="Enregistrer les modifications"]').first();
    const savePromise = page.waitForResponse(
      (r) =>
        r.url().includes(`/api/documents/${docId}`) &&
        r.request().method() === 'PUT' &&
        !r.url().includes('/type'),
      { timeout: 15_000 },
    );
    await saveBtn.click();
    const saveResp = await savePromise;
    expect(saveResp.ok(), `save HTTP ${saveResp.status()}`).toBeTruthy();

    const showResp = await api.get(`${BASE}/api/documents/${docId}`);
    expect(showResp.ok()).toBeTruthy();
    const showJson = await showResp.json();
    const savedTypeId = showJson.data?.document_type_id ?? showJson.document_type_id;
    expect(String(savedTypeId)).toBe(String(contrat.id));

    await page.screenshot({ path: path.join(SHOTS, 'workflow-doc-type-identification.png'), fullPage: true });
  });
});

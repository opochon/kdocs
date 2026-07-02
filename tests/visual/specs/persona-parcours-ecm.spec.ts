import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { REDX_EXPERT, BASE } from './helpers/personas';
import { loginAs } from './helpers/auth';
import { expectNoPhpError } from './helpers/page-guards';
import { infomaniakReady } from './helpers/infomaniak-guard';

const SHOTS = path.join(__dirname, '..', 'shots');
fs.mkdirSync(SHOTS, { recursive: true });

const SAMPLE_PDF = path.resolve(__dirname, '..', '..', 'samples', 'test.pdf');

/**
 * Lot A — Parcours ECM (oracles stricts).
 * Prérequis : personas/types via eval-full (--no-ocr) avant run-passe-lot-a.bat.
 */
test.describe('persona-parcours-ecm (Lot A)', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('Lot A — ingérer → classer → analyser (eval_redx_expert)', async ({ page }) => {
    test.setTimeout(300_000);

    const api = page.context().request;
    const stamp = Date.now();
    const title = `parcours_ecm_${stamp}`;
    let docId: number | null = null;

    const aiReady = await infomaniakReady(api);
    test.skip(!aiReady, 'Infomaniak indisponible — F-DOC-02 non testable (oracle strict)');

    try {
      await loginAs(page, REDX_EXPERT.username);

      // F-IMP-01 : upload formulaire
      await page.goto(`${BASE}/documents/upload`, { waitUntil: 'domcontentloaded' });
      await expectNoPhpError(page);
      await page.setInputFiles('#file', SAMPLE_PDF);
      await page.fill('#title', title);

      const typesResp = await api.get(`${BASE}/api/document-types`);
      const facture = ((await typesResp.json()).data as any[] ?? []).find(
        (t) => (t.label ?? '').toLowerCase() === 'facture',
      );
      expect(facture?.id, 'type Facture absent — eval-full ensureDocumentTypes').toBeTruthy();
      await page.selectOption('#document_type_id', String(facture!.id));

      await Promise.all([
        page.waitForURL(/\/documents(\?|$)/, { timeout: 60_000 }),
        page.click('button[type="submit"]'),
      ]);
      await expectNoPhpError(page);

      await page.fill('#search-input', title);
      await page.press('#search-input', 'Enter');
      await page.waitForLoadState('networkidle');
      const card = page.locator('.document-card, [data-doc-id]').filter({ hasText: title }).first();
      await expect(card, 'document non visible après upload formulaire').toBeVisible({ timeout: 15_000 });

      docId = Number(await card.getAttribute('data-doc-id'));
      if (!docId) {
        const docsJson = await (await api.get(`${BASE}/api/folders/documents?path=`)).json();
        docId = (docsJson.documents as any[] ?? []).find((d) => (d.title ?? '').includes(title))?.id ?? null;
      }
      expect(docId, 'id document introuvable après upload').toBeTruthy();
      const id = docId as number;

      // F-LIB-02 : fiche
      await page.goto(`${BASE}/documents?open=${id}`, { waitUntil: 'domcontentloaded' });
      const typeSelect = page.locator('#preview-type-select');
      await expect(typeSelect).toBeVisible({ timeout: 15_000 });
      await expectNoPhpError(page);

      const typesResp2 = await api.get(`${BASE}/api/document-types`);
      const contrat = ((await typesResp2.json()).data as any[] ?? []).find(
        (t) => (t.label ?? '').toLowerCase() === 'contrat',
      );
      expect(contrat?.id).toBeTruthy();

      // F-DOC-02 : classify-ai (obligatoire si aiReady)
      const aiBtn = page.locator('#ai-suggest-btn');
      await expect(aiBtn).toBeVisible();
      const classifyPromise = page.waitForResponse(
        (r) => r.url().includes(`/api/documents/${id}/classify-ai`) && r.request().method() === 'POST',
        { timeout: 180_000 },
      );
      await aiBtn.click();
      const classifyResp = await classifyPromise;
      expect(classifyResp, 'classify-ai obligatoire (oracle F-DOC-02)').toBeTruthy();
      expect(classifyResp!.status(), 'classify-ai route morte').toBeLessThan(500);
      console.log(`[parcours] classify-ai HTTP ${classifyResp!.status()}`);

      // F-DOC-01 : classer + save UI uniquement
      await typeSelect.selectOption(String(contrat!.id));
      await page.locator('#preview-title-input').fill(`${title} — classé`);

      const saveBtn = page.locator('[title="Enregistrer les modifications"]').first();
      const savePromise = page.waitForResponse(
        (r) =>
          r.url().includes(`/api/documents/${id}`) &&
          r.request().method() === 'PUT' &&
          !r.url().includes('/type'),
        { timeout: 60_000 },
      );
      await saveBtn.click();
      const saveResp = await savePromise;
      expect(saveResp?.ok(), `save UI PUT obligatoire (F-DOC-01), HTTP ${saveResp?.status()}`).toBeTruthy();

      const showJson = await (await api.get(`${BASE}/api/documents/${id}`)).json();
      const savedTypeId = showJson.data?.document_type_id ?? showJson.document_type_id;
      expect(String(savedTypeId)).toBe(String(contrat!.id));

      await page.screenshot({ path: path.join(SHOTS, 'parcours-ecm-lot-a.png'), fullPage: true });
    } finally {
      if (docId !== null) {
        try {
          await api.delete(`${BASE}/api/documents/${docId}`);
        } catch {
          /* best-effort */
        }
      }
    }
  });
});

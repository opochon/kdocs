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
 * Lot A — Parcours ECM complet (persona expert REDX).
 * Oracle séquentiel : ingérer → ouvrir fiche → classer (type) → analyser (IA) → retrouver.
 *
 * Discipline : lot rouge → corriger → re-lancer ce fichier avant lot B.
 * Prérequis : php tools/eval-full.php --no-ocr (fixtures personas + types ECM).
 */
test.describe('persona-parcours-ecm (Lot A)', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('Lot A — ingérer → classer → analyser (eval_redx_expert)', async ({ page }) => {
    test.setTimeout(240_000);

    const api = page.context().request;
    const stamp = Date.now();
    const title = `parcours_ecm_${stamp}`;
    let docId: number | null = null;

    const aiReady = await infomaniakReady(api);

    try {
      // --- F-AUTH-01 + persona ---
      await loginAs(page, REDX_EXPERT.username);

      // --- F-IMP-01 : upload via page formulaire (saisie écran réelle) ---
      await page.goto(`${BASE}/documents/upload`, { waitUntil: 'domcontentloaded' });
      await expectNoPhpError(page);
      await expect(page.locator('#file')).toBeVisible();

      await page.setInputFiles('#file', SAMPLE_PDF);
      await page.fill('#title', title);

      const typesResp = await api.get(`${BASE}/api/document-types`);
      const typesJson = await typesResp.json();
      const facture = (typesJson.data as any[] ?? []).find((t) => (t.label ?? '').toLowerCase() === 'facture');
      expect(facture?.id, 'type Facture absent — eval-full ensureDocumentTypes').toBeTruthy();
      await page.selectOption('#document_type_id', String(facture.id));

      await Promise.all([
        page.waitForURL(/\/documents(\?|$)/, { timeout: 60_000 }),
        page.click('button[type="submit"]'),
      ]);
      await expectNoPhpError(page);

      // Retrouver le doc créé (recherche = F-SEARCH-01)
      await page.fill('#search-input', title);
      await page.press('#search-input', 'Enter');
      await page.waitForLoadState('networkidle');
      const card = page.locator('.document-card, [data-doc-id]').filter({ hasText: title }).first();
      await expect(card, 'document non visible après upload formulaire').toBeVisible({ timeout: 15_000 });

      const docIdAttr = await card.getAttribute('data-doc-id');
      docId = docIdAttr ? Number(docIdAttr) : null;
      if (!docId) {
        const docsResp = await api.get(`${BASE}/api/folders/documents?path=`);
        const docsJson = await docsResp.json();
        const found = (docsJson.documents as any[] ?? []).find((d) => (d.title ?? '').includes(title));
        docId = found?.id ?? null;
      }
      expect(docId, 'id document introuvable après upload').toBeTruthy();
      const id = docId as number;

      // --- F-LIB-02 : ouvrir fiche ---
      await page.goto(`${BASE}/documents?open=${id}`, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('#preview-type-select')).toBeVisible({ timeout: 15_000 });
      await expectNoPhpError(page);

      // --- F-DOC-01 : classer (type Contrat) + save ---
      const typesResp2 = await api.get(`${BASE}/api/document-types`);
      const contrat = ((await typesResp2.json()).data as any[] ?? []).find((t) => (t.label ?? '').toLowerCase() === 'contrat');
      expect(contrat?.id).toBeTruthy();

      const typeSelect = page.locator('#preview-type-select');
      await typeSelect.selectOption(String(contrat.id));
      await expect(typeSelect).toHaveValue(String(contrat.id));

      const saveBtn = page.locator('[title="Enregistrer les modifications"]').first();
      const savePromise = page.waitForResponse(
        (r) =>
          r.url().includes(`/api/documents/${id}`) &&
          r.request().method() === 'PUT' &&
          !r.url().includes('/type'),
        { timeout: 15_000 },
      );
      await saveBtn.click();
      expect((await savePromise).ok()).toBeTruthy();

      const showResp = await api.get(`${BASE}/api/documents/${id}`);
      const showJson = await showResp.json();
      const savedTypeId = showJson.data?.document_type_id ?? showJson.document_type_id;
      expect(String(savedTypeId)).toBe(String(contrat.id));

      // --- F-DOC-02 : analyser (classify-ai) ---
      const aiBtn = page.locator('#ai-suggest-btn');
      await expect(aiBtn).toBeVisible();
      if (aiReady) {
        const classifyPromise = page.waitForResponse(
          (r) => r.url().includes(`/api/documents/${id}/classify-ai`) && r.request().method() === 'POST',
          { timeout: 120_000 },
        ).catch(() => null);
        await aiBtn.click();
        const classifyResp = await classifyPromise;
        expect(classifyResp, 'route classify-ai morte ou timeout').not.toBeNull();
      }

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

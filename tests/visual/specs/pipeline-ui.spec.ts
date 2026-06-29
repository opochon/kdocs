import { test, expect, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

// Test UI pipeline : upload (ingestion) → OCR → suggestion IA (classify-ai) →
// sauvegarde (PUT) → recherche. Déroule le chemin utilisateur réel dans le navigateur.
//
// Ces fonctions NE sont PAS rôle-dépendantes (aucun requireRole sur upload/classify-ai/
// update) : on les teste ici en session root. La couverture rôle-dépendante (droits de
// validation) est dans specs/persona.spec.ts.

const BASE_PATH = process.env.KDOCS_BASE_PATH ?? '/kdocs';
const HOST = process.env.KDOCS_HOST ?? '127.0.0.1';
const PORT = process.env.KDOCS_PORT ?? '8765';
const BASE = `http://${HOST}:${PORT}${BASE_PATH}`;
const TARGET_FOLDER = 'eval/lot-ui';

const SHOTS = path.join(__dirname, '..', 'shots');
fs.mkdirSync(SHOTS, { recursive: true });

const SAMPLE_PDF = path.resolve(__dirname, '..', '..', 'samples', 'test.pdf');

const ERROR_MARKERS = [
  'Fatal error', 'Parse error', 'Uncaught', 'Whoops',
  'PDOException', 'Call to undefined', 'syntax error, unexpected',
];

async function expectNoPhpError(page: Page) {
  const html = await page.content();
  for (const marker of ERROR_MARKERS) {
    expect(html, `marqueur "${marker}"`).not.toContain(marker);
  }
}

test('UI pipeline : upload → suggestion IA → sauvegarde → recherche', async ({ page }) => {
  test.setTimeout(120_000);

  const api = page.context().request;
  let docId: number | null = null;

  try {
    // --- 1. Ingestion via l'endpoint d'upload (même appel que la UI uploadFile()) ---
    const stamp = Date.now();
    const filename = `facture_pipeline_ui_${stamp}.pdf`;
    const expectedTitle = `facture_pipeline_ui_${stamp}`;
    const buf = fs.readFileSync(SAMPLE_PDF);

    const uploadResp = await api.post(`${BASE}/api/documents/upload`, {
      multipart: {
        'files[]': { name: filename, mimeType: 'application/pdf', buffer: buf },
        folder: TARGET_FOLDER,
      },
    });
    expect(uploadResp.ok(), `upload HTTP ${uploadResp.status()}`).toBeTruthy();
    const uploadJson = await uploadResp.json();
    expect(uploadJson.success).toBe(true);
    const uploaded = (uploadJson.results as any[]).find((r) => r.success);
    expect(uploaded, 'aucun résultat upload réussi').toBeTruthy();
    docId = uploaded.id;
    expect(docId, 'id document manquant').toBeTruthy();
    const id = docId as number;
    console.log(`[pipeline] uploadé doc #${id} (${filename})`);

    // --- 2. Ouverture de la fiche (preview) dans la UI ---
    await page.goto(`${BASE}/documents?open=${id}&path=${encodeURIComponent(TARGET_FOLDER)}`, {
      waitUntil: 'domcontentloaded',
    });
    await expectNoPhpError(page);

    // La modale s'ouvre via ?open=. Attendre le bouton "Suggestion : analyser".
    const aiBtn = page.locator('#ai-suggest-btn');
    await expect(aiBtn).toBeVisible({ timeout: 10_000 });

    // --- 3. Suggestion IA (classify-ai) ---
    const classifyPromise = page.waitForResponse(
      (r) => r.url().includes(`/api/documents/${id}/classify-ai`) && r.request().method() === 'POST',
      { timeout: 60_000 },
    ).catch(() => null);
    await aiBtn.click();
    const classifyResp = await classifyPromise;
    // Le bouton est câblé : l'endpoint répond (succès OU échec IA provider, mais route vivante).
    expect(classifyResp, 'aucune réponse classify-ai').not.toBeNull();
    console.log(`[pipeline] classify-ai HTTP ${classifyResp!.status()}`);

    // --- 4. Type : si l'IA n'a rien proposé, on sélectionne « Facture » manuellement ---
    const typeSelect = page.locator('#preview-type-select');
    await expect(typeSelect).toBeVisible();
    let typeValue = await typeSelect.inputValue();
    if (!typeValue || typeValue === '') {
      await typeSelect.selectOption({ label: 'Facture' });
      typeValue = await typeSelect.inputValue();
    }
    expect(typeValue, 'type toujours vide après sélection').not.toBe('');

    // --- 5. Sauvegarde (PUT /api/documents/{id}) ---
    const saveBtn = page.locator('[title="Enregistrer les modifications"]').first();
    const savePromise = page.waitForResponse(
      (r) => r.url().includes(`/api/documents/${id}`) && r.request().method() === 'PUT'
        && !r.url().includes('/type') && !r.url().includes('/correspondent') && !r.url().includes('/fields'),
      { timeout: 15_000 },
    );
    await saveBtn.click();
    const saveResp = await savePromise;
    expect(saveResp.ok(), `save HTTP ${saveResp.status()}`).toBeTruthy();
    console.log(`[pipeline] save PUT HTTP ${saveResp.status()}`);

    // --- 6. Vérification : le type est persisté en base (via API document par id) ---
    // NB : on n'utilise pas le listing de dossier car apiUpload ne renseigne pas
    // relative_path (bug produit : le doc uploadé n'est pas rangé dans le dossier).
    const showResp = await api.get(`${BASE}/api/documents/${id}`);
    expect(showResp.ok(), `show HTTP ${showResp.status()}`).toBeTruthy();
    const showJson = await showResp.json();
    const savedDoc = showJson.data ?? showJson;
    expect(savedDoc.document_type_id, 'type non persisté après save').toBeTruthy();
    console.log(`[pipeline] type persisté : id=${savedDoc.document_type_id} label=${savedDoc.document_type_label ?? '?'}`);

    // Capture de la fiche.
    await page.screenshot({ path: path.join(SHOTS, 'pipeline-preview.png'), fullPage: true });

    // --- 7. Recherche : retrouver le document par son titre ---
    await page.goto(`${BASE}/documents`, { waitUntil: 'domcontentloaded' });
    await expectNoPhpError(page);
    await page.fill('#search-input', expectedTitle);
    await page.press('#search-input', 'Enter');
    await page.waitForLoadState('networkidle');

    const card = page.locator('.document-card, [data-doc-id]').filter({ hasText: expectedTitle }).first();
    await expect(card, 'document non retrouvé par recherche').toBeVisible({ timeout: 10_000 });

    await page.screenshot({ path: path.join(SHOTS, 'pipeline-search.png'), fullPage: true });
  } finally {
    // Auto-nettoyage : supprimer le document de test pour ne pas polluer la base
    // (un doc frais en tête de liste casse smq-versions.spec.ts qui suppose docs[0] avec versions).
    if (docId !== null) {
      try { await api.delete(`${BASE}/api/documents/${docId}`); } catch { /* best-effort */ }
    }
  }
});

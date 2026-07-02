import { test, expect, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { infomaniakReady } from './helpers/infomaniak-guard';

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
  test.setTimeout(180_000); // live : upload (OCR synchrone pdftoppm/Tesseract) + classify-ai (Infomaniak ~17-60s)

  const api = page.context().request;
  let docId: number | null = null;

  // Préflight IA : si Infomaniak est lent/ratelimited, on skippe l'étape de suggestion IA
  // (assertion classify-ai) mais on garde upload + sauvegarde + persistance type + recherche
  // (F-LIB-03 relative_path, E2 type persisté) qui ne dépendent pas de l'IA. La logique IA
  // est couverte hermétiquement par PHPUnit (AiCascadeInfomaniakTest).
  const aiReady = await infomaniakReady(api);
  if (!aiReady) {
    console.log('[pipeline] Infomaniak lent/indisponible — étape suggestion IA skipée');
  }

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

    // --- 3. Suggestion IA (classify-ai) — skipuée si Infomaniak lent (préflight) ---
    if (aiReady) {
      const classifyPromise = page.waitForResponse(
        (r) => r.url().includes(`/api/documents/${id}/classify-ai`) && r.request().method() === 'POST',
        { timeout: 120_000 },
      ).catch(() => null);
      await aiBtn.click();
      const classifyResp = await classifyPromise;
      // F-DOC-02 oracle = « route vivante » : l'endpoint répond (succès OU échec IA provider).
      // On ne requiert pas HTTP 200 — juste que la route répond (non null).
      expect(classifyResp, 'aucune réponse classify-ai (route morte ou timeout IA)').not.toBeNull();
      console.log(`[pipeline] classify-ai HTTP ${classifyResp!.status()}`);
    } else {
      console.log('[pipeline] classify-ai skipé (Infomaniak lent)');
    }

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

    // --- 6. Vérification : le doc est rangé dans son dossier (relative_path) + type persisté ---
    // F-LIB-03 : apiUpload fixe désormais relative_path (was NULL -> doc absent du dossier).
    const docsResp = await api.get(`${BASE}/api/folders/documents?path=${encodeURIComponent(TARGET_FOLDER)}`);
    expect(docsResp.ok(), `folders HTTP ${docsResp.status()}`).toBeTruthy();
    const docsJson = await docsResp.json();
    const saved = (docsJson.documents as any[]).find((d) => Number(d.id) === Number(id));
    expect(saved, 'document absent du dossier cible après upload (relative_path non rangé)').toBeTruthy();
    expect(saved.document_type_id, 'type non persisté après save').toBeTruthy();
    console.log(`[pipeline] type persisté : id=${saved.document_type_id} label=${saved.document_type_label ?? '?'}`);

    // E2 : après rechargement de la fiche, le champ Type doit afficher la valeur persistée
    // (et non revenir à « Non défini »). Re-open la modale via ?open=.
    await page.goto(`${BASE}/documents?open=${id}&path=${encodeURIComponent(TARGET_FOLDER)}`, { waitUntil: 'domcontentloaded' });
    const typeSelectReload = page.locator('#preview-type-select');
    await expect(typeSelectReload).toBeVisible({ timeout: 10_000 });
    await expect(typeSelectReload, 'le type affiché revient à Non défini après rechargement')
      .toHaveValue(String(saved.document_type_id));

    // Capture de la fiche.
    await page.screenshot({ path: path.join(SHOTS, 'pipeline-preview.png'), fullPage: true });

    // --- 7. Recherche : retrouver le document dans son dossier cible ---
    await page.goto(`${BASE}/documents?path=${encodeURIComponent(TARGET_FOLDER)}`, { waitUntil: 'domcontentloaded' });
    await expectNoPhpError(page);
    await page.fill('#search-input', expectedTitle);
    await page.press('#search-input', 'Enter');
    await page.waitForLoadState('networkidle');

    let card = page.locator('.document-card, [data-doc-id]').filter({ hasText: expectedTitle }).first();
    if (!(await card.isVisible().catch(() => false))) {
      // Fallback : doc rangé dans le dossier sans filtre recherche (indexation fulltext async)
      card = page.locator('.document-card, [data-doc-id]').filter({ has: page.locator(`[data-doc-id="${id}"]`) }).first();
      if (!(await card.isVisible().catch(() => false))) {
        card = page.locator(`[data-doc-id="${id}"]`).first();
      }
    }
    await expect(card, 'document non retrouvé dans le dossier cible').toBeVisible({ timeout: 15_000 });

    await page.screenshot({ path: path.join(SHOTS, 'pipeline-search.png'), fullPage: true });
  } finally {
    // Auto-nettoyage : supprimer le document de test pour ne pas polluer la base
    // (un doc frais en tête de liste casse smq-versions.spec.ts qui suppose docs[0] avec versions).
    if (docId !== null) {
      try { await api.delete(`${BASE}/api/documents/${docId}`); } catch { /* best-effort */ }
    }
  }
});

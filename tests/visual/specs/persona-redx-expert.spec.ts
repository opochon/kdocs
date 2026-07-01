import { test, expect } from '@playwright/test';
import path from 'node:path';
import {
  REDX_EXPERT,
  EXPECTED_DOC_TYPE_LABELS,
  ERROR_MARKERS,
  BASE,
} from './helpers/personas';
import { loginAs } from './helpers/auth';

const SHOTS = path.join(__dirname, '..', 'shots');

/**
 * Persona expert ECM REDX — validation visuelle des fonctions documentaires.
 * Oracle : parcours bibliothèque → fiche → métadonnées identifiables (type, correspondant,
 * date, montant) + badge certitude + pas de promesse UI WinBiz (plugin hors scope).
 *
 * Prérequis : php tools/eval-full.php (crée eval_redx_expert + types ECM).
 */
test.describe('persona-redx-expert', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('login + bibliothèque eval + fiche document sans erreur PHP', async ({ page }) => {
    await loginAs(page, REDX_EXPERT.username);

    const resp = await page.goto(`${BASE}/documents?path=eval/lot-original`, {
      waitUntil: 'domcontentloaded',
    });
    expect(resp!.status()).toBeLessThan(400);
    expect(page.url()).not.toMatch(/\/login\b/);

    const html = await page.content();
    for (const marker of ERROR_MARKERS) {
      expect(html, marker).not.toContain(marker);
    }

    await expect(page.locator('.document-card, [data-doc-id]').first()).toBeVisible({ timeout: 15_000 });
    await page.screenshot({ path: path.join(SHOTS, 'persona-redx-expert-library.png'), fullPage: true });
  });

  test('types documentaires ECM visibles via API (identification)', async ({ page }) => {
    await loginAs(page, REDX_EXPERT.username);
    const api = page.context().request;

    const resp = await api.get(`${BASE}/api/document-types`);
    expect(resp.ok(), `document-types HTTP ${resp.status()}`).toBeTruthy();
    const json = await resp.json();
    const labels: string[] = (json.data ?? json.types ?? json).map((t: any) => t.label ?? t.name);
    if (!labels.length && Array.isArray(json)) {
      labels.push(...json.map((t: any) => t.label));
    }

    for (const expected of EXPECTED_DOC_TYPE_LABELS) {
      expect(labels, `type manquant: ${expected}`).toContain(expected);
    }
  });

  test('fiche preview : champs métadonnées + badge certitude + pas de lien WinBiz sidebar', async ({ page }) => {
    await loginAs(page, REDX_EXPERT.username);
    const api = page.context().request;

    const docsResp = await api.get(`${BASE}/api/folders/documents?path=${encodeURIComponent('eval/lot-original')}`);
    expect(docsResp.ok()).toBeTruthy();
    const docsJson = await docsResp.json();
    const doc = (docsJson.documents as any[])?.[0];
    expect(doc?.id, 'aucun document dans eval/lot-original — lancer eval-full.php').toBeTruthy();

    await page.goto(`${BASE}/documents?open=${doc.id}&path=${encodeURIComponent('eval/lot-original')}`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForLoadState('networkidle');

    // Oracle F-DOC-01 : champs métadonnées visibles.
    await expect(page.locator('#preview-title-input')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('#preview-type-select')).toBeVisible();
    await expect(page.locator('#preview-correspondent-select')).toBeVisible();
    await expect(page.locator('#preview-date-input')).toBeVisible();
    await expect(page.locator('#preview-amount-input')).toBeVisible();
    await expect(page.locator('#ai-suggest-btn')).toBeVisible();
    await expect(page.locator('#ai-confidence-badge')).toBeAttached();

    // Type select contient les types ECM (identification UI).
    const typeSelect = page.locator('#preview-type-select');
    for (const label of EXPECTED_DOC_TYPE_LABELS) {
      await expect(typeSelect.locator('option', { hasText: label })).toHaveCount(1);
    }

    // WinBiz = plugin : pas de lien sidebar /invoices tant que non opérationnel (hors scope).
    const invoicesLink = page.locator('a[href*="/invoices"]');
    await expect(invoicesLink).toHaveCount(0);

    await page.screenshot({ path: path.join(SHOTS, 'persona-redx-expert-preview.png'), fullPage: true });
  });

  test('droits validation facture 6000 CHF (expert peut valider métadonnées)', async ({ page }) => {
    await loginAs(page, REDX_EXPERT.username);
    const api = page.context().request;

    const docsResp = await api.get(`${BASE}/api/folders/documents?path=${encodeURIComponent('eval/lot-original')}`);
    const docsJson = await docsResp.json();
    const facture = (docsJson.documents as any[]).find(
      (d) => d.document_type_label === 'Facture' || d.document_type_code === 'FACTURE',
    ) ?? docsJson.documents?.[0];
    expect(facture).toBeTruthy();

    const canResp = await api.get(`${BASE}/api/validation/${facture.id}/can-validate`);
    expect(canResp.ok()).toBeTruthy();
    const canJson = await canResp.json();
    expect(canJson.can_validate, 'expert REDX doit pouvoir valider (APPROVER)').toBe(true);
  });
});

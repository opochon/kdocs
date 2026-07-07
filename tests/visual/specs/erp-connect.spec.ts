import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

/**
 * ERP Connect — simulation bout-en-bout GED ↔ K-Time (sans WinBiz vivant).
 *
 * Flux : document facture GED (lignes extraites) → panneau /erpconnect/panel
 * → proposition (fournisseur reconnu, dédup, ventilation par ligne)
 * → choix utilisateur sur la ligne inconnue → introduction dans K-Time
 * (brouillon flagué « saisie depuis ged ») → validation humaine côté K-Time
 * (UI, bannière inline) → retour GED : « Bon pour accord » (qui + quand).
 *
 * Prérequis (run-erp-simulation.bat les vérifie) :
 *  - K-Time web live (KTIME_BASE, défaut http://127.0.0.1:8091) + clé ged_api_key
 *  - GED servie avec ERPCONNECT_APP_ENABLED=true + KTIME_URL + KTIME_GED_API_KEY
 *  - MariaDB locale (kdocs + k_time) — le seed tools/erp-sim-seed.php est rejoué
 *    par le spec (idempotent).
 */

const HOST = process.env.KDOCS_HOST ?? '127.0.0.1';
const PORT = process.env.KDOCS_PORT ?? '8765';
const BASE = `http://${HOST}:${PORT}${process.env.KDOCS_BASE_PATH ?? '/kdocs'}`;
const KTIME = process.env.KTIME_BASE ?? 'http://127.0.0.1:8091';
const KTIME_KEY = process.env.KTIME_GED_API_KEY ?? 'ged-dev-key-2026';
const repoRoot = path.resolve(__dirname, '..', '..', '..');
const SHOTS = path.join(__dirname, '..', 'screenshots');

let docId = 0;

test.describe('erp-connect — simulation GED ↔ K-Time', () => {
  test.beforeAll(() => {
    // Lire l'état du seed si présent (écrit par tools/erp-sim-seed.php) : un
    // beforeAll re-exécuté (worker Playwright relancé après échec) ne doit PAS
    // resemer — la purge du seed détruirait le draft créé par le test lui-même.
    const stateFile = path.join(__dirname, '..', '.erp-sim.json');
    if (!fs.existsSync(stateFile)) {
      execSync('php tools/erp-sim-seed.php', { cwd: repoRoot, encoding: 'utf8' });
    }
    docId = JSON.parse(fs.readFileSync(stateFile, 'utf8')).document_id;
    expect(docId, 'seed erp-sim doit fournir document_id').toBeGreaterThan(0);
  });

  test('proposition → introduction → validation K-Time → bon pour accord', async ({ page, browser }) => {
    test.setTimeout(120_000);

    // Préflight K-Time (skip propre si l'environnement n'est pas monté)
    const health = await page.request.get(`${KTIME}/api/ged/health`, {
      headers: { 'X-Api-Key': KTIME_KEY },
      timeout: 5_000,
    }).catch(() => null);
    test.skip(!health || !health.ok(), `K-Time injoignable sur ${KTIME}`);

    // ── 1. Panneau GED : proposition ──────────────────────────────────────
    await page.goto(`${BASE}/erpconnect/panel/${docId}`, { waitUntil: 'domcontentloaded' });

    await expect(page.locator('#erp-supplier-status')).toContainText('Fournisseur reconnu', { timeout: 15_000 });
    await expect(page.locator('#erp-supplier-status')).toContainText('Fournitout SA');
    await expect(page.locator('#erp-invoice-exists')).toContainText('Nouvelle facture');

    // 4 lignes, ventilations attendues dans l'ordre du seed
    const badges = page.locator('.erp-line-ventilation');
    await expect(badges).toHaveCount(4);
    await expect(badges.nth(0)).toHaveText('Stock');             // VIS-40
    await expect(badges.nth(1)).toHaveText('Facturé ici');       // PREST-INFO
    await expect(badges.nth(2)).toHaveText('Vente au comptant'); // CAISSE-ART
    await expect(badges.nth(3)).toHaveText('Non introduit');     // CABLE-X

    // Ventilation fractionnée : un éditeur d'allocation pré-rempli par ligne (§2).
    const editors = page.locator('.erp-alloc-editor');
    await expect(editors).toHaveCount(4);

    // Ligne inconnue (CABLE-X, idx 3, qté 3) : proposée « non attribué ».
    // L'utilisateur la fractionne : 2 en stock + 1 en fiche de travail (Σ = 3).
    const lastEditor = editors.nth(3);
    await lastEditor.locator('.erp-alloc-type').first().selectOption('stock');
    await lastEditor.locator('.erp-alloc-qty').first().fill('2');
    await lastEditor.locator('.erp-alloc-add').click();
    await lastEditor.locator('.erp-alloc-type').nth(1).selectOption('fiche_travail');
    await lastEditor.locator('.erp-alloc-qty').nth(1).fill('1');
    // La somme des répartitions doit égaler la quantité de la ligne (indicateur vert).
    await expect(lastEditor.locator('.erp-alloc-sum')).toHaveClass(/erp-alloc-sum--ok/);
    await expect(lastEditor.locator('.erp-alloc-sum')).toContainText('3 / 3');

    await page.screenshot({ path: path.join(SHOTS, 'erp-connect-proposition.png'), fullPage: true });

    // ── 2. Introduction dans K-Time (draft « saisie depuis ged ») ─────────
    await page.locator('#erp-submit-btn').click();
    await expect(page.locator('#erp-validation-status')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('#erp-validation-detail')).toContainText('En attente de validation');

    // ── 3. K-Time : login manager + validation (bannière inline) ──────────
    const ktContext = await browser.newContext(); // contexte vierge (pas le storageState GED)
    const kt = await ktContext.newPage();
    await kt.goto(`${KTIME}/login`, { waitUntil: 'domcontentloaded' });
    await kt.fill('input[name="username"]', 'admin');
    await kt.fill('input[name="password"]', 'password');
    await kt.click('button[type="submit"]');
    await kt.waitForLoadState('domcontentloaded');

    await kt.goto(`${KTIME}/received-invoices`, { waitUntil: 'domcontentloaded' });
    const validateForm = kt.locator('form[data-ged-confirm="validate"]').first();
    await expect(validateForm, 'la facture GED draft doit être visible avec son bouton Valider').toBeVisible({ timeout: 10_000 });
    await validateForm.locator('button').click();                    // ouvre la bannière inline
    const confirmBtn = kt.locator('.ged-banner-validate').first();
    await expect(confirmBtn).toBeVisible();
    await kt.screenshot({ path: path.join(SHOTS, 'erp-connect-ktime-validation.png'), fullPage: true });
    await confirmBtn.click();                                        // « Oui, valider »
    await kt.waitForLoadState('domcontentloaded');
    await ktContext.close();

    // ── 4. GED : refresh → « Bon pour accord » (qui + quand) ──────────────
    await page.locator('#erp-refresh-btn').click();
    await expect(page.locator('#erp-validation-detail')).toContainText('Bon pour accord', { timeout: 10_000 });
    await expect(page.locator('#erp-validation-detail')).toContainText('Administrateur');
    await page.screenshot({ path: path.join(SHOTS, 'erp-connect-bon-pour-accord.png'), fullPage: true });
  });

  test('lien d\'évidence persisté côté GED (erp_links)', async ({ page }) => {
    // Après le test principal : le lien document ↔ facture K-Time doit exister
    // avec le statut de validation copié (bon pour accord).
    const out = execSync(`php tools/erp-sim-check.php ${docId}`, { cwd: repoRoot, encoding: 'utf8' });
    const link = JSON.parse(out.trim().split(/\r?\n/).pop() ?? 'null');
    expect(link, 'erp_links doit contenir le lien').toBeTruthy();
    expect(link.external_ref).toBe(`ged:doc:${docId}`);
    expect(link.validation_status).toBe('validated');
    expect(link.validated_by_name).toContain('Administrateur');

    // La ventilation fractionnée a bien été persistée côté K-Time : 3 lignes à 1
    // allocation + la ligne CABLE-X fractionnée en 2 → 5 allocations, toutes confirmées
    // après la validation totale (§4.2 + §4.5).
    const show = await page.request.get(
      `${KTIME}/api/ged/received-invoices/${link.external_id}`,
      { headers: { 'X-Api-Key': KTIME_KEY }, timeout: 5_000 },
    );
    expect(show.ok()).toBeTruthy();
    const inv = await show.json();
    expect(inv.allocations_summary.total).toBe(5);
    expect(inv.allocations_summary.confirmed).toBe(5);
  });
});

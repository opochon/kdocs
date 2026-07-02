import { test, expect, type Page } from '@playwright/test';
import path from 'node:path';
import { BASE } from './helpers/personas';
import { expectNoPhpError } from './helpers/page-guards';

const SHOTS = path.join(__dirname, '..', 'shots');

/**
 * Lot E — Hub admin et sous-sections (F-ADM-01..05).
 * Session technique root (storageState global) : accès admin complet.
 */
test.describe('admin-hub (Lot E)', () => {
  test.describe.configure({ mode: 'serial' });
  test.setTimeout(60_000);

  test('F-ADM-01: hub admin — tuiles présentes et navigables', async ({ page }) => {
    await page.goto(`${BASE}/admin`, { waitUntil: 'domcontentloaded' });
    await expectNoPhpError(page);

    const tiles = page.locator('main a[href*="/admin/"]');
    expect(await tiles.count(), 'nombre de tuiles admin').toBeGreaterThanOrEqual(8);

    for (const title of ['Paramètres', 'Utilisateurs', 'Tags', 'Workflows', 'Diagnostic', 'Indexation']) {
      await expect(page.locator('main').getByText(title, { exact: true }).first(), `tuile ${title}`).toBeVisible();
    }

    // Invariant : le hub admin n'est pas une entrée de la sidebar user principale.
    const sidebarMainLinks = page.locator('aside nav a[href$="/admin"]');
    expect(await sidebarMainLinks.count(), 'hub hors sidebar user').toBeLessThanOrEqual(1);

    // Navigation depuis une tuile : Tags.
    await page.locator('main a[href*="/admin/tags"]').first().click();
    await page.waitForLoadState('domcontentloaded');
    await expectNoPhpError(page);
    await expect(page.locator('main h1').first()).toContainText(/tags/i);
    await page.screenshot({ path: path.join(SHOTS, 'admin-hub.png'), fullPage: true });
  });

  test('F-ADM-02: référentiels — listes éditables rendues', async ({ page }) => {
    const referentiels: Array<{ route: string; h1: RegExp }> = [
      { route: '/admin/tags', h1: /tags/i },
      { route: '/admin/document-types', h1: /types/i },
      { route: '/admin/correspondents', h1: /correspondants/i },
      { route: '/admin/custom-fields', h1: /champs/i },
      { route: '/admin/storage-paths', h1: /stockage|chemins/i },
      { route: '/admin/workflows', h1: /workflows/i },
      { route: '/admin/users', h1: /utilisateurs/i },
      { route: '/admin/roles', h1: /rôles|roles/i },
    ];

    for (const ref of referentiels) {
      await page.goto(`${BASE}${ref.route}`, { waitUntil: 'domcontentloaded' });
      await expectNoPhpError(page);
      await expect(page.locator('main h1').first(), `h1 ${ref.route}`).toContainText(ref.h1);
      // Liste éditable : un tableau/une grille est rendu, ou l'état vide propose la création.
      const list = page.locator('main table, main [class*="divide-y"], main ul');
      const emptyState = page.locator('main').getByText(/aucun/i);
      const editable = (await list.count()) >= 1 || (await emptyState.count()) >= 1;
      expect(editable, `liste ou état vide ${ref.route}`).toBe(true);
    }
  });

  test('F-ADM-02: CRUD référentiel — création puis suppression d\'un tag', async ({ page }) => {
    const tagName = `lot-e-tag-${Date.now()}`;

    await page.goto(`${BASE}/admin/tags/create`, { waitUntil: 'domcontentloaded' });
    await expectNoPhpError(page);
    await page.fill('input[name="name"]', tagName);
    await page.locator('form[action*="tags"] button[type="submit"]').first().click();
    await page.waitForLoadState('domcontentloaded');
    await expectNoPhpError(page);

    // Le tag créé apparaît dans la liste.
    await page.goto(`${BASE}/admin/tags`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('main').getByText(tagName).first(), 'tag créé visible').toBeVisible();

    // Suppression : formulaire POST delete sur la ligne du tag.
    const row = page.locator('main tr', { hasText: tagName }).first();
    page.once('dialog', (dialog) => dialog.accept());
    const deleteBtn = row.locator('form[action*="delete"] button, button[onclick*="delete"], a[href*="delete"]').first();
    if (await deleteBtn.count()) {
      await deleteBtn.click();
      await page.waitForLoadState('domcontentloaded');
      await expectNoPhpError(page);
      await page.goto(`${BASE}/admin/tags`, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('main').getByText(tagName), 'tag supprimé').toHaveCount(0);
    }
  });

  test('F-ADM-03: règles d\'attribution — page + éditeur + API', async ({ page }) => {
    await page.goto(`${BASE}/admin/attribution-rules`, { waitUntil: 'domcontentloaded' });
    await expectNoPhpError(page);
    await expect(page.locator('main h1').first()).toContainText(/attribution/i);
    await expect(page.locator('main a[href*="attribution-rules/create"]').first()).toBeVisible();

    // API index : JSON 200.
    const apiResp = await page.request.get(`${BASE}/api/attribution-rules`);
    expect(apiResp.status(), 'GET /api/attribution-rules').toBe(200);
    const payload = await apiResp.json();
    expect(payload, 'payload JSON règles').toBeTruthy();

    // Éditeur de règle (création) rend sans erreur.
    await page.goto(`${BASE}/admin/attribution-rules/create`, { waitUntil: 'domcontentloaded' });
    await expectNoPhpError(page);
    await page.screenshot({ path: path.join(SHOTS, 'admin-attribution-rules.png'), fullPage: true });
  });

  test('F-ADM-04: diagnostic — statuts connecteurs UI + API health', async ({ page }) => {
    await page.goto(`${BASE}/admin/diagnostic`, { waitUntil: 'domcontentloaded' });
    await expectNoPhpError(page);
    await expect(page.locator('main h1').first()).toContainText(/diagnostic/i);

    const apiResp = await page.request.get(`${BASE}/api/admin/connectors/health`);
    expect(apiResp.status(), 'GET /api/admin/connectors/health').toBe(200);
    const health = await apiResp.json();
    expect(typeof health, 'payload health connecteurs').toBe('object');
    await page.screenshot({ path: path.join(SHOTS, 'admin-diagnostic.png'), fullPage: true });
  });

  test('F-ADM-05: indexation — statut, stats et worker', async ({ page }) => {
    await page.goto(`${BASE}/admin/indexing`, { waitUntil: 'domcontentloaded' });
    await expectNoPhpError(page);
    await expect(page.locator('main h1').first()).toContainText(/indexation/i);

    // Stats + bouton de lancement présents.
    await expect(page.locator('#stat-documents')).toBeVisible();
    await expect(page.locator('#stat-status')).toBeVisible();
    await expect(page.locator('#btn-index')).toBeVisible();

    // Endpoint statut : JSON 200.
    const statusResp = await page.request.get(`${BASE}/admin/indexing/status`);
    expect(statusResp.status(), 'GET /admin/indexing/status').toBe(200);
    const status = await statusResp.json();
    expect(status, 'payload statut indexation').toBeTruthy();
    await page.screenshot({ path: path.join(SHOTS, 'admin-indexing.png'), fullPage: true });
  });
});

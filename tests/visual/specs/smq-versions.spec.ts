import { test, expect } from '@playwright/test';

// Couvre C.2 : l'onglet Versions (plugin SMQ) dans la modale fiche document.
// Le serveur du harness est lancé avec SMQ_APP_ENABLED=true (playwright.config).
const BASE_PATH = process.env.KDOCS_BASE_PATH ?? '/kdocs';

test('SMQ: onglet Versions dans la modale fiche', async ({ page }) => {
  // Découvre un document via l'API (la fiche est une modale, pas une page /documents/{id}).
  const resp = await page.request.get(`${BASE_PATH}/api/documents?per_page=1`, {
    headers: { Accept: 'application/json' },
  });
  expect(resp.ok()).toBeTruthy();

  const json = await resp.json();
  const docs = Array.isArray(json.data) ? json.data : [];
  test.skip(docs.length === 0, 'Aucun document en base pour tester la fiche');

  const id = docs[0].id;
  // ?open={id} ouvre automatiquement la modale (P0.4) ; le panneau se construit en JS.
  await page.goto(`${BASE_PATH}/documents?open=${id}`, { waitUntil: 'domcontentloaded' });

  // Onglet Versions present dans les onglets de la modale (SMQ actif).
  const versionsTab = page.locator('#preview-tabs button', { hasText: 'Versions' });
  await expect(versionsTab).toBeVisible({ timeout: 10_000 });

  // Le panneau s'affiche et tente de charger les versions sans casser la modale.
  await versionsTab.click();
  await expect(page.locator('#preview-tab-versions')).toBeVisible();
  await expect(page.locator('#preview-versions-content')).toBeVisible();

  const html = await page.content();
  expect(html).not.toContain('Fatal error');
});

// Couvre C.3 / GAP-032 : quittance de lecture — 1 ligne par user/version (idempotent).
test('SMQ: quittance de lecture — record idempotent + read-status', async ({ page }) => {
  const resp = await page.request.get(`${BASE_PATH}/api/documents?per_page=1`, {
    headers: { Accept: 'application/json' },
  });
  expect(resp.ok()).toBeTruthy();
  const json = await resp.json();
  const docs = Array.isArray(json.data) ? json.data : [];
  test.skip(docs.length === 0, 'Aucun document en base pour tester la quittance');

  const id = docs[0].id;
  const version = 1; // v1 implicite (quittance affichée même sans rangée de version)

  // Enregistrer la quittance deux fois : idempotent, pas de doublon.
  const first = await page.request.post(`${BASE_PATH}/api/documents/${id}/versions/${version}/read`);
  expect([200, 201]).toContain(first.status());
  const second = await page.request.post(`${BASE_PATH}/api/documents/${id}/versions/${version}/read`);
  expect([200, 201]).toContain(second.status());

  // read-status : lu par l'utilisateur courant, et une seule ligne pour lui.
  const statusResp = await page.request.get(`${BASE_PATH}/api/documents/${id}/versions/${version}/read-status`);
  expect(statusResp.status()).toBe(200);
  const payload = await statusResp.json();
  const status = payload.data ?? payload;
  expect(status.has_read, 'has_read après record').toBe(true);
  expect(status.read_at, 'read_at renseigné').toBeTruthy();
  const readers: Array<{ user_id?: number; username?: string }> = status.readers ?? [];
  expect(status.readers_count, 'readers_count cohérent').toBe(readers.length);
  const currentUserRows = readers.filter((r) => r.username === 'root' || r.user_id === 1);
  expect(currentUserRows.length, '1 ligne quittance par user/version').toBeLessThanOrEqual(1);
});

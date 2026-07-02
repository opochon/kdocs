import { test, expect } from '@playwright/test';
import { BASE } from './helpers/personas';

// CmdV4 étape 6 — badge fraîcheur dans la modale fiche document.
// Test structurel (pas de sidecar CmdV4 live) : le badge #cmdv4-freshness-badge
// est présent dans le DOM et caché pour un document jamais analysé par CmdV4.
// Le remplissage (à jour / obsolète) est couvert par CmdV4ResultMapperTest
// (cmdv4_up_to_date persisté) + la logique JS updateCmdV4FreshnessBadge.

test('CmdV4: badge fraîcheur présent et caché sans analyse', async ({ page }) => {
  const resp = await page.request.get(`${BASE}/api/documents?per_page=1`, {
    headers: { Accept: 'application/json' },
  });
  expect(resp.ok()).toBeTruthy();
  const json = await resp.json();
  const docs = Array.isArray(json.data) ? json.data : [];
  test.skip(docs.length === 0, 'Aucun document en base');

  await page.goto(`${BASE}/documents?open=${docs[0].id}`, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');

  const badge = page.locator('#cmdv4-freshness-badge').first();
  await expect(badge, 'badge fraîcheur absent de la modale').toBeAttached({ timeout: 10_000 });
  await expect(badge).toHaveClass(/\bhidden\b/);
});

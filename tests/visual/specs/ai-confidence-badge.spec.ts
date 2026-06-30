import { test, expect, type Page } from '@playwright/test';
import path from 'node:path';

// Lot 2 — Badge « % de certitude » dans la modale fiche document.
// Test structurel (pas d'IA live) : on ouvre la fiche preview d'une facture
// existante et on vérifie que le badge #ai-confidence-badge est présent dans
// le DOM et caché tant qu'aucune suggestion n'a été demandée. Le remplissage
// du badge après classify-ai est couvert par la logique JS + les tests
// PHPUnit hermétiques (confidence_pct renvoyé par l'API).

const BASE_PATH = process.env.KDOCS_BASE_PATH ?? '/kdocs';
const HOST = process.env.KDOCS_HOST ?? '127.0.0.1';
const PORT = process.env.KDOCS_PORT ?? '8765';
const BASE = `http://${HOST}:${PORT}${BASE_PATH}`;
const SHOTS = path.join(__dirname, '..', 'shots');

async function loginAs(page: Page, username: string) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('#username', username);
  await page.fill('#password', '');
  await page.click('button[type="submit"]');
  await page.waitForLoadState('networkidle');
  if (/\/login\b/.test(page.url())) throw new Error(`Login echoue pour "${username}"`);
}

async function findFactureId(api: any): Promise<number> {
  const resp = await api.get(`${BASE}/api/folders/documents?path=${encodeURIComponent('eval/lot-original')}`);
  if (!resp.ok()) throw new Error('folders documents API KO');
  const json = await resp.json();
  const facture = (json.documents as any[]).find(
    (d) => d.document_type_label === 'Facture' || d.document_type_id === 1,
  );
  if (!facture) throw new Error('Aucune facture dans eval/lot-original');
  return Number(facture.id);
}

test.describe('ai-confidence-badge (Lot 2)', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('le badge #ai-confidence-badge est présent et caché initialement', async ({ page }) => {
    await loginAs(page, 'eval_employeur');
    const api = page.context().request;
    const factureId = await findFactureId(api);

    await page.goto(`${BASE}/documents?open=${factureId}&path=${encodeURIComponent('eval/lot-original')}`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForLoadState('networkidle');

    const badge = page.locator('#ai-confidence-badge').first();
    await expect(badge, 'badge de certitude absent de la modale').toBeAttached({ timeout: 10_000 });
    // Caché tant qu'aucune suggestion n'a été demandée (classe `hidden`).
    await expect(badge).toHaveClass(/\bhidden\b/);

    // Le bouton « Suggestion : analyser » déclenche classify-ai (présence structurelle).
    const btn = page.locator('#ai-suggest-btn').first();
    await expect(btn).toBeVisible();

    await page.screenshot({ path: path.join(SHOTS, 'ai-confidence-badge.png'), fullPage: true });
  });
});

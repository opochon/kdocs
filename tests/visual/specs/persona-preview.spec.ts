import { test, expect, type Page } from '@playwright/test';
import path from 'node:path';

// Couche 3 — Persona étendu : ouverture de la fiche document en UI par persona,
// vérification que l'action de validation est présentée (bouton toggle) et que le flag
// `can_validate` renvoyé par l'API (et consommé par l'UI) est cohérent avec le rôle.
//
// Complément de persona.spec.ts (qui teste can-validate via l'endpoint dédié) :
// ici on valide le CHEMIN UI (modale preview) + la présence du contrôle.

const BASE_PATH = process.env.KDOCS_BASE_PATH ?? '/kdocs';
const HOST = process.env.KDOCS_HOST ?? '127.0.0.1';
const PORT = process.env.KDOCS_PORT ?? '8765';
const BASE = `http://${HOST}:${PORT}${BASE_PATH}`;
const SHOTS = path.join(__dirname, '..', 'shots');

type Persona = { username: string; label: string; canValidateFacture: boolean };

const PERSONAS: Persona[] = [
  { username: 'eval_secretaire', label: 'secretaire', canValidateFacture: false },
  { username: 'eval_comptable',  label: 'comptable',  canValidateFacture: false },
  { username: 'eval_rh',         label: 'rh',         canValidateFacture: false },
  { username: 'eval_employeur',  label: 'employeur',  canValidateFacture: true  },
];

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

for (const p of PERSONAS) {
  test.describe(`persona-preview: ${p.label}`, () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('fiche preview + contrôle validation + can_validate cohérent', async ({ page }) => {
      await loginAs(page, p.username);
      const api = page.context().request;
      const factureId = await findFactureId(api);

      // Ouvrir la modale preview via ?open=.
      await page.goto(`${BASE}/documents?open=${factureId}&path=${encodeURIComponent('eval/lot-original')}`, {
        waitUntil: 'domcontentloaded',
      });
      await page.waitForLoadState('networkidle');

      // Le contrôle de validation (toggle) est présent dans l'header de la modale.
      const toggle = page.locator('[title="Toggle validation (Validé/Rejeté/N/A)"]').first();
      await expect(toggle, 'bouton toggle validation absent de la modale').toBeVisible({ timeout: 10_000 });

      // Le flag can_validate (consommé par l'UI) doit être cohérent avec le rôle.
      const showResp = await api.get(`${BASE}/api/documents/${factureId}`);
      expect(showResp.ok(), `show HTTP ${showResp.status()}`).toBeTruthy();
      const showJson = await showResp.json();
      const canValidate = showJson.data?.can_validate;
      expect(canValidate, `${p.label}: can_validate attendu=${p.canValidateFacture}`).toBe(p.canValidateFacture);

      await page.screenshot({ path: path.join(SHOTS, `persona-preview-${p.label}.png`), fullPage: true });
    });
  });
}

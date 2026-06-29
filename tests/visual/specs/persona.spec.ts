import { test, expect, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

// Test Playwright paramétré par persona : login dédié → documents → recherche → capture.
// Les personas (eval_*) sont créés par tools/eval-full.php avec password_hash vide.

const BASE_PATH = process.env.KDOCS_BASE_PATH ?? '/kdocs';
const HOST = process.env.KDOCS_HOST ?? '127.0.0.1';
const PORT = process.env.KDOCS_PORT ?? '8765';
const BASE = `http://${HOST}:${PORT}${BASE_PATH}`;

const SHOTS = path.join(__dirname, '..', 'shots');
fs.mkdirSync(SHOTS, { recursive: true });

type Persona = { username: string; label: string; canValidateFacture: boolean };

const PERSONAS: Persona[] = [
  { username: 'eval_secretaire', label: 'secretaire', canValidateFacture: false },
  { username: 'eval_comptable',  label: 'comptable',  canValidateFacture: false },
  { username: 'eval_rh',         label: 'rh',         canValidateFacture: false },
  { username: 'eval_employeur',  label: 'employeur',  canValidateFacture: true  },
];

const ERROR_MARKERS = [
  'Fatal error', 'Parse error', 'Uncaught', 'Whoops',
  'PDOException', 'Call to undefined', 'syntax error, unexpected',
];

// Login inline pour un persona donné, sur le contexte courant de la page.
async function loginAs(page: Page, username: string) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('#username', username);
  await page.fill('#password', '');
  await page.click('button[type="submit"]');
  await page.waitForLoadState('networkidle');
  // Échec auth => toujours sur /login.
  if (/\/login\b/.test(page.url())) {
    throw new Error(`Login echoue pour "${username}"`);
  }
}

for (const p of PERSONAS) {
  test.describe(`persona: ${p.label} (${p.username})`, () => {
    // Contexte isolé par persona (storageState dédié) pour garantir des sessions distinctes.
    test.use({ storageState: { cookies: [], origins: [] } });

    test('login + documents + recherche "tribunal"', async ({ page }) => {
      await loginAs(page, p.username);

      // 1. Bibliothèque / documents rendue, auth tenue.
      const resp = await page.goto(`${BASE}/documents?path=eval/lot-original`, { waitUntil: 'domcontentloaded' });
      expect(resp).toBeTruthy();
      expect(resp!.status()).toBeLessThan(400);
      expect(page.url(), 'redirection login').not.toMatch(/\/login\b/);

      const html = await page.content();
      for (const marker of ERROR_MARKERS) {
        expect(html, `marqueur "${marker}"`).not.toContain(marker);
      }

      // 2. Au moins une carte document chargée (AJAX).
      await expect(page.locator('.document-card, [data-doc-id]').first()).toBeVisible({ timeout: 10_000 });

      // 3. Recherche « tribunal ».
      await page.fill('#search-input', 'tribunal');
      await page.press('#search-input', 'Enter');
      await page.waitForLoadState('networkidle');
      await expect(page.locator('.document-card, [data-doc-id]').first()).toBeVisible({ timeout: 10_000 });

      // 4. Capture par persona pour revue.
      await page.screenshot({ path: path.join(SHOTS, `persona-${p.label}.png`), fullPage: true });
    });

    test('droits de validation conformes au rôle (facture 6000 CHF)', async ({ page }) => {
      await loginAs(page, p.username);
      const api = page.context().request; // hérite des cookies de session du persona

      // 1. Trouver l'id du document facture du lot eval via l'API dossiers.
      const docsResp = await api.get(`${BASE}/api/folders/documents?path=${encodeURIComponent('eval/lot-original')}`);
      expect(docsResp.ok()).toBeTruthy();
      const docsJson = await docsResp.json();
      const facture = (docsJson.documents as any[]).find(
        (d) => d.document_type_label === 'Facture' || d.document_type_id === 1,
      );
      expect(facture, 'aucune facture dans le lot eval').toBeTruthy();

      // 2. Droits de validation pour ce persona via l'API (utilise la session du persona).
      const canResp = await api.get(`${BASE}/api/validation/${facture.id}/can-validate`);
      expect(canResp.ok()).toBeTruthy();
      const canJson = await canResp.json();

      if (p.canValidateFacture) {
        expect(canJson.can_validate, `employeur doit pouvoir valider`).toBe(true);
      } else {
        expect(canJson.can_validate, `${p.label} ne doit pas pouvoir valider (plafond/scope)`).toBe(false);
      }

      // 3. Capture de la bibliothèque sous l'identité du persona.
      await page.goto(`${BASE}/documents?path=eval/lot-original`, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('.document-card, [data-doc-id]').first()).toBeVisible({ timeout: 10_000 });
      await page.screenshot({ path: path.join(SHOTS, `persona-${p.label}-validation.png`), fullPage: true });
    });
  });
}

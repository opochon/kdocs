import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

// Couche 3 — Lisibilité / accessibilité (F-A11Y-01..05 de FUNCTIONS-SPEC.md).
// Vérifie les règles WCAG pertinentes pour le chrome K-Docs sur les vues clés,
// par persona (chaque persona voit ses propres éléments : boutons validation,
// badges, liens). Les fonctions rôle-agnostiques (ingestion/IA/recherche) ne
// sont pas re-testées ici — couvertes par pipeline-ui.spec.ts.

const BASE_PATH = process.env.KDOCS_BASE_PATH ?? '/kdocs';
const HOST = process.env.KDOCS_HOST ?? '127.0.0.1';
const PORT = process.env.KDOCS_PORT ?? '8765';
const BASE = `http://${HOST}:${PORT}${BASE_PATH}`;

// Règles axe valides ciblées par la spec (F-A11Y-01..05).
// `name-role-value` est un principe WCAG, pas un id de règle axe — couvert par button-name/link-name.
const RULES = [
  'color-contrast',     // F-A11Y-01 contraste texte/fond
  'button-name',        // F-A11Y-02 / F-A11Y-05 boutons (icônes SVG) ont un nom accessible
  'link-name',          // F-A11Y-02 liens ont un nom accessible
  'aria-allowed-attr',  // robustesse ARIA
];

const PERSONAS = ['eval_secretaire', 'eval_comptable', 'eval_rh', 'eval_employeur'];

async function loginAs(page: Page, username: string) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('#username', username);
  await page.fill('#password', '');
  await page.click('button[type="submit"]');
  await page.waitForLoadState('networkidle');
  if (/\/login\b/.test(page.url())) throw new Error(`Login echoue pour "${username}"`);
}

// Scan axe sur la page courante, restreint aux règles ciblées. Retourne les violations.
async function scanViolations(page: Page) {
  const results = await new AxeBuilder({ page })
    .withRules(RULES)
    .analyze();
  return results.violations;
}

function summarize(violations: any[]): string {
  return violations
    .map((v) => `${v.id} (${v.impact}): ${v.nodes.length} noeud(s)`)
    .join(' | ');
}

// --- Vues clés en session root (storageState global) ---

for (const route of [
  { name: 'dashboard', path: '/' },
  { name: 'documents', path: '/documents' },
  { name: 'search', path: '/search' },
  { name: 'mes-taches', path: '/mes-taches' },
]) {
  test(`a11y root: ${route.name} sans violations (regles F-A11Y-01..05)`, async ({ page }) => {
    await page.goto(`${BASE}${route.path}`, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle');
    const violations = await scanViolations(page);
    expect(violations, summarize(violations)).toEqual([]);
  });
}

// --- Par persona : documents (workspace principal) ---

for (const username of PERSONAS) {
  test.use({ storageState: { cookies: [], origins: [] } });
  test(`a11y ${username}: /documents sans violations de contraste/nom`, async ({ page }) => {
    await loginAs(page, username);
    await page.goto(`${BASE}/documents`, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle');
    // Attendre que la grille/arborescence soit rendue.
    await page.locator('.folder-link, .document-card, #empty-state').first().waitFor({ timeout: 10_000 });
    const violations = await scanViolations(page);
    expect(violations, summarize(violations)).toEqual([]);
  });
}

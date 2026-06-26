import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

// Captures rangees hors test-results pour revue a l'oeil.
const SHOTS = path.join(__dirname, '..', 'shots');
fs.mkdirSync(SHOTS, { recursive: true });

// Base path applicatif (baseURL = origine seule cote config).
const BASE_PATH = process.env.KDOCS_BASE_PATH ?? '/kdocs';

// Marqueurs d'erreur PHP rendus dans le HTML (display_errors en dev).
const ERROR_MARKERS = [
  'Fatal error',
  'Parse error',
  'Uncaught',
  'Whoops',
  'PDOException',
  'Call to undefined',
  'syntax error, unexpected',
];

// Routes canoniques du shell (oracle ORACLES-KDOCS-PRODUCT.md section 1).
const ROUTES = [
  { name: 'dashboard', path: '/' },
  { name: 'documents', path: '/documents' },
  { name: 'search', path: '/search' },
  { name: 'mes-taches', path: '/mes-taches' },
  { name: 'upload', path: '/documents/upload' },
  { name: 'admin', path: '/admin' },
];

for (const route of ROUTES) {
  test(`shell: ${route.name} (${route.path})`, async ({ page }) => {
    const resp = await page.goto(`${BASE_PATH}${route.path}`, { waitUntil: 'domcontentloaded' });

    // 1. Reponse HTTP saine.
    expect(resp, `pas de reponse pour ${route.path}`).toBeTruthy();
    expect(resp!.status(), `status HTTP ${route.path}`).toBeLessThan(400);

    // 2. Auth tenue : pas de redirection vers /login.
    expect(page.url(), `redirection login depuis ${route.path}`).not.toMatch(/\/login\b/);

    // 3. Pas de marqueur d'erreur PHP dans le HTML.
    const html = await page.content();
    for (const marker of ERROR_MARKERS) {
      expect(html, `marqueur "${marker}" sur ${route.path}`).not.toContain(marker);
    }

    // 4. Capture pour revue visuelle (baseline pixel activable plus tard).
    await page.screenshot({ path: path.join(SHOTS, `${route.name}.png`), fullPage: true });
  });
}

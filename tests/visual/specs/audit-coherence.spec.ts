import { test } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Audit de cohérence visuelle — capture pleine page de chaque écran représentatif
 * (cœur GED + apps satellites) pour revue à l'œil. But : voir le patchwork ensemble
 * avant de codifier la couche composition. Non bloquant : une route en échec ne
 * fait pas tomber les autres captures.
 */

const SHOTS = path.join(__dirname, '..', 'audit');
fs.mkdirSync(SHOTS, { recursive: true });

const BASE_PATH = process.env.KDOCS_BASE_PATH ?? '/kdocs';

// Ordre = parcours visuel logique. Préfixe numérique => tri stable dans le dossier.
const ROUTES = [
  // --- Cœur GED ---
  { name: '01-dashboard', path: '/', group: 'GED' },
  { name: '02-documents', path: '/documents', group: 'GED' },
  { name: '03-search', path: '/search', group: 'GED' },
  { name: '04-mes-taches', path: '/mes-taches', group: 'GED' },
  { name: '05-upload', path: '/documents/upload', group: 'GED' },
  // --- Admin (formulaires + listes, gros pourvoyeurs de style ad hoc) ---
  { name: '06-admin-hub', path: '/admin', group: 'Admin' },
  { name: '07-admin-settings', path: '/admin/settings', group: 'Admin' },
  { name: '08-admin-correspondents', path: '/admin/correspondents', group: 'Admin' },
  { name: '09-admin-document-types', path: '/admin/document-types', group: 'Admin' },
  { name: '10-admin-diagnostic', path: '/admin/diagnostic', group: 'Admin' },
  { name: '11-admin-consume', path: '/admin/consume', group: 'Admin' },
  { name: '12-admin-indexing', path: '/admin/indexing', group: 'Admin' },
  // --- Apps satellites (hors-système présumé) ---
  { name: '20-timetrack', path: '/time', group: 'Satellite' },
  { name: '21-erpconnect', path: '/erpconnect', group: 'Satellite' },
];

for (const route of ROUTES) {
  test(`audit: ${route.name} (${route.path})`, async ({ page }) => {
    try {
      const resp = await page.goto(`${BASE_PATH}${route.path}`, { waitUntil: 'networkidle', timeout: 15_000 });
      const status = resp?.status() ?? 0;
      // Laisse le temps aux polices/Alpine de peindre.
      await page.waitForTimeout(400);
      await page.screenshot({ path: path.join(SHOTS, `${route.name}.png`), fullPage: true });
      console.log(`  [${route.group}] ${route.name} -> HTTP ${status} ${page.url()}`);
    } catch (err) {
      console.log(`  [${route.group}] ${route.name} -> ÉCHEC: ${(err as Error).message}`);
      // Capture ce qui est peint malgré tout, pour ne pas perdre le signal.
      try { await page.screenshot({ path: path.join(SHOTS, `${route.name}-ERR.png`), fullPage: true }); } catch { /* noop */ }
    }
  });
}

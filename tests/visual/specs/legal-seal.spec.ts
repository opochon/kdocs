import { test, expect } from '@playwright/test';
import path from 'node:path';
import { BASE } from './helpers/personas';

/**
 * P2 conformité CH — scellement légal WORM (GAP-020/024).
 * Seed doc jetable (test_legal_seal_*, invisible hors debug) → seal → écritures 403.
 */
async function seedSealDoc(): Promise<number> {
  const { execFile } = await import('node:child_process');
  const { promisify } = await import('node:util');
  const execFileAsync = promisify(execFile);
  const repoRoot = path.resolve(__dirname, '..', '..', '..');
  const { stdout } = await execFileAsync('php', [path.join(repoRoot, 'tools', 'seed-legal-seal.php')], {
    cwd: repoRoot,
    timeout: 15_000,
  });
  const parsed = JSON.parse(stdout.trim()) as { id?: number };
  expect(parsed.id, stdout).toBeTruthy();
  return Number(parsed.id);
}

test('P2: scellement WORM — seal puis PUT/DELETE 403', async ({ page }) => {
  const id = await seedSealDoc();

  // Avant scellement : PUT permis.
  const putBefore = await page.request.put(`${BASE}/api/documents/${id}`, {
    data: { title: `test_legal_seal_edit_${Date.now()}` },
    headers: { 'Content-Type': 'application/json' },
  });
  expect(putBefore.status(), 'PUT avant scellement').toBe(200);

  // Scellement : 201, rétention ≥ 10 ans fixée.
  const seal = await page.request.post(`${BASE}/api/documents/${id}/legal-seal`);
  expect(seal.status(), 'POST legal-seal').toBe(201);
  const sealPayload = (await seal.json()).data ?? (await seal.json());
  expect(sealPayload.sealed).toBe(true);
  expect(sealPayload.retention_until, 'retention_until fixée').toBeTruthy();

  // Idempotent : re-seal → 200 already_sealed.
  const resealed = await page.request.post(`${BASE}/api/documents/${id}/legal-seal`);
  expect(resealed.status(), 're-seal idempotent').toBe(200);

  // Après scellement : toute écriture 403.
  const putAfter = await page.request.put(`${BASE}/api/documents/${id}`, {
    data: { title: 'tentative modification doc scellé' },
    headers: { 'Content-Type': 'application/json' },
  });
  expect(putAfter.status(), 'PUT après scellement').toBe(403);

  const putType = await page.request.put(`${BASE}/api/documents/${id}/type`, {
    data: { document_type_id: 1 },
    headers: { 'Content-Type': 'application/json' },
  });
  expect(putType.status(), 'PUT /type après scellement').toBe(403);

  const del = await page.request.delete(`${BASE}/api/documents/${id}`);
  expect(del.status(), 'DELETE après scellement').toBe(403);

  // Lecture toujours permise.
  const get = await page.request.get(`${BASE}/api/documents/${id}`);
  expect(get.status(), 'GET après scellement').toBe(200);
});

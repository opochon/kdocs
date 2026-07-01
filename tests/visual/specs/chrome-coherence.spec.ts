import { test, expect, type Page } from '@playwright/test';

// Couche 3 — Cohérence du chrome (F-CHROME-01..08 de FUNCTIONS-SPEC.md).
// Invariants transverses de l'arborescence / sidebar / indicateurs.
// Session root (storageState global) — ces invariants sont rôle-agnostiques.

const BASE_PATH = process.env.KDOCS_BASE_PATH ?? '/kdocs';
const HOST = process.env.KDOCS_HOST ?? '127.0.0.1';
const PORT = process.env.KDOCS_PORT ?? '8765';
const BASE = `http://${HOST}:${PORT}${BASE_PATH}`;

// Dossiers internes du pipeline qui doivent être masqués de l'arborescence.
const HIDDEN_FOLDERS = ['toclassify', 'consume', 'processed', 'consume_done', 'consume_error'];

async function openDocuments(page: Page) {
  await page.goto(`${BASE}/documents`, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');
  // Attendre que l'arborescence soit rendue.
  await page.locator('.folder-link, #empty-state').first().waitFor({ timeout: 10_000 });
}

// F-CHROME-01 : sidebar user ≤ 5 entrées + lien Admin séparé.
test('F-CHROME-01: sidebar user <= 5 entrees + lien Administration', async ({ page }) => {
  await openDocuments(page);
  const userNav = page.locator('.ds-sidebar__nav .ds-nav-item');
  const count = await userNav.count();
  expect(count, 'sidebar user a au plus 5 intentions').toBeLessThanOrEqual(5);
  expect(count, 'au moins 1 entree user').toBeGreaterThanOrEqual(1);

  // Lien Administration présent (hors nav user, dans le pied de sidebar).
  await expect(page.locator('.ds-sidebar__foot .ds-nav-item').filter({ hasText: 'Administration' }))
    .toBeVisible();
});

// F-CHROME-02 : compteurs sidebar = dashboard = BDD (oracle ORACLES §4 — même requête SQL).
test('F-CHROME-02: compteur sidebar Bibliothèque = dashboard Documents totaux', async ({ page }) => {
  // Dashboard
  await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');
  const dashText = await page.locator('text=Documents totaux').locator('..').textContent();
  const dashNum = parseInt((dashText ?? '').replace(/[^\d]/g, ''), 10);

  // Sidebar Bibliothèque
  await openDocuments(page);
  const sideText = await page.locator('.ds-sidebar__nav .ds-nav-item').filter({ hasText: 'Bibliothèque' })
    .locator('.ds-nav-count').textContent();
  const sideNum = parseInt((sideText ?? '0').replace(/[^\d]/g, ''), 10);

  expect(sideNum, `sidebar=${sideNum} vs dashboard=${dashNum}`).toBe(dashNum);
});

// F-CHROME-03 : racine = libellé vide + aria-label "Racine du stockage".
test('F-CHROME-03: racine libelle vide + aria-label "Racine du stockage"', async ({ page }) => {
  await openDocuments(page);
  const root = page.locator('.folder-link--root').first();
  await expect(root).toBeVisible();
  await expect(root).toHaveAttribute('aria-label', 'Racine du stockage');
  // Le libellé visible (hors icône/compteur) doit être vide.
  const label = (await root.textContent() ?? '').trim();
  expect(label, 'libellé racine non vide').toBe('');
});

// F-CHROME-04 : dossiers internes (consume, toclassify, processed…) masqués.
// On vérifie les LIBELLÉS des nœuds dossier (.folder-link), pas le texte de la description
// qui mentionne légitimement ces noms (« consume, toclassify, etc. masqués »).
test('F-CHROME-04: dossiers internes masques de l\'arborescence', async ({ page }) => {
  await openDocuments(page);
  const links = page.locator('#documents-sidebar .folder-link');
  const n = await links.count();
  test.skip(n === 0, 'Aucun nœud dossier dans l\'arborescence');
  for (let i = 0; i < n; i++) {
    const label = ((await links.nth(i).textContent()) ?? '').trim().toLowerCase();
    for (const name of HIDDEN_FOLDERS) {
      expect(label, `nœud dossier interne visible: "${label}"`).not.toBe(name);
    }
  }
});

// F-CHROME-05 : sémantique de l'indicateur "Synchronisation nécessaire".
// Pour chaque compteur ⚠, le title "N fichiers, M indexés" doit satisfaire N != M.
// NB : l'indicateur peut être faussement présent à cause du bug F-LIB-03 (relative_path non
// renseigné à l'upload) — ce test valide la SEMANTIQUE du title, pas l'absence de l'indicateur.
test('F-CHROME-05: indicateur sync <=> fileCount != dbCount (semantique du title)', async ({ page }) => {
  await openDocuments(page);
  // Compteurs avec title "N fichiers, M indexés".
  const counts = page.locator('#documents-sidebar [title*="fichiers"][title*="indexés"]');
  const n = await counts.count();
  test.skip(n === 0, 'Aucun indicateur sync présent sur cette arborescence');

  for (let i = 0; i < n; i++) {
    const title = await counts.nth(i).getAttribute('title') ?? '';
    const m = title.match(/(\d+)\s+fichiers,\s*(\d+)\s+indexés/);
    expect(m, `title illisible: "${title}"`).not.toBeNull();
    const fileCount = parseInt(m![1], 10);
    const dbCount = parseInt(m![2], 10);
    expect(fileCount, `indicateur sync mais fileCount==dbCount (${title})`).not.toBe(dbCount);
  }
});

// F-CHROME-06 : pas d'emoji chrome (SVG uniquement). ⚠ (U+26A0, indicateur sync) est whitelisté.
test('F-CHROME-06: pas d\'emoji dans les libellés chrome', async ({ page }) => {
  await openDocuments(page);
  // Chrome = sidebar user + header + titres de cartes. On exclut le compteur sync (⚠ whitelisté).
  const chrome = page.locator('.ds-sidebar__nav .ds-nav-item, .ds-sidebar__foot, header, .document-card h3');
  const n = await chrome.count();
  test.skip(n === 0, 'Aucun élément chrome à scanner');
  // Plages emoji (true emoji + dingbats), hors U+26A0 (⚠) whitelisté.
  const isEmoji = (ch: string) => {
    const cp = ch.codePointAt(0)!;
    if (cp === 0x26A0) return false; // ⚠ whitelisté (indicateur sync)
    return (cp >= 0x1F300 && cp <= 0x1FAFF) || (cp >= 0x1F600 && cp <= 0x1F64F)
      || (cp >= 0x2600 && cp <= 0x27BF) || cp === 0xFE0F;
  };
  for (let i = 0; i < n; i++) {
    const txt = (await chrome.nth(i).textContent()) ?? '';
    for (const ch of Array.from(txt)) {
      expect(isEmoji(ch), `emoji chrome détecté: "${ch}" (U+${ch.codePointAt(0)!.toString(16).toUpperCase()})`).toBe(false);
    }
  }
});

// F-CHROME-07 : bannière sécurité root visible ssi APP_DEBUG=true.
// Le harness tourne en config non-debug (APP_DEBUG=false) -> la bannière doit être absente.
// Le cas positif (debug=true) se vérifie en relançant le serveur avec APP_DEBUG=true.
test('F-CHROME-07: bannière sécurité root absente hors debug', async ({ page }) => {
  await openDocuments(page);
  // Bannière "Sécurité : Le compte root n'a pas de mot de passe" (header.php, gated isAppDebug()).
  const banner = page.locator('header, header + *, body > div').filter({ hasText: 'Le compte root n\'a pas de mot de passe' });
  await expect(banner).toHaveCount(0);
});

// F-CHROME-08 : docs test_* masqués hors debug (documentVisibilitySql sur /api/folders/documents).
test('F-CHROME-08: documents test_* masqués hors debug', async ({ page }) => {
  const resp = await page.request.get(`${BASE}/api/folders/documents?path=`);
  expect(resp.ok(), `API folders/documents HTTP ${resp.status()}`).toBeTruthy();
  const data = await resp.json();
  expect(data.success, data.error ?? 'success=false').toBe(true);

  for (const doc of data.documents ?? []) {
    const title = String(doc.title ?? '').toLowerCase();
    const filename = String(doc.original_filename ?? doc.filename ?? '').toLowerCase();
    expect(title.startsWith('test_'), `doc test visible (title): ${doc.title}`).toBe(false);
    expect(filename.startsWith('test_'), `doc test visible (filename): ${filename}`).toBe(false);
  }
});

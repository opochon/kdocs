#!/usr/bin/env node
/**
 * run-harness — coeur mesurable du harness GEDv1.
 *
 * Remplace les 4 blocs opaques de run-harness.bat (migration smoke, PHPUnit,
 * eval-full, Playwright) par des suites nommees, ecrites dans
 * tests/reports/harness-latest.json au format attendu par tools/checklist.mjs :
 * un tableau `suites`, chaque entree { name, ok, ... }.
 *
 * Verite avant tout : une suite qui echoue reste ok:false avec ses cas nommes ;
 * une suite qui n'a pas pu s'executer n'est jamais ok:true. Aucun oracle du
 * backlog n'est invente — un nom de suite n'apparait que s'il est adosse a un
 * test reel.
 *
 * Usage :
 *   node tools/run-harness.mjs                 harness complet (~10-12 min, Playwright inclus)
 *   node tools/run-harness.mjs --check-specs    verifie juste governance/specs.json vs disque (instantane)
 *
 * Exit code : 0 si toutes les suites emises sont vertes, 1 sinon.
 */
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const ROOT = path.resolve(new URL('..', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'));
const P = (...a) => path.join(ROOT, ...a);
const J = (p, d = null) => { try { return JSON.parse(fs.readFileSync(P(p), 'utf8')); } catch { return d; } };

const ANSI = /\x1b\[[0-9;]*m/g;
const strip = (s) => (s || '').replace(ANSI, '');

// ---------------------------------------------------------------------------
// Registre des specs Playwright — decouverte + garde-fou anti-silence
// ---------------------------------------------------------------------------

function checkSpecsRegistry() {
  const specsDir = P('tests', 'visual', 'specs');
  const onDisk = fs.readdirSync(specsDir)
    .filter((f) => f.endsWith('.spec.ts'))
    .map((f) => f.replace(/\.spec\.ts$/, ''))
    .sort();
  const registry = J('governance/specs.json', { specs: {} }).specs || {};
  const missing = onDisk.filter((f) => !(f in registry));
  const stale = Object.keys(registry).filter((k) => registry[k].status !== 'retired' && !onDisk.includes(k));
  const active = onDisk.filter((f) => registry[f]?.status === 'active');
  const retired = onDisk.filter((f) => registry[f]?.status === 'retired');
  return { ok: missing.length === 0, onDisk, missing, stale, active, retired };
}

if (process.argv.includes('--check-specs')) {
  const r = checkSpecsRegistry();
  if (!r.ok) {
    console.error(`ECHEC — spec(s) sur disque absente(s) de governance/specs.json : ${r.missing.join(', ')}`);
    console.error('Chaque spec de tests/visual/specs/*.spec.ts doit avoir une entree dans governance/specs.json (active, ou retired avec une raison).');
    process.exit(1);
  }
  console.log(`OK — ${r.onDisk.length} spec(s) sur disque, toutes registrees (${r.active.length} active(s), ${r.retired.length} retiree(s)).`);
  if (r.stale.length) console.log(`Note — registrees mais absentes du disque : ${r.stale.join(', ')}`);
  process.exit(0);
}

// ---------------------------------------------------------------------------
// --only <suite> — reexecute une seule suite "autonome" (script PHP capture)
// et la fusionne dans le rapport existant, sans relancer tout le harness.
// Limite aux suites hors PHPUnit/Playwright (celles-la exigent le run complet
// pour rester coherentes avec le reste du rapport).
// ---------------------------------------------------------------------------

const ONLY_STANDALONE = {
  'migration-smoke': () => suiteFromCapture('migration-smoke', 'tests/migration_smoke_test.php', runCapture('php', ['tests/migration_smoke_test.php'])),
  'documents-innodb': () => suiteFromCapture('documents-innodb', 'tools/fix-documents-innodb.php', runCapture('php', ['tools/fix-documents-innodb.php']), { parse: false }),
  'search-fulltext': () => suiteFromCapture('search-fulltext', 'tests/integration/test_fulltext_search.php', runCapture('php', ['tests/integration/test_fulltext_search.php'])),
  'eval-full': () => suiteFromCapture('eval-full', 'tools/eval-full.php --no-ocr', runCapture('php', ['tools/eval-full.php', '--no-ocr'])),
};

if (process.argv.includes('--only')) {
  const name = process.argv[process.argv.indexOf('--only') + 1];
  const fn = ONLY_STANDALONE[name];
  if (!fn) {
    console.error(`--only ${name} : suite inconnue ou non re-executable isolement. Suites disponibles : ${Object.keys(ONLY_STANDALONE).join(', ')}`);
    process.exit(2);
  }
  const reportPath = P('tests', 'reports', 'harness-latest.json');
  const existing = J('tests/reports/harness-latest.json');
  if (!existing) { console.error('tests/reports/harness-latest.json absent — lance le harness complet d\'abord.'); process.exit(2); }
  console.log(`[--only ${name}] re-execution isolee...`);
  const suite = fn();
  console.log(`  ${suite.ok ? 'OK  ' : 'FAIL'} ${name}`);
  const idx = existing.suites.findIndex((s) => s.name === name);
  if (idx >= 0) existing.suites[idx] = suite; else existing.suites.push(suite);
  existing.exitCode = existing.suites.some((s) => !s.ok) ? 1 : 0;
  existing.verdict = existing.exitCode === 0 ? 'VERT' : 'ROUGE';
  existing.patchedAt = new Date().toISOString();
  existing.patchedSuite = name;
  fs.writeFileSync(reportPath, JSON.stringify(existing, null, 2) + '\n');
  console.log(`tests/reports/harness-latest.json mis a jour (suite '${name}' seule re-executee).`);
  process.exit(suite.ok ? 0 : 1);
}

// ---------------------------------------------------------------------------
// Runners
// ---------------------------------------------------------------------------

function runCapture(cmd, args, opts = {}) {
  const t0 = Date.now();
  const res = spawnSync(cmd, args, { cwd: ROOT, encoding: 'utf8', shell: true, ...opts });
  return {
    code: res.status === null ? 1 : res.status,
    stdout: res.stdout || '',
    stderr: res.stderr || '',
    durationMs: Date.now() - t0,
    crashed: res.error ? String(res.error) : null,
  };
}

function runLive(cmd, args, opts = {}) {
  const t0 = Date.now();
  const res = spawnSync(cmd, args, { cwd: ROOT, stdio: 'inherit', shell: true, ...opts });
  return { code: res.status === null ? 1 : res.status, durationMs: Date.now() - t0, crashed: res.error ? String(res.error) : null };
}

/** Parse les lignes "  ✓ Nom" / "[✗] Nom - detail" / "  ✗ GATE X — detail" (formats maison des scripts PHP du depot). */
function parseCheckLines(text) {
  const cases = [];
  for (const raw of strip(text).split(/\r?\n/)) {
    const m = raw.trim().match(/^\[?([✓✔✗✘])\]?\s*(.+)$/);
    if (!m) continue;
    cases.push({ name: m[2].trim(), ok: m[1] === '✓' || m[1] === '✔' });
  }
  return cases;
}

function suiteFromCapture(name, source, cap, { parse = true } = {}) {
  const cases = parse ? parseCheckLines(cap.stdout) : [];
  const failedCases = cases.filter((c) => !c.ok).map((c) => c.name);
  return {
    name,
    ok: cap.code === 0 && !cap.crashed,
    source,
    durationMs: cap.durationMs,
    testCount: cases.length || undefined,
    failures: cases.length ? failedCases.length : undefined,
    failedCases: failedCases.length ? failedCases : undefined,
    exitCode: cap.code,
    crashed: cap.crashed || undefined,
    stderrTail: !cap.crashed && cap.code !== 0 && cap.stderr ? strip(cap.stderr).slice(-2000) : undefined,
  };
}

// ---------------------------------------------------------------------------
// PHPUnit — JUnit XML -> cas nommes, puis suites par oracle
// ---------------------------------------------------------------------------

function parseJUnit(xmlPath) {
  if (!fs.existsSync(xmlPath)) return null;
  const xml = fs.readFileSync(xmlPath, 'utf8');
  const cases = [];
  const re = /<testcase\b([^>]*?)(?:\/>|>([\s\S]*?)<\/testcase>)/g;
  let m;
  while ((m = re.exec(xml))) {
    const attrs = {};
    const attrRe = /(\w+)="([^"]*)"/g;
    let am;
    while ((am = attrRe.exec(m[1]))) attrs[am[1]] = am[2].replace(/&quot;/g, '"').replace(/&apos;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
    const inner = m[2] || '';
    const status = /<failure\b|<error\b/.test(inner) ? 'failed' : /<skipped\b/.test(inner) ? 'skipped' : 'passed';
    cases.push({ class: attrs.class || '', name: attrs.name || '', time: parseFloat(attrs.time || '0'), status });
  }
  return cases;
}

function suiteFromCases(name, source, cases) {
  if (!cases || !cases.length) {
    return { name, ok: false, source, testCount: 0, failures: 0, note: 'aucun cas trouve (classe/methode absente du run PHPUnit)' };
  }
  const failedCases = cases.filter((c) => c.status === 'failed').map((c) => `${c.class.split('\\').pop()}::${c.name}`);
  const skipped = cases.filter((c) => c.status === 'skipped').length;
  return {
    name,
    ok: failedCases.length === 0,
    source,
    testCount: cases.length,
    failures: failedCases.length,
    skipped: skipped || undefined,
    failedCases: failedCases.length ? failedCases : undefined,
    durationMs: Math.round(cases.reduce((a, c) => a + c.time, 0) * 1000),
  };
}

// ---------------------------------------------------------------------------
// Playwright — JSON reporter -> suites par fichier de spec
// ---------------------------------------------------------------------------

function collectSpecs(node, out) {
  for (const s of node.specs || []) out.push(s);
  for (const sub of node.suites || []) collectSpecs(sub, out);
}

function suitesFromPlaywrightJson(jsonPath, activeNames) {
  if (!fs.existsSync(jsonPath)) return null;
  const report = J(path.relative(ROOT, jsonPath));
  if (!report) return null;
  const byFile = new Map();
  for (const topSuite of report.suites || []) {
    const leafs = [];
    collectSpecs(topSuite, leafs);
    for (const spec of leafs) {
      const key = (spec.file || topSuite.file || '').replace(/\\/g, '/').split('/').pop().replace(/\.spec\.ts$/, '');
      if (!byFile.has(key)) byFile.set(key, []);
      byFile.get(key).push(spec);
    }
  }
  const suites = [];
  for (const name of activeNames) {
    const specs = byFile.get(name);
    if (!specs) {
      suites.push({ name, ok: false, source: `tests/visual/specs/${name}.spec.ts`, note: 'non execute (absent du rapport Playwright)' });
      continue;
    }
    const failed = specs.filter((s) => !s.ok).map((s) => s.title);
    const durationMs = specs.reduce((a, s) => a + (s.tests || []).reduce((b, t) => b + (t.results || []).reduce((c, r) => c + (r.duration || 0), 0), 0), 0);
    suites.push({
      name,
      ok: failed.length === 0,
      source: `tests/visual/specs/${name}.spec.ts`,
      testCount: specs.length,
      failures: failed.length,
      failedCases: failed.length ? failed : undefined,
      durationMs: Math.round(durationMs),
    });
  }
  return suites;
}

// ---------------------------------------------------------------------------
// Orchestration
// ---------------------------------------------------------------------------

async function main() {
  const t0 = Date.now();
  fs.mkdirSync(P('tests', 'reports'), { recursive: true });
  const suites = [];
  const push = (s) => { suites.push(s); return s; };

  console.log('\n=== GEDv1 Harness — run-harness.mjs ===\n');

  // -- 1. Migration smoke --------------------------------------------------
  console.log('[1] Migration smoke...');
  const smoke = runCapture('php', ['tests/migration_smoke_test.php']);
  console.log(strip(smoke.stdout));
  push(suiteFromCapture('migration-smoke', 'tests/migration_smoke_test.php', smoke));

  // -- 1b. documents InnoDB -------------------------------------------------
  console.log('[1b] documents InnoDB (FK document_notes)...');
  const innodb = runCapture('php', ['tools/fix-documents-innodb.php']);
  console.log(strip(innodb.stdout));
  push(suiteFromCapture('documents-innodb', 'tools/fix-documents-innodb.php', innodb, { parse: false }));

  const vendorOk = fs.existsSync(P('vendor', 'autoload.php'));
  if (!vendorOk) {
    console.error('[ERREUR] vendor absent — composer install');
    push({ name: 'phpunit-all', ok: false, source: 'vendor/bin/phpunit', note: 'vendor absent — non execute' });
  } else {
    // -- 2. PHPUnit ---------------------------------------------------------
    console.log('\n[2] PHPUnit (toutes suites)...');
    const junitPath = P('tests', 'reports', 'phpunit-junit.xml');
    const pu = runLive('vendor\\bin\\phpunit', ['--colors=always', '--no-coverage', '--log-junit', 'tests/reports/phpunit-junit.xml']);
    const allCases = parseJUnit(junitPath) || [];
    push(suiteFromCases('phpunit-all', 'vendor/bin/phpunit (toutes suites tests/Unit + tests/Feature)', allCases));

    const byExactClass = (cls) => allCases.filter((c) => c.class === cls);
    const byMethods = (cls, methods) => allCases.filter((c) => c.class === cls && methods.includes(c.name));

    push(suiteFromCases('folder-permissions', 'tests/Unit/FolderPermissionTest.php', byExactClass('Tests\\Unit\\FolderPermissionTest')));
    push(suiteFromCases('thumbnails', 'tests/Unit/Services/ThumbnailGeneratorTest.php', byExactClass('KDocs\\Tests\\Unit\\Services\\ThumbnailGeneratorTest')));
    push(suiteFromCases('classifier-taxonomie', 'tests/Unit/Adapters/HtmleditorTaxonomyAdapterTest.php + tests/Unit/Services/DocumentTypeIdentificationTest.php',
      [...byExactClass('KDocs\\Tests\\Unit\\Adapters\\HtmleditorTaxonomyAdapterTest'), ...byExactClass('KDocs\\Tests\\Unit\\Services\\DocumentTypeIdentificationTest')]));
    push(suiteFromCases('soft-delete', 'tests/Unit/Services/TrashServiceTest.php (testSoftDeleteKeepsRecord, testDeletedAtTimestamp)',
      byMethods('Tests\\Unit\\Services\\TrashServiceTest', ['testSoftDeleteKeepsRecord', 'testDeletedAtTimestamp'])));
    push(suiteFromCases('trash-retention', 'tests/Unit/Services/TrashServiceTest.php (testTrashRetentionPeriod, testTrashPathIsConfigured)',
      byMethods('Tests\\Unit\\Services\\TrashServiceTest', ['testTrashRetentionPeriod', 'testTrashPathIsConfigured'])));
    push(suiteFromCases('ktime-contract', 'tests/Feature/KTimeContractTest.php', byExactClass('Tests\\Feature\\KTimeContractTest')));
    push(suiteFromCases('api-key-redaction', 'tests/Feature/ApiKeyRedactionTest.php', byExactClass('Tests\\Feature\\ApiKeyRedactionTest')));
    push(suiteFromCases('no-hard-delete', 'tests/Feature/NoHardDeleteTest.php (cliquet governance/budgets.json)', byExactClass('Tests\\Feature\\NoHardDeleteTest')));
    push(suiteFromCases('folder-permissions-serverside', 'tests/Feature/FolderPermissionServerSideTest.php (cablage ACL dans DocumentsApiController)', byExactClass('Tests\\Feature\\FolderPermissionServerSideTest')));

    // -- search-fulltext (script autonome, pas PHPUnit) ----------------------
    console.log('\n[2b] search-fulltext (tests/integration/test_fulltext_search.php)...');
    const ft = runCapture('php', ['tests/integration/test_fulltext_search.php']);
    console.log(strip(ft.stdout));
    push(suiteFromCapture('search-fulltext', 'tests/integration/test_fulltext_search.php', ft));

    // -- 3. eval-full ---------------------------------------------------------
    console.log('\n[3] eval-full (personas + types ECM + lot eval)...');
    const ev = runCapture('php', ['tools/eval-full.php', '--no-ocr']);
    console.log(strip(ev.stdout));
    push(suiteFromCapture('eval-full', 'tools/eval-full.php --no-ocr', ev));
  }

  // -- 4. Specs registry + Playwright --------------------------------------
  console.log('\n[4] Registre des specs Playwright...');
  const reg = checkSpecsRegistry();
  if (!reg.ok) {
    console.error(`ECHEC registre — spec(s) sur disque absente(s) de governance/specs.json : ${reg.missing.join(', ')}`);
    push({ name: 'specs-registre', ok: false, source: 'governance/specs.json vs tests/visual/specs/*.spec.ts', failedCases: reg.missing, note: 'Playwright non execute : registre incoherent avec le disque.' });
  } else {
    console.log(`OK — ${reg.onDisk.length} spec(s) sur disque, ${reg.active.length} active(s), ${reg.retired.length} retiree(s).`);
    push({ name: 'specs-registre', ok: true, source: 'governance/specs.json vs tests/visual/specs/*.spec.ts', testCount: reg.onDisk.length });

    console.log(`\n[5] Playwright — ${reg.active.length} spec(s) active(s)...`);
    const jsonOut = P('tests', 'reports', 'playwright-latest.json');
    const visualDir = P('tests', 'visual');
    if (!fs.existsSync(path.join(visualDir, 'node_modules'))) {
      runLive('npm', ['install'], { cwd: visualDir });
      runLive('npx', ['playwright', 'install', 'chromium'], { cwd: visualDir });
    }
    const specArgs = reg.active.map((n) => `specs/${n}.spec.ts`);
    const pw = runLive('npx', ['playwright', 'test', ...specArgs, '--reporter=list,json'], {
      cwd: visualDir,
      env: {
        ...process.env,
        KDOCS_HOST: process.env.KDOCS_HOST || '127.0.0.1',
        KDOCS_PORT: process.env.KDOCS_PORT || '8765',
        KDOCS_BASE_PATH: process.env.KDOCS_BASE_PATH || '/kdocs',
        PLAYWRIGHT_JSON_OUTPUT_NAME: jsonOut,
      },
    });
    const pwSuites = suitesFromPlaywrightJson(jsonOut, reg.active);
    if (!pwSuites) {
      push({ name: 'playwright', ok: false, source: 'tests/visual (npx playwright test)', note: `Playwright n'a produit aucun rapport JSON (crash avant tout test ? code=${pw.code}).`, exitCode: pw.code });
    } else {
      for (const s of pwSuites) push(s);
      // Alias oracle : ui-chrome couvre la coherence du chrome UI (sidebar/emojis/compteurs).
      const chrome = pwSuites.find((s) => s.name === 'chrome-coherence');
      if (chrome) push({ ...chrome, name: 'ui-chrome', source: `alias de chrome-coherence — ${chrome.source}` });
    }
  }

  // -- Rapport --------------------------------------------------------------
  const exitCode = suites.some((s) => !s.ok) ? 1 : 0;
  const report = {
    generatedAt: new Date().toISOString(),
    verdict: exitCode === 0 ? 'VERT' : 'ROUGE',
    exitCode,
    durationMs: Date.now() - t0,
    suites,
  };
  fs.writeFileSync(P('tests', 'reports', 'harness-latest.json'), JSON.stringify(report, null, 2) + '\n');

  console.log('\n' + '='.repeat(72));
  for (const s of suites) console.log(`  ${s.ok ? 'OK  ' : 'FAIL'} ${s.name}${s.failures ? ` (${s.failures}/${s.testCount} en echec)` : ''}`);
  console.log('='.repeat(72));
  console.log(`\n=== Harness complet : ${report.verdict} (${suites.filter((s) => s.ok).length}/${suites.length} suites vertes) ===\n`);
  process.exit(exitCode);
}

main();

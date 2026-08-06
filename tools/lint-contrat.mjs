#!/usr/bin/env node
/**
 * lint-contrat — gate externe (regle 9) pour le contrat /api/ged/* GED <-> K-Time.
 *
 * La verite ne vient PAS du code GEDv1. Ce script confronte governance/contrat-ged-ktime.json
 * a trois sources, dont deux exterieures a ce depot :
 *
 *   1. Le depot K-Time sur disque, en lecture seule (KTIME_REPO/k-time-web/src/routes.php)
 *      -> routes declarees + presence dans $apiKeyRoutes. SKIP explicite si le depot est absent.
 *   2. Le serveur K-Time vivant (KTIME_URL) : GET /api/ged/health doit repondre 200, avec
 *      KTIME_GED_API_KEY lu depuis le .env de GEDv1. SKIP explicite si KTIME_URL est absent
 *      du .env — pas un succes silencieux, un mock ne prouve rien.
 *   3. Le code interne GEDv1 : les appels reellement presents dans
 *      apps/erpconnect/Services/KTimeClient.php.
 *
 * Sortie 1 des qu'il y a divergence (route au contrat absente de K-Time, route K-Time absente
 * du contrat, methode client appelant un chemin hors contrat, health en echec).
 *
 * INTERDICTION ABSOLUE : KTIME_GED_API_KEY n'apparait jamais dans la sortie console ni dans
 * le rapport ecrit — ni meme partiellement.
 *
 *   node tools/lint-contrat.mjs
 *
 * Config env (facultative) :
 *   KTIME_REPO   racine du depot K-Time en lecture seule (defaut F:/DATA/DEVELOPPEMENT/K-TIME)
 */
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(new URL('..', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'));
const P = (...a) => path.join(ROOT, ...a);

const CONTRACT_PATH = 'governance/contrat-ged-ktime.json';
const REPORT_PATH = 'tests/reports/contrat-ged-latest.json';
const KTIME_ROUTES_REL = 'k-time-web/src/routes.php';

// -----------------------------------------------------------------------------------------
// Helpers generiques
// -----------------------------------------------------------------------------------------

/** Parse minimal d'un fichier .env (KEY=VALUE, commentaires #, guillemets optionnels). */
function parseEnvFile(filePath) {
  const out = {};
  if (!fs.existsSync(filePath)) return out;
  const text = fs.readFileSync(filePath, 'utf8');
  for (const rawLine of text.split(/\r?\n/)) {
    const line = rawLine.trim();
    if (line === '' || line.startsWith('#')) continue;
    const eq = line.indexOf('=');
    if (eq === -1) continue;
    const key = line.slice(0, eq).trim();
    let val = line.slice(eq + 1).trim();
    if ((val.startsWith('"') && val.endsWith('"')) || (val.startsWith("'") && val.endsWith("'"))) {
      val = val.slice(1, -1);
    }
    out[key] = val;
  }
  return out;
}

function readJson(relPath) {
  return JSON.parse(fs.readFileSync(P(relPath), 'utf8'));
}

// -----------------------------------------------------------------------------------------
// Source externe 1 — depot K-Time sur disque (lecture seule)
// -----------------------------------------------------------------------------------------

/**
 * Extrait les routes /api/ged/* declarees dans src/routes.php :
 *  - le tableau principal ['METHOD', '/path', 'Controller', 'action']
 *  - le tableau $apiKeyRoutes ('METHOD:/path')
 */
function parseKTimeRoutes(routesFilePath) {
  const text = fs.readFileSync(routesFilePath, 'utf8');

  const declared = [];
  const mainRe = /\[\s*'(GET|POST|PUT|DELETE|PATCH)'\s*,\s*'(\/api\/ged\/[^']*)'\s*,\s*'(\w+)'\s*,\s*'(\w+)'\s*\]/g;
  let m;
  while ((m = mainRe.exec(text)) !== null) {
    declared.push({ method: m[1], path: m[2], controller: m[3], action: m[4] });
  }

  const apiKeySet = new Set();
  const apiKeyRe = /'(GET|POST|PUT|DELETE|PATCH):(\/api\/ged\/[^']*)'/g;
  while ((m = apiKeyRe.exec(text)) !== null) {
    apiKeySet.add(`${m[1]}:${m[2]}`);
  }

  return { declared, apiKeySet };
}

// -----------------------------------------------------------------------------------------
// Source interne — apps/erpconnect/Services/KTimeClient.php
// -----------------------------------------------------------------------------------------

/**
 * Extrait, pour chaque methode publique du client, le couple {method, path} reellement
 * envoye a $this->request(...). Les variables interpolees dans le chemin ('.$id.') sont
 * normalisees en '{id}' pour se comparer au gabarit du contrat / de routes.php.
 */
function parseClientCalls(clientFilePath) {
  const text = fs.readFileSync(clientFilePath, 'utf8');

  // Decoupe la classe en blocs par methode publique.
  const methodRe = /public function\s+(\w+)\s*\(/g;
  const boundaries = [];
  let m;
  while ((m = methodRe.exec(text)) !== null) {
    boundaries.push({ name: m[1], start: m.index });
  }
  boundaries.push({ name: null, start: text.length }); // sentinelle de fin

  const calls = [];
  const requestRe = /->request\(\s*'(GET|POST|PUT|DELETE|PATCH)'\s*,\s*((?:\s*\.?\s*'[^']*'|\s*\.\s*\$[A-Za-z_][A-Za-z0-9_]*)+)/;

  for (let i = 0; i < boundaries.length - 1; i++) {
    const { name, start } = boundaries[i];
    const end = boundaries[i + 1].start;
    const chunk = text.slice(start, end);

    const found = requestRe.exec(chunk);
    if (!found) continue;

    const httpMethod = found[1];
    const rawExpr = found[2];

    // Reconstruit le chemin en remplacant chaque fragment $var par {id}.
    const pieces = [];
    const pieceRe = /'([^']*)'|\$([A-Za-z_][A-Za-z0-9_]*)/g;
    let p;
    while ((p = pieceRe.exec(rawExpr)) !== null) {
      pieces.push(p[1] !== undefined ? p[1] : '{id}');
    }
    let normalizedPath = pieces.join('');
    // Coupe une eventuelle query string ('...?' . $qs).
    const qIdx = normalizedPath.indexOf('?');
    if (qIdx !== -1) normalizedPath = normalizedPath.slice(0, qIdx);

    calls.push({ clientMethod: name, method: httpMethod, path: normalizedPath });
  }

  return calls;
}

// -----------------------------------------------------------------------------------------
// Source externe 2 — serveur K-Time vivant
// -----------------------------------------------------------------------------------------

async function checkLiveHealth(ktimeUrl, apiKey) {
  const url = `${ktimeUrl.replace(/\/$/, '')}/api/ged/health`;
  try {
    const res = await fetch(url, {
      method: 'GET',
      headers: {
        Accept: 'application/json',
        ...(apiKey ? { 'X-Api-Key': apiKey } : {}),
      },
      signal: AbortSignal.timeout(5000),
    });
    return { ok: res.status === 200, status: res.status };
  } catch (err) {
    return { ok: false, status: 0, error: err?.code || err?.message || 'erreur reseau' };
  }
}

// -----------------------------------------------------------------------------------------
// Main
// -----------------------------------------------------------------------------------------

async function main() {
  const results = []; // { name, status: 'ok'|'skip'|'fail', detail }
  const divergences = [];
  let hasFailure = false;

  const contract = readJson(CONTRACT_PATH);
  const routes = contract.routes;

  const env = parseEnvFile(P('.env'));
  const ktimeRepo = process.env.KTIME_REPO || 'F:/DATA/DEVELOPPEMENT/K-TIME';
  const ktimeRoutesFile = path.join(ktimeRepo, KTIME_ROUTES_REL);

  // --- Check 1 : depot K-Time sur disque -------------------------------------------------
  if (!fs.existsSync(ktimeRoutesFile)) {
    results.push({
      name: 'ktime-repo-routes',
      status: 'skip',
      detail: `depot K-Time introuvable (${ktimeRoutesFile}) — controle SKIP, pas un succes`,
    });
  } else {
    const { declared, apiKeySet } = parseKTimeRoutes(ktimeRoutesFile);
    const declaredIndex = new Map(declared.map((r) => [`${r.method}:${r.path}`, r]));
    const contractIndex = new Set(routes.map((r) => `${r.method}:${r.path}`));

    let ok = true;
    for (const r of routes) {
      const key = `${r.method}:${r.path}`;
      const found = declaredIndex.get(key);
      if (!found) {
        ok = false;
        divergences.push(`contrat -> K-Time : route ${key} absente de ${KTIME_ROUTES_REL}`);
        continue;
      }
      if (found.controller !== r.ktime.controller || found.action !== r.ktime.action) {
        ok = false;
        divergences.push(
          `contrat -> K-Time : ${key} pointe vers ${found.controller}::${found.action} ` +
          `mais le contrat declare ${r.ktime.controller}::${r.ktime.action}`
        );
      }
      if (r.requires_api_key && !apiKeySet.has(key)) {
        ok = false;
        divergences.push(`contrat -> K-Time : ${key} absente de $apiKeyRoutes (auth par cle attendue)`);
      }
    }
    // Routes K-Time /api/ged/* non couvertes par le contrat.
    for (const d of declared) {
      const key = `${d.method}:${d.path}`;
      if (!contractIndex.has(key)) {
        ok = false;
        divergences.push(`K-Time -> contrat : route ${key} (${d.controller}::${d.action}) absente du contrat`);
      }
    }

    results.push({
      name: 'ktime-repo-routes',
      status: ok ? 'ok' : 'fail',
      detail: ok
        ? `${declared.length} route(s) /api/ged/* trouvee(s) dans ${KTIME_ROUTES_REL}, conformes au contrat`
        : `${declared.length} route(s) trouvee(s) — divergences detectees (voir ci-dessous)`,
    });
    if (!ok) hasFailure = true;
  }

  // --- Check 2 : serveur K-Time vivant ----------------------------------------------------
  const ktimeUrl = env.KTIME_URL;
  if (!ktimeUrl) {
    results.push({
      name: 'ktime-live-health',
      status: 'skip',
      detail: 'KTIME_URL absent du .env — controle SKIP, pas un succes',
    });
  } else {
    const apiKeyPresent = Boolean(env.KTIME_GED_API_KEY);
    const health = await checkLiveHealth(ktimeUrl, env.KTIME_GED_API_KEY);
    const ok = health.ok;
    results.push({
      name: 'ktime-live-health',
      status: ok ? 'ok' : 'fail',
      detail: ok
        ? `GET ${ktimeUrl}/api/ged/health -> 200 (cle API ${apiKeyPresent ? 'presente' : 'absente'} dans .env)`
        : `GET ${ktimeUrl}/api/ged/health -> ${health.status}${health.error ? ' (' + health.error + ')' : ''} — attendu 200`,
    });
    if (!ok) {
      hasFailure = true;
      divergences.push(`serveur K-Time vivant : health check en echec (statut ${health.status})`);
    }
  }

  // --- Check 3 : code interne GEDv1 (KTimeClient.php) -------------------------------------
  const clientFile = P('apps/erpconnect/Services/KTimeClient.php');
  {
    const calls = parseClientCalls(clientFile);
    const callsByMethod = new Map(calls.map((c) => [c.clientMethod, c]));
    const contractPaths = new Set(routes.map((r) => `${r.method}:${r.path}`));

    let ok = true;
    for (const r of routes) {
      const call = callsByMethod.get(r.client_method);
      if (!call) {
        ok = false;
        divergences.push(`contrat -> client : methode ${r.client_method}() introuvable dans KTimeClient.php`);
        continue;
      }
      if (call.method !== r.method || call.path !== r.path) {
        ok = false;
        divergences.push(
          `contrat -> client : ${r.client_method}() appelle ${call.method} ${call.path} ` +
          `mais le contrat declare ${r.method} ${r.path}`
        );
      }
    }
    for (const c of calls) {
      const key = `${c.method}:${c.path}`;
      if (!contractPaths.has(key)) {
        ok = false;
        divergences.push(`client -> contrat : ${c.clientMethod}() appelle ${key}, absent du contrat`);
      }
    }

    results.push({
      name: 'client-interne',
      status: ok ? 'ok' : 'fail',
      detail: ok
        ? `${calls.length} appel(s) trouve(s) dans KTimeClient.php, conformes au contrat`
        : `${calls.length} appel(s) trouve(s) — divergences detectees (voir ci-dessous)`,
    });
    if (!ok) hasFailure = true;
  }

  // --- Sortie lisible ----------------------------------------------------------------------
  const symbol = { ok: '[OK]  ', fail: '[FAIL]', skip: '[SKIP]' };
  console.log('lint-contrat — GEDv1 <-> K-Time /api/ged/*\n');
  for (const r of results) {
    console.log(`${symbol[r.status]} ${r.name} — ${r.detail}`);
  }

  if (divergences.length > 0) {
    console.log('\nDivergences :');
    for (const d of divergences) console.log(`  - ${d}`);
  }

  const anySkip = results.some((r) => r.status === 'skip');
  const verdict = hasFailure ? 'DIVERGENT' : anySkip ? 'OK (avec controle(s) SKIP)' : 'OK';
  console.log(`\nVerdict final : ${verdict}`);

  // --- Rapport machine -----------------------------------------------------------------
  fs.mkdirSync(P('tests/reports'), { recursive: true });
  const report = {
    timestamp: new Date().toISOString(),
    contract_version: contract.version,
    ktime_repo: ktimeRepo,
    checks: results,
    divergences,
    verdict: hasFailure ? 'DIVERGENT' : anySkip ? 'OK_AVEC_SKIPS' : 'OK',
  };
  fs.writeFileSync(P(REPORT_PATH), JSON.stringify(report, null, 2) + '\n', 'utf8');

  process.exit(hasFailure ? 1 : 0);
}

main().catch((err) => {
  console.error('lint-contrat: erreur inattendue —', err?.message || err);
  process.exit(1);
});

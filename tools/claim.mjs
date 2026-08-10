#!/usr/bin/env node
/**
 * claim — reservation d items entre sessions claude-s paralleles.
 *
 * Deux sessions qui tournent en meme temps doivent se voir. Un item pris est
 * pris. La regle reelle : un processus VIVANT n'est jamais repute mort, quel
 * que soit l'age de son dernier battement — le TTL de 20 min ne juge que ce
 * qu'on ne peut pas verifier autrement (pid absent du format, ou pid porte
 * par une autre machine). C'est le filet, pas le juge de premiere instance.
 *
 * Constat du 2026-08-10 : une reservation prise pour un lot de 16 min a
 * disparu de `list` au bout de 20 min alors que le lot tournait toujours, et
 * `gc()` a efface le fichier — une autre session pouvait reprendre l'item en
 * cours de travail. D'ou l'ancrage sur un pid proprietaire : tant que ce pid
 * repond, la reservation reste vivante.
 *
 * Deuxieme constat, le meme jour, sur le premier correctif : ancrer sur
 * `process.pid` (le CLI `node tools/claim.mjs take` lui-meme) ne protege
 * rien, puisque ce processus se termine quelques millisecondes apres avoir
 * ecrit le fichier — a la lecture suivante le pid enregistre est deja mort,
 * et le comportement retombe silencieusement sur le TTL seul. Le proprietaire
 * reel n'est pas le CLI, c'est la session `claude.exe` qui l'a lance : `take`
 * remonte la chaine des parents (`Win32_Process` via CIM, 10 niveaux max)
 * jusqu'a trouver un ancetre nomme `claude.exe`, et enregistre CE pid — pas
 * le sien. Si aucune session claude n'est trouvee (invocation manuelle,
 * sonde, CI), aucun pid n'est enregistre : c'est correct, il n'y a pas de
 * session a proteger, et le TTL tranche seul.
 *
 * Le pid recycle par un autre processus est demasque : la reservation porte
 * aussi `pid_name` (« claude.exe »), et un pid qui repond mais dont le nom a
 * change n'est plus considere vivant. `list`, `gc`, `beat`, `release` ne
 * remontent jamais de chaine — ils se contentent de sonder le pid deja
 * enregistre (existence + nom). Seul `take` paie le cout d'un aller-retour
 * CIM, et `take` est rare.
 *
 *   node tools/claim.mjs list
 *   node tools/claim.mjs take <item>      exit 0 pris · exit 1 deja pris
 *   node tools/claim.mjs beat <item>
 *   node tools/claim.mjs release <item> [etat] [note...]
 *   node tools/claim.mjs gc
 *
 * Identite de session : $env:KS_AGENT (pose par claude-s.bat).
 * Repertoire de claims : $env:KS_CLAIMS_DIR (defaut governance/claims) —
 * permet a une sonde de travailler sur un repertoire jetable sans jamais
 * toucher aux reservations reelles d'une session en cours.
 */
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { spawnSync } from 'node:child_process';

const ROOT = path.resolve(new URL('..', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'));
const DIR = process.env.KS_CLAIMS_DIR
  ? path.resolve(process.env.KS_CLAIMS_DIR)
  : path.join(ROOT, 'governance', 'claims');
const PERIME_MS = 20 * 60 * 1000;
const AGENT = process.env.KS_AGENT || `${os.hostname()}-${process.pid}`;
const [cmd, item, ...rest] = process.argv.slice(2);

fs.mkdirSync(DIR, { recursive: true });
const f = (i) => path.join(DIR, i.replace(/[^a-zA-Z0-9._-]/g, '_') + '.json');
const lire = (p) => { try { return JSON.parse(fs.readFileSync(p, 'utf8')); } catch { return null; } };
const age = (c) => Date.now() - new Date(c.beat || c.pris).getTime();
const min = (ms) => Math.round(ms / 60000);

// Remonte la chaine des parents du processus courant (celui qui execute
// `take`) jusqu'a trouver un ancetre nomme claude.exe — la session qui doit
// posseder la reservation, pas le CLI qui l'ecrit et meurt aussitot. Borne a
// 10 niveaux. N'est appele qu'a `take`, qui est rare : le cout d'un
// aller-retour CIM y est acceptable. Rend null si aucune session claude.exe
// n'est trouvee (execution manuelle, sonde, CI, plateforme non Windows) —
// c'est un cas legitime, pas un repli honteux : take n'enregistre alors pas
// de pid, et le TTL tranche seul, comme avant tout ce correctif.
function trouverSessionClaude() {
  if (process.platform !== 'win32') return null;
  const script = [
    `$cur = ${process.ppid}`,
    'for ($i = 0; $i -lt 10; $i++) {',
    '  $p = Get-CimInstance Win32_Process -Filter "ProcessId=$cur" -ErrorAction SilentlyContinue',
    '  if (-not $p) { break }',
    "  if ($p.Name -eq 'claude.exe') { Write-Output \"$($p.ProcessId)|$($p.Name)\"; exit }",
    '  $cur = $p.ParentProcessId',
    '  if (-not $cur) { break }',
    '}',
  ].join('\n');
  try {
    const r = spawnSync(
      'powershell.exe',
      ['-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', script],
      { encoding: 'utf8', timeout: 5000 }
    );
    const out = (r.stdout || '').trim();
    if (!out) return null;
    const [pidStr, name] = out.split('|');
    const pid = Number(pidStr);
    return pid && name ? { pid, name } : null;
  } catch {
    return null;
  }
}

// Nom du processus portant ce pid, ou null si indetectable (outil absent,
// processus deja disparu entre-temps, etc). Utilise tasklist plutot que CIM :
// c'est le controle qui tourne a chaque list/gc/beat/release, il doit rester
// leger.
function nomDuProcessus(pid) {
  if (process.platform !== 'win32') return null;
  try {
    const r = spawnSync('tasklist.exe', ['/FI', `PID eq ${pid}`, '/NH', '/FO', 'CSV'], { encoding: 'utf8', timeout: 3000 });
    const m = (r.stdout || '').trim().match(/^"([^"]+)"/);
    return m ? m[1] : null;
  } catch {
    return null;
  }
}

// true : le processus proprietaire repond, et porte toujours le nom attendu
//        (quand ce nom est connu) — donc vivant.
// false : le pid est verifiable (meme machine) et ne repond pas, ou repond
//         mais sous un autre nom (pid recycle par un processus different) —
//         donc mort.
// null : indetectable (pas de pid dans le format, ou pid d'une autre machine) —
//        le TTL tranche seul, comme avant.
function processVivant(c) {
  if (!c.pid) return null;
  if (c.host && c.host !== os.hostname()) return null;
  try {
    process.kill(c.pid, 0); // signal 0 : sonde d'existence, ne tue rien.
  } catch {
    return false;
  }
  if (c.pid_name) {
    const nomActuel = nomDuProcessus(c.pid);
    // nomActuel === null : indetectable (tasklist absent, course entre la
    // sonde et la lecture, etc). On ne peut pas demasquer un recyclage dans
    // ce cas, mais le pid repond bel et bien : on considere vivant.
    if (nomActuel !== null && nomActuel !== c.pid_name) return false;
  }
  return true;
}

// Une reservation dont le processus est vivant n'est JAMAIS perimee, quel
// que soit son age. Sinon (processus mort ou indetectable), le TTL de 20 min
// juge seul — inchange par rapport au comportement d'origine.
function perimee(c) {
  if (processVivant(c) === true) return false;
  return age(c) >= PERIME_MS;
}

function actifs() {
  return fs.readdirSync(DIR).filter((x) => x.endsWith('.json'))
    .map((x) => ({ file: path.join(DIR, x), c: lire(path.join(DIR, x)) }))
    .filter((x) => x.c && !perimee(x.c));
}

function gc() {
  let n = 0;
  for (const x of fs.readdirSync(DIR).filter((y) => y.endsWith('.json'))) {
    const p = path.join(DIR, x); const c = lire(p);
    if (!c || perimee(c)) { fs.unlinkSync(p); n++; }
  }
  return n;
}

if (cmd === 'gc') { console.log(`${gc()} reservation(s) perimee(s) liberee(s).`); process.exit(0); }

if (cmd === 'list') {
  gc();
  const a = actifs();
  if (!a.length) { console.log('Aucun item reserve. Tout est libre.'); process.exit(0); }
  console.log('Items reserves (y compris les miens, marques (moi)) :');
  for (const { c } of a) {
    const moi = c.agent === AGENT ? ' (moi)' : '';
    console.log(`  ${c.item.padEnd(28)} ${c.agent}${moi}  depuis ${min(age(c))} min`);
  }
  process.exit(0);
}

if (!item) { console.error('item manquant'); process.exit(2); }
gc();

if (cmd === 'take') {
  const p = f(item); const c = lire(p);
  if (c && !perimee(c) && c.agent !== AGENT) {
    console.log(`PRIS ${item} — par ${c.agent} depuis ${min(age(c))} min. Prends-en un autre.`);
    process.exit(1);
  }
  const record = {
    item, agent: AGENT, pris: new Date().toISOString(), beat: new Date().toISOString(),
    host: os.hostname(),
  };
  const session = trouverSessionClaude();
  if (session) { record.pid = session.pid; record.pid_name = session.name; }
  fs.writeFileSync(p, JSON.stringify(record, null, 2) + '\n');
  console.log(`OK ${item} reserve par ${AGENT}.`);
  process.exit(0);
}

if (cmd === 'beat') {
  const p = f(item); const c = lire(p);
  if (!c) { console.error('reservation absente'); process.exit(1); }
  c.beat = new Date().toISOString();
  fs.writeFileSync(p, JSON.stringify(c, null, 2) + '\n');
  process.exit(0);
}

if (cmd === 'release') {
  const p = f(item); const c = lire(p);
  if (!c) {
    console.log(`ABSENTE ${item} — deja liberee ou perimee`);
    process.exit(0);
  }
  if (!perimee(c) && c.agent !== AGENT) {
    console.error(`REFUS ${item} — reservation vivante de ${c.agent}, pas la tienne (${AGENT}).`);
    process.exit(1);
  }
  fs.unlinkSync(p);
  console.log(`LIBERE ${item}${rest.length ? ' — ' + rest.join(' ') : ''}`);
  process.exit(0);
}

console.error('usage: list | take <item> | beat <item> | release <item> [etat] [note] | gc');
process.exit(2);
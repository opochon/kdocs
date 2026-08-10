#!/usr/bin/env node
/**
 * Oracle du secteur mesurabilite — la reservation entre sessions paralleles
 * (tools/claim.mjs).
 *
 * Deux defauts constates le 2026-08-10 :
 *
 *   D1 — une reservation meurt en silence pendant un lot long. `PERIME_MS`
 *        vaut 20 min et rien n'emet de battement automatique ; une session
 *        qui travaille 16 min sur un item le voit disparaitre de `list` a
 *        20 min, et `gc()` efface le fichier — une autre session peut alors
 *        prendre le meme item PENDANT que le premier lot tourne encore.
 *
 *   D2 — `release` affirme LIBERE sans avoir rien verifie : sur un item deja
 *        parti (jamais pris, ou deja libere), et pire, sur la reservation
 *        VIVANTE d'un autre agent, qu'il efface sans controle.
 *
 * Piege constate sur un premier correctif de D1 (meme jour) : ancrer la
 * reservation sur `process.pid`, c'est ancrer sur le CLI `take` lui-meme, qui
 * meurt quelques millisecondes apres avoir ecrit le fichier. Une sonde qui
 * fabrique elle-meme ses fichiers de reservation avec un pid choisi vivant
 * (le sien) ne peut jamais voir ce defaut — sa verite vient des fichiers
 * qu'elle a ecrits, pas du chemin reel (regle 7). La section 0 ci-dessous
 * corrige cela : elle appelle `take` pour de vrai et relit ce qu'il a ecrit.
 *
 * Cette sonde execute claim.mjs en sous-processus reel (spawnSync), sur un
 * repertoire de claims JETABLE (KS_CLAIMS_DIR) — jamais governance/claims/
 * du depot, qu'une session peut utiliser en meme temps que cette sonde.
 *
 * Usage : node tests/integration/test-claim-reservations.mjs
 */

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..', '..');
const CLAIM = path.join(ROOT, 'tools', 'claim.mjs');

console.log('');
console.log('+==============================================================+');
console.log('|   K-DOCS - RESERVATIONS ENTRE SESSIONS (tools/claim.mjs)     |');
console.log('+==============================================================+');
console.log('');

let passed = 0;
let failed = 0;

function test(name, ok, detail = '') {
  const tag = ok ? '\x1b[32m[OK]\x1b[0m' : '\x1b[31m[KO]\x1b[0m';
  console.log(`${tag} ${name}${detail ? ' - ' + detail : ''}`);
  ok ? passed++ : failed++;
  return ok;
}

// ---------------------------------------------------------------------------
// Bac a sable jetable — jamais governance/claims/ reel.
// ---------------------------------------------------------------------------
const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'kdocs-claim-test-'));

function claimFile(item) {
  return path.join(tmpDir, item.replace(/[^a-zA-Z0-9._-]/g, '_') + '.json');
}

function ecrireReservation(item, data) {
  fs.writeFileSync(claimFile(item), JSON.stringify({ item, ...data }, null, 2) + '\n');
}

function claim(args, agent) {
  const env = { ...process.env, KS_CLAIMS_DIR: tmpDir, KS_AGENT: agent };
  const r = spawnSync(process.execPath, [CLAIM, ...args], { cwd: ROOT, env, encoding: 'utf8' });
  return { code: r.status, out: `${r.stdout || ''}${r.stderr || ''}` };
}

const il_y_a = (min) => new Date(Date.now() - min * 60 * 1000).toISOString();

try {
  // -------------------------------------------------------------------------
  console.log('--- 0. take EN CONDITIONS REELLES (chemin complet, pas fabrique) ---');
  console.log('');

  const itemE2E = 'lot-0-take-reel';
  const takeE2E = claim(['take', itemE2E], 'agent-E2E');
  test(
    'take (chemin reel) reussit et ecrit une reservation',
    takeE2E.code === 0 && takeE2E.out.includes('OK') && fs.existsSync(claimFile(itemE2E)),
    `exit=${takeE2E.code} out=${takeE2E.out.trim()}`
  );

  // Le processus `node tools/claim.mjs take ...` est deja termine ici :
  // spawnSync a attendu sa fin avant de rendre la main. Si le pid enregistre
  // etait le sien (process.pid, le bug constate), il serait deja mort.
  const recordE2E = fs.existsSync(claimFile(itemE2E)) ? JSON.parse(fs.readFileSync(claimFile(itemE2E), 'utf8')) : null;
  const pidE2E = recordE2E ? recordE2E.pid : undefined;

  test(
    'la reservation ecrite porte un pid',
    typeof pidE2E === 'number',
    recordE2E ? `pid=${pidE2E} pid_name=${recordE2E.pid_name || '?'}` : 'fichier absent'
  );

  let pidE2EVivant = false;
  if (typeof pidE2E === 'number') {
    try { process.kill(pidE2E, 0); pidE2EVivant = true; } catch { pidE2EVivant = false; }
  }
  test(
    'le pid enregistre par take reste vivant APRES la fin du processus node qui l a ecrit ' +
    '(preuve que ce n est pas le pid du CLI take, mort en quelques ms)',
    typeof pidE2E === 'number' && pidE2EVivant,
    typeof pidE2E === 'number' ? `pid=${pidE2E} vivant=${pidE2EVivant}` : 'pas de pid a verifier'
  );

  claim(['release', itemE2E], 'agent-E2E');

  // -------------------------------------------------------------------------
  console.log('');
  console.log('--- 1. D1 : PROCESSUS VIVANT, BEAT VIEUX ---');
  console.log('');

  const itemVivant = 'lot-d1-pid-vivant';
  ecrireReservation(itemVivant, {
    agent: 'agent-A',
    pris: il_y_a(25),
    beat: il_y_a(25), // > PERIME_MS (20 min)
    pid: process.pid, // notre propre pid : garanti vivant pendant la sonde
    host: os.hostname(),
  });

  const liste1 = claim(['list'], 'agent-B');
  test(
    'Une reservation de 25 min avec pid vivant reste visible dans list',
    liste1.out.includes(itemVivant),
    liste1.out.trim().split('\n').slice(0, 3).join(' | ')
  );

  const take1 = claim(['take', itemVivant], 'agent-B');
  test(
    'take par un autre agent est refuse tant que le pid proprietaire est vivant',
    take1.code === 1 && take1.out.includes('PRIS'),
    `exit=${take1.code} out=${take1.out.trim()}`
  );

  // -------------------------------------------------------------------------
  console.log('');
  console.log('--- 2. LE FILET : PID MORT, BEAT VIEUX ---');
  console.log('');

  const enfantMort = spawnSync(process.execPath, ['-e', 'process.exit(0)']);
  const pidMort = enfantMort.pid;

  const itemMort = 'lot-d1-pid-mort';
  ecrireReservation(itemMort, {
    agent: 'agent-A',
    pris: il_y_a(25),
    beat: il_y_a(25),
    pid: pidMort, // processus deja termine : garanti mort
    host: os.hostname(),
  });

  test('Precondition — le fichier de reservation existe avant gc', fs.existsSync(claimFile(itemMort)));

  claim(['gc'], 'agent-B');
  test(
    'pid mort + beat vieux : gc collecte toujours la reservation (le filet fonctionne)',
    !fs.existsSync(claimFile(itemMort))
  );

  // -------------------------------------------------------------------------
  console.log('');
  console.log('--- 3. COMPATIBILITE : FORMAT ANCIEN SANS PID ---');
  console.log('');

  const itemAncien = 'lot-d1-format-ancien';
  ecrireReservation(itemAncien, {
    agent: 'agent-A',
    pris: il_y_a(25),
    beat: il_y_a(25),
    // pas de champ pid : format d'avant la correction
  });

  const liste3 = claim(['list'], 'agent-B');
  test(
    'reservation ancienne sans pid, beat vieux : absente de list (le TTL seul tranche)',
    !liste3.out.includes(itemAncien)
  );

  const take3 = claim(['take', itemAncien], 'agent-B');
  test(
    'take reussit sur une reservation ancienne perimee par le TTL (compat format)',
    take3.code === 0 && take3.out.includes('OK'),
    `exit=${take3.code} out=${take3.out.trim()}`
  );
  claim(['release', itemAncien], 'agent-B'); // nettoyage, deja verifie en section 4

  // -------------------------------------------------------------------------
  console.log('');
  console.log('--- 4. D2 : release SUR UN ITEM ABSENT ---');
  console.log('');

  const relAbsente = claim(['release', 'jamais-pris'], 'agent-Z');
  test(
    'release sur un item jamais pris rend ABSENTE (pas LIBERE), exit 0',
    relAbsente.code === 0 && relAbsente.out.includes('ABSENTE') && !relAbsente.out.includes('LIBERE'),
    `exit=${relAbsente.code} out=${relAbsente.out.trim()}`
  );

  // -------------------------------------------------------------------------
  console.log('');
  console.log('--- 5. D2 : release SUR LA RESERVATION VIVANTE D UN AUTRE AGENT ---');
  console.log('');

  const itemAutrui = 'lot-d2-vivant-autrui';
  ecrireReservation(itemAutrui, {
    agent: 'agent-A',
    pris: new Date().toISOString(),
    beat: new Date().toISOString(),
    pid: process.pid,
    host: os.hostname(),
  });

  const relRefus = claim(['release', itemAutrui], 'agent-B');
  test(
    'release refuse la reservation vivante d un autre agent, exit 1',
    relRefus.code === 1 && relRefus.out.includes('REFUS'),
    `exit=${relRefus.code} out=${relRefus.out.trim()}`
  );
  test('le fichier refuse reste present — rien n a ete efface', fs.existsSync(claimFile(itemAutrui)));

  const relOk = claim(['release', itemAutrui], 'agent-A');
  test(
    'le proprietaire peut liberer sa propre reservation vivante',
    relOk.code === 0 && relOk.out.includes('LIBERE'),
    `exit=${relOk.code} out=${relOk.out.trim()}`
  );
} finally {
  fs.rmSync(tmpDir, { recursive: true, force: true });
}

// ---------------------------------------------------------------------------
console.log('');
console.log('==============================================================');
console.log(`RESUME: ${passed} reussis, ${failed} echoues`);
console.log('==============================================================');

if (failed > 0) {
  console.log('');
  console.log('\x1b[31mDes controles ont echoue.\x1b[0m');
  process.exit(1);
}

console.log('');
console.log('\x1b[32mTous les controles passent!\x1b[0m');
process.exit(0);

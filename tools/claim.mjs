#!/usr/bin/env node
/**
 * claim — reservation d items entre sessions claude-s paralleles.
 *
 * Deux sessions qui tournent en meme temps doivent se voir. Un item pris est
 * pris ; une reservation sans battement depuis 20 min est reputee morte et
 * redevient libre (une session qui meurt ne previent personne).
 *
 *   node tools/claim.mjs list
 *   node tools/claim.mjs take <item>      exit 0 pris · exit 1 deja pris
 *   node tools/claim.mjs beat <item>
 *   node tools/claim.mjs release <item> [etat] [note...]
 *   node tools/claim.mjs gc
 *
 * Identite de session : $env:KS_AGENT (pose par claude-s.bat).
 */
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';

const ROOT = path.resolve(new URL('..', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'));
const DIR = path.join(ROOT, 'governance', 'claims');
const PERIME_MS = 20 * 60 * 1000;
const AGENT = process.env.KS_AGENT || `${os.hostname()}-${process.pid}`;
const [cmd, item, ...rest] = process.argv.slice(2);

fs.mkdirSync(DIR, { recursive: true });
const f = (i) => path.join(DIR, i.replace(/[^a-zA-Z0-9._-]/g, '_') + '.json');
const lire = (p) => { try { return JSON.parse(fs.readFileSync(p, 'utf8')); } catch { return null; } };
const age = (c) => Date.now() - new Date(c.beat || c.pris).getTime();
const min = (ms) => Math.round(ms / 60000);

function actifs() {
  return fs.readdirSync(DIR).filter((x) => x.endsWith('.json'))
    .map((x) => ({ file: path.join(DIR, x), c: lire(path.join(DIR, x)) }))
    .filter((x) => x.c && age(x.c) < PERIME_MS);
}

function gc() {
  let n = 0;
  for (const x of fs.readdirSync(DIR).filter((y) => y.endsWith('.json'))) {
    const p = path.join(DIR, x); const c = lire(p);
    if (!c || age(c) >= PERIME_MS) { fs.unlinkSync(p); n++; }
  }
  return n;
}

if (cmd === 'gc') { console.log(`${gc()} reservation(s) perimee(s) liberee(s).`); process.exit(0); }

if (cmd === 'list') {
  gc();
  const a = actifs();
  if (!a.length) { console.log('Aucun item reserve. Tout est libre.'); process.exit(0); }
  console.log('Items en cours ailleurs :');
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
  if (c && age(c) < PERIME_MS && c.agent !== AGENT) {
    console.log(`PRIS ${item} — par ${c.agent} depuis ${min(age(c))} min. Prends-en un autre.`);
    process.exit(1);
  }
  fs.writeFileSync(p, JSON.stringify({ item, agent: AGENT, pris: new Date().toISOString(), beat: new Date().toISOString() }, null, 2) + '\n');
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
  const p = f(item);
  if (fs.existsSync(p)) fs.unlinkSync(p);
  console.log(`LIBERE ${item}${rest.length ? ' — ' + rest.join(' ') : ''}`);
  process.exit(0);
}

console.error('usage: list | take <item> | beat <item> | release <item> [etat] [note] | gc');
process.exit(2);
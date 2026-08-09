#!/usr/bin/env node
/**
 * status-secteurs — la vue d'ensemble de GEDv1, en une page.
 *
 * Croise governance/sectors.json (le decoupage declare) avec
 * tests/reports/harness-latest.json (ce qui a reellement tourne) et produit
 * docs/STATUS-SECTEURS.md.
 *
 * Raison d'etre : reconstruire l'etat secteur par secteur a chaque question
 * conduit a se tromper. Constat du 2026-08-08 — trois erreurs factuelles sur
 * l'existant en une seule session, toutes faute de vue d'ensemble.
 *
 * Quatre etats :
 *   VERT      tous les oracles du secteur sont verts au dernier harness
 *   ROUGE     au moins un oracle est rouge
 *   ORPHELIN  aucun oracle declare — dette visible, pas oubli
 *   FANTOME   oracles verts mais cablage non prouve (declare dans le registre)
 *
 * FANTOME est le pire des quatre : il ressemble a VERT et ne vaut rien.
 *
 *   node tools/status-secteurs.mjs           tableau a l ecran
 *   node tools/status-secteurs.mjs --write   ecrit docs/STATUS-SECTEURS.md
 */
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(new URL('..', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'));
const P = (...a) => path.join(ROOT, ...a);
const J = (p, d = null) => { try { return JSON.parse(fs.readFileSync(P(p), 'utf8')); } catch { return d; } };

const registre = J('governance/sectors.json');
if (!registre) { console.error('governance/sectors.json introuvable'); process.exit(1); }

const harness = J('tests/reports/harness-latest.json', null);
const suites = new Map((harness?.suites || []).map((s) => [s.name, s]));

// Un secteur est FANTOME si son etatConnu le dit explicitement. On ne le devine
// pas : le cablage se constate a la lecture du code, pas au comptage des verts.
const FANTOME = /fantome/i;

function evalue(sec) {
  const oracles = sec.oracles || [];
  if (!oracles.length) return { etat: 'ORPHELIN', verts: 0, rouges: 0, inconnus: 0 };

  let verts = 0, rouges = 0, inconnus = 0;
  const rougesNoms = [], inconnusNoms = [];

  for (const nom of oracles) {
    const s = suites.get(nom);
    if (!s) { inconnus++; inconnusNoms.push(nom); continue; }
    if (s.ok) verts++; else { rouges++; rougesNoms.push(nom); }
  }

  let etat = rouges ? 'ROUGE' : (inconnus === oracles.length ? 'ORPHELIN' : 'VERT');
  if (etat === 'VERT' && FANTOME.test(sec.etatConnu || '')) etat = 'FANTOME';

  return { etat, verts, rouges, inconnus, rougesNoms, inconnusNoms };
}

const ICONE = { VERT: '🟢', ROUGE: '🔴', ORPHELIN: '⚪', FANTOME: '👻' };
const ORDRE = { FANTOME: 0, ROUGE: 1, ORPHELIN: 2, VERT: 3 };

const lignes = [];
for (const [id, sec] of Object.entries(registre.sectors)) {
  lignes.push({ id, sec, ...evalue(sec) });
}
lignes.sort((a, b) => (ORDRE[a.etat] - ORDRE[b.etat]) || a.id.localeCompare(b.id));

const compte = (e) => lignes.filter((l) => l.etat === e).length;

// ---------- sortie ecran ----------
console.log('\nSTATUS SECTEURS — GEDv1');
console.log('='.repeat(74));
if (!harness) console.log('AUCUN harness : lancer run-harness.bat. Les etats sont indetermines.\n');

for (const l of lignes) {
  const det = l.etat === 'ORPHELIN' ? 'aucun oracle'
    : `${l.verts} vert(s)` + (l.rouges ? `, ${l.rouges} rouge(s) : ${l.rougesNoms.join(', ')}` : '')
      + (l.inconnus ? `, ${l.inconnus} jamais execute(s) : ${l.inconnusNoms.join(', ')}` : '');
  console.log(`${ICONE[l.etat]} ${l.etat.padEnd(9)} ${l.id.padEnd(22)} ${det}`);
}

console.log('='.repeat(74));
console.log(`${lignes.length} secteurs — ${compte('VERT')} verts · ${compte('ROUGE')} rouges · ${compte('ORPHELIN')} orphelins · ${compte('FANTOME')} fantomes`);
if (compte('FANTOME')) console.log('Un secteur FANTOME ressemble a un vert et ne prouve rien. A traiter en premier.');

// ---------- docs/STATUS-SECTEURS.md ----------
if (process.argv.includes('--write')) {
  const md = [];
  md.push('# STATUS SECTEURS — GEDv1 (K-Docs)');
  md.push('');
  md.push('> **Genere** par `node tools/status-secteurs.mjs --write`. Ne pas editer a la main.');
  md.push('> Croise `governance/sectors.json` avec `tests/reports/harness-latest.json`.');
  md.push('> Regles contraignantes : `governance/agent-rules.md`.');
  md.push('');
  md.push(harness
    ? `> Dernier harness : **${harness.verdict}** · ${(harness.suites || []).length} suites · ${harness.generatedAt}`
    : '> **Aucun harness disponible** — lancer `run-harness.bat`. Les etats ci-dessous sont indetermines.');
  md.push('');
  md.push(`**${lignes.length} secteurs** — ${compte('VERT')} 🟢 verts · ${compte('ROUGE')} 🔴 rouges · ${compte('ORPHELIN')} ⚪ orphelins · ${compte('FANTOME')} 👻 fantomes`);
  md.push('');
  md.push('| | Secteur | Etat | Oracles | Depend de |');
  md.push('|---|---|---|---|---|');
  for (const l of lignes) {
    const or = l.etat === 'ORPHELIN' ? '—'
      : `${l.verts}✓` + (l.rouges ? ` ${l.rouges}✗` : '') + (l.inconnus ? ` ${l.inconnus}?` : '');
    md.push(`| ${ICONE[l.etat]} | \`${l.id}\` | ${l.etat} | ${or} | ${(l.sec.dependsOn || []).join(', ') || '—'} |`);
  }
  md.push('');
  md.push('## Lecture des etats');
  md.push('');
  md.push('- 🟢 **VERT** — tous les oracles declares sont verts au dernier harness.');
  md.push('- 🔴 **ROUGE** — au moins un oracle tombe. Le detail est ci-dessous.');
  md.push('- ⚪ **ORPHELIN** — aucun oracle. Le secteur peut etre casse sans que rien ne rougisse.');
  md.push('- 👻 **FANTOME** — oracles verts, **cablage non prouve**. Le plus dangereux : il a l apparence du vert. Cas fondateur : `folder-permissions` etait vert sur 10 tests unitaires alors que `FolderPermissionService` n est appele par aucune ligne applicative.');
  md.push('');
  md.push('## Detail par secteur');
  md.push('');
  for (const l of lignes) {
    md.push(`### ${ICONE[l.etat]} ${l.id} — ${l.etat}`);
    md.push('');
    md.push(`**${l.sec.label}**`);
    md.push('');
    if (l.sec.invariant) { md.push(`> *Invariant* — ${l.sec.invariant}`); md.push(''); }
    if (l.sec.etatConnu) { md.push(`**Etat connu.** ${l.sec.etatConnu}`); md.push(''); }
    if (l.rougesNoms?.length) { md.push(`**Oracles rouges** : ${l.rougesNoms.map((n) => `\`${n}\``).join(', ')}`); md.push(''); }
    if (l.inconnusNoms?.length) { md.push(`**Oracles declares jamais executes** : ${l.inconnusNoms.map((n) => `\`${n}\``).join(', ')}`); md.push(''); }
    md.push(`**Agent** : \`${l.sec.ownerAgent}\` · **Oracles** : ${(l.sec.oracles || []).map((n) => `\`${n}\``).join(', ') || '_aucun_'}`);
    md.push('');
    if ((l.sec.fichiers || []).length) { md.push(`**Fichiers** : ${l.sec.fichiers.map((f) => `\`${f}\``).join(' · ')}`); md.push(''); }
    if ((l.sec.tables || []).length) { md.push(`**Tables** : ${l.sec.tables.map((t) => `\`${t}\``).join(', ')}`); md.push(''); }
    md.push('---');
    md.push('');
  }
  fs.writeFileSync(P('docs', 'STATUS-SECTEURS.md'), md.join('\n'), 'utf8');
  console.log('\ndocs/STATUS-SECTEURS.md ecrit.');
}

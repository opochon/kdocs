#!/usr/bin/env node
/**
 * checklist — ou en est K-Time, mesure et pas memoire.
 *
 * Trois etats :
 *   A FAIRE  la sonde echoue
 *   FAIT     la sonde passe, mais aucun oracle vert ne le garantit
 *   TESTE    la sonde passe ET l oracle nomme est vert au dernier harness
 *
 * Un item sans oracle declare ne peut jamais atteindre TESTE. C est voulu :
 * la dette d instrumentation doit se voir, pas se cacher derriere un vert.
 *
 *   node tools/checklist.mjs           tableau
 *   node tools/checklist.mjs --write   ecrit docs/CHECKLIST.md
 */
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(new URL('..', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'));
const P = (...a) => path.join(ROOT, ...a);
const J = (p, d = null) => { try { return JSON.parse(fs.readFileSync(P(p), 'utf8')); } catch { return d; } };

const backlog = J('governance/backlog.json');
if (!backlog) { console.error('governance/backlog.json introuvable'); process.exit(1); }
const fk = J('tests/reports/fk-latest.json', {});
const map = J('governance/fk-map.json', {});
const harness = J('tests/reports/harness-latest.json', { suites: [] });
const green = new Set((harness.suites || []).filter((s) => s.ok).map((s) => s.name));
const known = new Set((harness.suites || []).map((s) => s.name));

function probe(p) {
  if (!p) return false;
  switch (p.type) {
    case 'file': return fs.existsSync(P(p.path));
    case 'grep': {
      if (!fs.existsSync(P(p.file))) return false;
      return new RegExp(p.pattern, 'i').test(fs.readFileSync(P(p.file), 'utf8'));
    }
    case 'report': {
      const r = J(p.file, {});
      const v = r?.[p.path];
      if (p.op === 'empty') return Array.isArray(v) && v.length === 0;
      if (p.op === 'zero') return Number(v) === 0;
      return false;
    }
    case 'fkowner': {
      const d = (fk.detail || []).filter((s) => s.owner === p.owner);
      return d.length > 0 && d.every((s) => s.covered);
    }
    case 'mapdate': {
      const c = map?.documentDateColumns?.[p.table];
      return Array.isArray(c) && c.length > 0;
    }
    case 'suite': return green.has(p.name);
    default: return false;
  }
}

function reste(item) {
  if (item.probe?.type === 'fkowner') {
    const d = (fk.detail || []).filter((s) => s.owner === item.probe.owner);
    const n = d.filter((s) => !s.covered).length;
    return n ? `${n} surface(s)` : '';
  }
  if (item.probe?.type === 'report' && item.probe.op === 'empty') {
    const r = J(item.probe.file, {});
    const v = r?.[item.probe.path];
    return Array.isArray(v) && v.length ? v.join(', ') : '';
  }
  if (item.probe?.type === 'suite' && !known.has(item.probe.name)) return 'oracle inexistant';
  if (!item.oracle) return 'aucun oracle — ne pourra jamais etre TESTE';
  return '';
}

const lignes = [];
let totFait = 0, totTeste = 0, totN = 0;

for (const [key, bloc] of Object.entries(backlog.blocs)) {
  let fait = 0, teste = 0;
  const rows = [];
  for (const it of bloc.items) {
    const ok = probe(it.probe);
    const t = ok && it.oracle && green.has(it.oracle);
    const etat = t ? 'TESTE' : ok ? 'FAIT' : 'A FAIRE';
    if (ok) fait++;
    if (t) teste++;
    rows.push({ etat, id: it.id, titre: it.titre, reste: reste(it) });
  }
  const n = bloc.items.length;
  totFait += fait; totTeste += teste; totN += n;
  lignes.push({ key, titre: bloc.titre, n, fait, teste, rows });
}

const pc = (a, b) => (b ? Math.round((a / b) * 100) : 0);
const sym = { 'TESTE': '[x]', 'FAIT': '[~]', 'A FAIRE': '[ ]' };

let out = `# Checklist K-Time\n\n> Genere par \`npm run checklist\`. **Ne pas editer a la main.**\n> \`[x]\` teste (oracle vert) · \`[~]\` fait mais non garanti · \`[ ]\` a faire\n> Genere le ${new Date().toISOString().slice(0, 16).replace('T', ' ')}\n\n`;
out += `**Global : ${pc(totFait, totN)} % fait · ${pc(totTeste, totN)} % teste** (${totN} items)\n\n`;

console.log('Checklist K-Time');
console.log('='.repeat(72));
for (const b of lignes) {
  const head = `${b.titre} — ${pc(b.fait, b.n)}% fait · ${pc(b.teste, b.n)}% teste (${b.n})`;
  console.log(`\n${head}`);
  out += `## ${b.titre}\n\n${pc(b.fait, b.n)} % fait · ${pc(b.teste, b.n)} % teste — ${b.n} items\n\n`;
  for (const r of b.rows) {
    console.log(`  ${sym[r.etat]} ${r.titre}${r.reste ? '  — ' + r.reste : ''}`);
    out += `- ${sym[r.etat]} **${r.titre}**${r.reste ? ` — ${r.reste}` : ''}\n`;
  }
  out += '\n';
}
console.log('\n' + '='.repeat(72));
console.log(`GLOBAL : ${pc(totFait, totN)} % fait · ${pc(totTeste, totN)} % teste (${totN} items)`);

fs.mkdirSync(P('tests/reports'), { recursive: true });
fs.writeFileSync(P('tests/reports/checklist-latest.json'), JSON.stringify({ generatedAt: new Date().toISOString(), totalItems: totN, fait: totFait, teste: totTeste, blocs: lignes }, null, 2) + '\n');
if (process.argv.includes('--write')) { fs.writeFileSync(P('docs/CHECKLIST.md'), out); console.log('\ndocs/CHECKLIST.md ecrit.'); }
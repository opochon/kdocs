# Checklist K-Time

> Genere par `npm run checklist`. **Ne pas editer a la main.**
> `[x]` teste (oracle vert) · `[~]` fait mais non garanti · `[ ]` a faire
> Genere le 2026-08-06 14:22

**Global : 17 % fait · 0 % teste** (12 items)

## Socle — mesurabilite

25 % fait · 0 % teste — 4 items

- [~] **Preflight environnement (vendor, playwright, DB, K-Time)** — aucun oracle — ne pourra jamais etre TESTE
- [ ] **Le harness produit un rapport machine (harness-latest.json)** — aucun oracle — ne pourra jamais etre TESTE
- [ ] **Specs Playwright decouvertes, non listees en dur dans le .bat** — aucun oracle — ne pourra jamais etre TESTE
- [ ] **Les 8 RAPPORT_*.md de la racine ranges** — aucun oracle — ne pourra jamais etre TESTE

## Integration K-Time (erpconnect)

25 % fait · 0 % teste — 4 items

- [~] **K-Time /api/ged/health verifie au preflight** — aucun oracle — ne pourra jamais etre TESTE
- [ ] **Oracle d aller-retour REEL contre K-Time (pas moque)**
- [ ] **Contrat GED<->K-Time versionne des deux cotes**
- [ ] **KTIME_GED_API_KEY jamais serialisee dans un log ou rapport** — aucun oracle — ne pourra jamais etre TESTE

## Qualite — cliquets et perimetres

0 % fait · 0 % teste — 4 items

- [ ] **260 erreurs phpstan en baseline : decision tracee** — aucun oracle — ne pourra jamais etre TESTE
- [ ] **Au moins un cliquet qui ne peut que descendre** — aucun oracle — ne pourra jamais etre TESTE
- [ ] **Secteurs declares avec leurs oracles (anti-orphelin)** — aucun oracle — ne pourra jamais etre TESTE
- [ ] **Un gate dont la verite est exterieure au code (regle 9)** — aucun oracle — ne pourra jamais etre TESTE


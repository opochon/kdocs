# Checklist GEDv1

> Genere par `npm run checklist`. **Ne pas editer a la main.**
> `[x]` teste (oracle vert) · `[~]` fait mais non garanti · `[ ]` a faire
> Genere le 2026-08-26 05:52

**Global : 68 % fait · 32 % teste** (22 items)

## Produit — les 5 gaps critiques de l audit

40 % fait · 20 % teste — 5 items

- [ ] **UI professionnelle (audit 3,5/10 : sidebar melangee, emojis, compteurs incoherents)**
- [~] **Ingestion asynchrone : OCR/classification hors requete HTTP**
- [ ] **IA alignee sur la taxonomie HTMLEDITOR (variables, sections, tags)**
- [x] **Apps stub invoices/mail : livrees ou retirees de l UI (plus de 404)**
- [ ] **Conformite archivage CH : WORM, retention, horodatage**

## Coeur documentaire — ce que la GED doit garantir

50 % fait · 50 % teste — 6 items

- [x] **Recherche FULLTEXT operante sans Qdrant**
- [ ] **Zero incoherence is_deleted / deleted_at**
- [ ] **Miniatures PDF et bureautique generees (LibreOffice/Ghostscript)**
- [ ] **OCR eprouve sur un lot de controle mesure**
- [x] **Permissions de dossiers verifiees cote serveur, pas a l affichage**
- [x] **Corbeille et retention : purge tracee et reversible**

## Socle — mesurabilite

100 % fait · 0 % teste — 4 items

- [~] **Preflight environnement (vendor, playwright, DB, K-Time)** — aucun oracle — ne pourra jamais etre TESTE
- [~] **Le harness produit un rapport machine (harness-latest.json)** — aucun oracle — ne pourra jamais etre TESTE
- [~] **Specs Playwright decouvertes, non listees en dur dans le .bat** — aucun oracle — ne pourra jamais etre TESTE
- [~] **Les 8 RAPPORT_*.md de la racine ranges** — aucun oracle — ne pourra jamais etre TESTE

## Integration K-Time (erpconnect)

100 % fait · 67 % teste — 3 items

- [~] **K-Time /api/ged/health verifie au preflight** — aucun oracle — ne pourra jamais etre TESTE
- [x] **Contrat /api/ged/* versionne et compare aux deux depots**
- [x] **KTIME_GED_API_KEY jamais serialisee dans un log ou rapport**

## Qualite — cliquets et perimetres

75 % fait · 25 % teste — 4 items

- [ ] **260 erreurs phpstan en baseline : decision tracee** — aucun oracle — ne pourra jamais etre TESTE
- [x] **Au moins un cliquet qui ne peut que descendre**
- [~] **Secteurs declares avec leurs oracles (anti-orphelin)** — aucun oracle — ne pourra jamais etre TESTE
- [~] **Un gate dont la verite est exterieure au code (regle 9)** — aucun oracle — ne pourra jamais etre TESTE


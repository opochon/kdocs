# SESSION-STATUS — GEDv1 (K-Docs)

> Source de vérité état projet — migration initiale.
> Dépôt : `F:\DATA\DEVELOPPEMENT\GEDv1`

## État au 2026-06-17

### Fait cette session

- [x] Copie `C:\wamp64\www\kdocs` → `F:\DATA\DEVELOPPEMENT\GEDv1`
- [x] Vérification intégrité (1110 → 1108 fichiers hors vendor)
- [x] Exclusion secrets (`claude_api_key.txt`, `cookies.txt`)
- [x] Documentation oracles et architecture
- [x] Index fonctions (~165 classes, ~730 méthodes publiques)
- [x] Analyse code et dette technique
- [x] Harness tests migration (`tests/migration_smoke_test.php`, `run-tests.bat`)
- [x] Documentation système plugin + WinBiz
- [x] **Panorama GED complet** — `docs/PANORAMA-GED-REDX.md`
- [x] **Correction delta REDX** — REDX = intégrateur RedX / M-Files (pas projet local ni Xerox pur)
- [x] Matrice delta 38 fonctions (P0–P4), score ~48 % vs REDX fiduciaire

### Documentation créée

| Fichier | Contenu |
|---------|---------|
| `docs/ORACLES.md` | Invariants, contrats API, conventions |
| `docs/ARCHITECTURE.md` | Vue technique, stack, flux |
| `docs/PLUGIN-SYSTEM.md` | Connecteurs, WinBiz, vision plugin |
| `docs/FUNCTIONS-INDEX.md` | Inventaire fonctions par module |
| `docs/CODE-ANALYSIS.md` | Qualité, sécurité, dette |
| `docs/DELTA-REDX.md` | Delta REDX vs GEDv1 (38 gaps P0–P4) |
| `docs/PANORAMA-GED-REDX.md` | Panorama GED open source + marché + stratégie self-hosted |
| `docs/MIGRATION-NOTES.md` | Détails copie et exclusions |

## Stack identifiée

- **PHP 8.1+** / Slim 4 / PHP-DI / Monolog
- **MySQL/MariaDB** — schéma `database/schema_consolidated.sql`
- **GED core** : OCR, IA, workflows, API REST, OnlyOffice, Qdrant optionnel
- **Apps** : timetrack (partiel), invoices/mail (stubs)
- **Connecteur** : WinBiz ODBC (code présent, validation terrain à faire)

## Harness tests

```cmd
cd F:\DATA\DEVELOPPEMENT\GEDv1

REM Offline (sans serveur)
php tests\migration_smoke_test.php

REM Harness complet
run-tests.bat              REM migration + PHPUnit unit
run-tests.bat smoke        REM smoke HTTP (serveur requis)
run-tests.bat full         REM toutes suites (serveur + BDD)
```

## Bloqueurs

| Bloqueur | Impact | Action |
|----------|--------|--------|
| P0 bugs UI (miniatures, OCR) | Usage quotidien | `docs/CORRECTIONS_PRIORITAIRES.md` — **Lot 1** |
| Archivage légal Olico absent | Parité REDX impossible | Lot 3 — `LegalArchiveService` |
| WinBiz ODBC non validé | Pas d'intégration ERP | Lot 2 — test terrain 32-bit |
| `composer.lock` désync | `composer install` échoue | `composer update` ou copie vendor |
| Apps invoices/mail non branchées | Routes mortes | Lot 2 — brancher `apps/invoices/` |

## Prochaines étapes recommandées

1. **Lot 1** — Corriger P0 (miniatures, aperçu modale, OCR indexé, badge validation)
2. **Lot 1** — `run-tests.bat` vert + config WAMP `http://localhost/gedv1`
3. **Lot 2** — Valider WinBiz ODBC 32-bit + brancher `apps/invoices/`
4. **Lot 3** — Couche archivage légal Olico (`LegalArchiveService`, rétention)
5. **Extraire routes** `index.php` → fichiers modulaires
6. **Créer** `.env.example` et formaliser `ConnectorInterface`
7. Voir roadmap détaillée : `docs/PANORAMA-GED-REDX.md` section 9

## Liens

- README : `README.md`
- Roadmap : `docs/ROADMAP.md`
- API : `docs/API.md`
- Corrections P0 : `docs/CORRECTIONS_PRIORITAIRES.md`
- WinBiz bridge référence : `F:\DATA\DEVELOPPEMENT\WinbizIntegrator\k-winbiz-bridge\`

---
*Dernière mise à jour : 2026-06-17*

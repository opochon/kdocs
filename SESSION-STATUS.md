# SESSION-STATUS — GEDv1 (K-Docs)

> Source de vérité état projet — migration initiale + roadmap produit B0→B1.
> Dépôt : `F:\DATA\DEVELOPPEMENT\GEDv1`

## Session 2026-06-26/27 — harness visuel, C.2/C.3/C.4 SMQ, dette

**Fait :**
- Harness visuel **Playwright** (`tests/visual/`) — smoke DOM + captures, login auto, serveur auto. **7/7 vert** (6 routes shell + fiche SMQ). Remplace `screenshot_runner.ps1`.
- **C.2 — versioning documentaire SMQ livré** : onglet **Versions** dans la **modale fiche** (`templates/documents/index.php`), gated `SMQ_ENABLED`. Liste + restore + download + diff + upload nouvelle version, sur l'API existante (`DocumentVersionsApiController`). Pas de page `/smq` parallèle (principe REDX).
- **2 bugs réels attrapés par le harness** : (1) dashboard `created_at` ambigu (1052) → 500 sur `/`, qualifié `d.created_at` ; (2) `ApiController::successResponse` param implicitement nullable → **déprecation PHP 8.4 polluait toutes les réponses API** (cassait `JSON.parse`, dont la modale) → `?string`.
- **Dette debug retirée** : instrumentation cross-projet `4af063` / `htmleditor_v3` délogée → `DebugLogger` écrit dans `storage/logs/` ; `router.php`, `.bat` dev, smokes live, `audit_with_log.php` nettoyés.
- **Docs/build** : oracle `/search` canonique + fiche-modale/versioning, `ROADMAP.md` obsolète marqué, `BEFORE_YOU_START` état réel, `Makefile`, **design system Karbonic** (`docs/DESIGN-SYSTEM-KARBONIC.md`, lot d'uniformisation futur).
- **`Validator` core complété + suites vertes** : `ValidatorTest` passait de **25 erreurs → 0** (ajout `passes/fails/error/sanitize` + règles `between/confirmed/nullable` + `validated()` filtré sur les champs réglés ; `make()` valide à la construction). `DocumentServiceTest` orphelin (service inexistant) neutralisé (`markTestSkipped`).
- **C.3 — quittance lecture SMQ livré** : migration **031** `document_read_receipts` (appliquée via nouvel outil `tools/apply-sql-migration.php`), `DocumentReadReceipt` + `ReadReceiptsApiController` (POST `…/read`, GET `…/read-status`), bloc quittance dans l'onglet Versions de la modale (« lu le X par Y » / bouton « Marquer comme lu »). Vérifié bout-en-bout (POST → statut `has_read:true`, lecteur `root`).
- **`show.php` legacy déplacé vers `_trash/`** (git mv, tracé/récupérable — voir `_trash/README.md`) + bloc mort retiré de `DocumentsController::show`.
- **C.3 durci** : bannière « lecture obligatoire non quittancée » en tête de modale (gated SMQ) à l'ouverture + bouton « Marquer comme lu » ; quittance affichée même sans rangée de version (v1 implicite).
- **C.4 livré** : vue filtrée qualité **« À quittancer »** dans la Bibliothèque (`GET /documents?smq=to_read`, `NOT EXISTS` quittance sur la version courante) + section sidebar « Qualité (SMQ) », gated SMQ.
- **Dette traitée** : déprecations PHP 8.4 `WinBizConnector` (`?string`) ; `DocumentServiceTest` orphelin → `_trash` (0 skip) ; `SMOKE-FULL-REPORT.md` (généré) gitignoré ; autoloader `Tests\` robuste dans `tests/bootstrap.php`.

```cmd
cd F:\DATA\DEVELOPPEMENT\GEDv1\tests\visual
npm install && npm run install-browser   REM une fois
npm test                                  REM 7/7 — ou `make test-visual` depuis la racine
```

### Sécurités — lancer toute la batterie (dernier run 2026-06-27 : **tout vert**)

```cmd
php tests\migration_smoke_test.php        REM 141/141 offline structurel
vendor\bin\phpunit --testsuite=unit       REM 230 tests, 0 err/fail, 0 skip
vendor\bin\phpunit --testsuite=feature    REM 58 tests, 0 err/fail, 3 skipped
tools\run-live-smokes.bat                 REM smoke 24 + live 9 + full 64 OK (serveur auto)
cd tests\visual && npm test               REM 7/7 Playwright (SMQ on via webServer env)
```

0 Deprecated/Warning/Fatal dans les réponses (la déprecation API était le seul leak, corrigée).

**Dette — traitée** : `show.php` → `_trash` + bloc mort contrôleur ; outil `tools/apply-sql-migration.php` (les `.sql` n'ont pas de runner) ; déprecations PHP 8.4 (`ApiController`, `WinBizConnector`) ; `DocumentServiceTest` orphelin → `_trash` (0 skip) ; `SMOKE-FULL-REPORT.md` gitignoré ; autoloader `Tests\` robuste (`tests/bootstrap.php`).

**Dette — différée (motif, lot dédié)** :
- **`#region agent log` (DebugLogger)** : ~100+ blocs sur 24 fichiers, **mêlés à du code fonctionnel** (ex. `AuthController::login` extrait `$data`/`$username` dans un bloc) → retrait en masse **dangereux**. Refactor bloc par bloc requis.
- **PHPStan** : installable mais `composer.lock` **désynchronisé** pour les dev-tools (l'installer élague d'autres paquets, ex. `n0nag0n` → casse) → nécessite `composer update` + resync lock délibéré. Validé localement (level 5 : 275 erreurs → baseline).
- **`slim/slim` CVE-2026-48157** (medium) : montée de version framework à tester (lot sécurité).

**Prochains pas** :
1. **Lot dépendances** : resync `composer.lock` (dev-tools), activer PHPStan + baseline, corriger CVE `slim/slim`.
2. **Lot DebugLogger** : retirer les `#region agent log` bloc par bloc (préserver le code fonctionnel mêlé).
3. **Uniformisation UI** via `docs/DESIGN-SYSTEM-KARBONIC.md` (lot transverse, clair+sombre).
4. Phase A factures reste 🟡 — bridge WinBiz externe non déployé ; purge `_trash/` quand validé.

---

## État au 2026-06-22 (chantier B1 — GED pro K-Docs vs REDX)

### Roadmap produit

| Phase | Avancement | Document |
|-------|------------|----------|
| **B0** Crédibilité | **12/12** | `docs/ROADMAP-KDOCS-PRODUCT.md` |
| **B1** GED pro | **10/10** | idem |
| A Factures | 3/8 (stubs + health ; reste 🟡 bridge) | idem |
| C SMQ | 1/4 (scaffold) | idem |
| D RH | 1/4 (scaffold) | idem |
| P2 Conformité CH | 1/5 (scaffold LegalArchiveService) | idem |

**B1 complété cette session** : sidebar `/search`, hub admin tuiles SVG, recherche unifiée, show.php actions branchées, inbox « À traiter », JS modale extrait, design system minimal, compteurs `shellSidebarStats`, placeholder miniatures, `routes/web.php`, bench ingest.

**Prochain lot** : Phase A — activer plugin factures + matching live quand `k-winbiz-bridge` déployé.

### Harness tests

```cmd
cd F:\DATA\DEVELOPPEMENT\GEDv1
php tests\migration_smoke_test.php    REM 125/125 offline (post-B1)
php tools\bench-ingest.php            REM structure
php tools\bench-ingest.php --live     REM BDD requise
```

**Dernier run** : **125/125** migration_smoke · **15/15** PHPUnit ingest/classifiers · **6/6** harness visuel Playwright (2026-06-26)

### Commits session 2026-06-22 (B1 + scaffold)

| Lot | Commit | Message |
|-----|--------|---------|
| B1.1/7 | `ae7b7f0` | `feat(ged): shellSidebarStats et sidebar Recherche /search B1.1 B1.7` |
| B1.2 | `2fc5b85` | `feat(ged): hub admin tuiles SVG sans emoji B1.2` |
| B1.3/9 | `42b55a9` | `feat(ged): route /search unifiée et redirect /chat B1.3 B1.9` |
| B1.4 | `9edc3a3` | `fix(ged): fiche document épurée et inbox À traiter B1.4` |
| B1.5/6/8 | `bce772d` | `feat(ged): modale JS, design system et placeholder miniatures B1.5 B1.6 B1.8` |
| docs | `d3f07f1` | `docs(ged): roadmap B1 complète, smoke 125 et SESSION-STATUS` |
| A/C/D/P2 | `d62b143` | `feat(ged): bench ingest, stubs WinBiz et scaffold SMQ/RH/P2` |

### Commits session 2026-06-22 (B0)

| Lot | Commit | Message |
|-----|--------|---------|
| B0.1 | `d4fff49` | `docs(ged): spec produit K-Docs vs REDX et roadmap B0` |
| B0.8 | `5ad203b` | `feat(ged): séparer sidebar user 5 entrées et hub admin B0.8` |
| B0.12 | `94bf36d` | `docs(ged): geler cascade directe AIClassifierService B0.12` |

### Push GitHub

| Élément | Détail |
|---------|--------|
| Remote | `https://github.com/opochon/kdocs.git` |
| Branch | `main` |

---

## Liens

- Spec produit : `docs/superpowers/specs/2026-06-18-kdocs-redx-simplification-design.md`
- Roadmap produit : `docs/ROADMAP-KDOCS-PRODUCT.md`
- Oracle produit : `docs/ORACLES-KDOCS-PRODUCT.md`

---

*Dernière mise à jour : 2026-06-26 — harness visuel Playwright + nettoyage dette debug*

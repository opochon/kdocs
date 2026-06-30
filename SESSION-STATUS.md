# SESSION-STATUS — GEDv1 (K-Docs)

> Source de vérité état projet — migration initiale + roadmap produit B0→B1.
> Dépôt : `F:\DATA\DEVELOPPEMENT\GEDv1`

## Session 2026-06-30 (sprint) — retrait connecteur ClearMyDocs v3 + finalisation chrome

**Sprint autonome** : commits/push réguliers, arrêt sur rouge → diagnostic + solution.
Baseline : PHPUnit 299/0, smoke 162/0, Playwright 32/32 (smq-versions résolu : serveur dev relancé avec `SMQ_APP_ENABLED=true`).

**Lots poussés sur `main`** :

| Lot | Commit | Contenu |
|-----|--------|---------|
| Retrait ClearMyDocs v3 | `76db1e4` | Connecteur sidecar v3 obsolète retiré (CMD v4 le remplace) : `IngestEngineRouter` (v4→natif, plus de coupled/INGEST_ENGINE), `ConnectorRegistry`/`config/connectors.php` (entrée v3 retirée), `PdfSplitService` (sidecar retiré, split legacy conservé), diagnostic (carte v3→CMD v4), suppression `ClearMyDocsSidecarClient`/`ClearMyDocsIngestEngine`/`ClearMyDocsCapabilityProbe`/`CmdResultMapper` + tests + `start-cmd-sidecar.bat`, `.env.example` (CLEARMYDOCS_*/INGEST_ENGINE retirés), docs (INGEST-DUAL-MODE/IA-CLEARMYDOCS-* supprimés, CONNECTEURS-PLUGINS/PLUGIN-SYSTEM/WINBIZ-REPOSITIONNE/ORACLES mis à jour). Vert : smoke 162/0, PHPUnit 299/0, Playwright 32/0. |
| Finalisation chrome | (suivant) | F-CHROME-06 (emoji) + F-CHROME-07 (bannière gated APP_DEBUG) instrumentalisés et verts. F-CHROME-02 reste fixme (alignement SQL compteurs = décision produit), F-CHROME-08 reste skip (`documentVisibilitySql` non appliqué à l'API dossiers = décision produit). |

**Résolu (environnemental)** : `smq-versions.spec.ts` — le serveur dev réutilisé n'avait pas `SMQ_APP_ENABLED=true` ; relance du serveur avec SMQ activé → onglet Versions rendu → test vert.

**État final** : PHPUnit 299/0 · smoke 162/0 · **Playwright 32/32** (0 échec, 2 skipped documentés : F-CHROME-02 fixme, F-CHROME-08 skip).

**Prochain pas** : (1) F-CHROME-02 alignement SQL compteurs sidebar/dashboard (décision produit) ; (2) F-CHROME-08 étendre `documentVisibilitySql` à `/api/folders/documents` ou tester via dashboard (décision produit) ; (3) configurer `.env` Infomaniak (clé + secret/product_id).

---

## Session 2026-06-29 (sprint) — finalisation tests, persona, a11y, chrome-coherence

**Sprint autonome** : commits/push réguliers par lot, arrêt sur rouge → diagnostic.
Baseline : PHPUnit 247/247, smoke 24/24, Playwright 15/16 (smq-versions = environnemental, SMQ désactivé).

**Lots poussés sur `main`** :

| Lot | Commit | Contenu |
|-----|--------|---------|
| Connecteur CMD v4 | `71d472a` | CmdV4Client/IngestEngine/Mapper/Probe + router + tests |
| Connecteur IA Infomaniak | `8999fa8` | InfomaniakAIService + priorité provider + tests |
| Correctifs noyau | `bf6b21b` | moteur attribution (ocr_content, bool PDO), JSON API dossiers (embedding), heuristiques dates (regex `\b`→`(?<!\d)`), arborescence (racine vide, dossiers internes masqués), AuthMiddleware 401 JSON |
| Harness évaluation + specs | `66d47cc` | `tools/eval-full.php` (gros fichiers réels : ingestion/OCR/classification/attribution IA/recherche/personas), `import-lot-controle.php`, `persona.spec.ts`, `pipeline-ui.spec.ts`, `FUNCTIONS-SPEC.md` |
| Couche a11y axe-core | `3e56023` | `a11y.spec.ts` + remédiation contraste WCAG AA (`--dim`, `--accent`, `--amber-ink`, footer, nav-count actif, compteur sync orange) |
| Couche chrome-coherence | `1e41c63` | `chrome-coherence.spec.ts` (F-CHROME-01/03/04/05 actifs, 02 fixme, 06/07/08 skip) |
| Persona étendu | `d68c103` | `persona-preview.spec.ts` (fiche UI + bouton validation + `can_validate` cohérent) |
| Fix upload `relative_path` | `bc6c075` | `apiUpload` range le doc dans son dossier (F-LIB-03) + pipeline-ui vérifie le rangement |

**Bugs produit corrigés** (découverts par les tests) :
- Moteur d'attribution cassé (`ocr_content` colonne inexistante, booléens PDO en strict mode) — jamais exercé faute de règles.
- `GET /api/folders/documents` HTTP 500 (BLOB `embedding` cassait `json_encode`).
- `apiUpload` ne renseignait pas `relative_path` → doc uploadé invisible dans son dossier + indicateur sync faux.
- Contraste WCAG AA : `--dim` (4.18), badge accent (4.45), chip amber (2.47), footer, compteur sync orange (3.36).

**Registre de test** (3 couches, `tests/visual/FUNCTIONS-SPEC.md`) :
- Couche 1 : spec fonctionnelle d'interface (référentiel UI+API+rôle+oracle).
- Couche 2 : pipeline rôle-agnostique (CLI `eval-full.php` + UI `pipeline-ui.spec.ts`).
- Couche 3 : persona (rôle-dépendant : `persona.spec.ts` + `persona-preview.spec.ts`) + a11y (`a11y.spec.ts`) + chrome (`chrome-coherence.spec.ts`).

**État final** : PHPUnit 247/247 · smoke 24/24 · **Playwright 31/32** (smq-versions = environnemental, SMQ désactivé dans le serveur dev réutilisé).

**Prochain pas** : (1) F-CHROME-02 alignement SQL compteurs sidebar/dashboard (décision produit) ; (2) instrumenter F-CHROME-06/07/08 ; (3) smq-versions : lancer le serveur avec `SMQ_APP_ENABLED=true` ou `test.use` dédié.

---

## Session 2026-06-29 — connecteurs P2 (CMD v4 ingest factures)

**Fait (lot P2, commité `71d472a`)** :
- `CmdV4Client` — aligné sur `clearmydocs-v3/cmdv4/docs/API.md` (health, projets, jobs, champs gatés ; port défaut **8510**).
- `CmdV4CapabilityProbe` — disponibilité v4 + détection PDF facture (`CMD_V4_INVOICE_*`).
- `CmdV4IngestEngine` — pipeline projet éphémère GED → champs `facture_fournisseur`.
- `CmdV4ResultMapper` — en-tête → `invoice_extraction_results` + `classification_suggestions`.
- `IngestEngineRouter` — PDF facture → v4 si up, sinon v3, sinon natif (jamais 500).
- `ConnectorRegistry` — health v4 via probe (plus `/health` racine).
- Doc adaptateur : `docs/CMD-V4-CONNECTOR.md` (pointeur vers API.md CMD v4).
- Tests : smoke **165/165** · unit **240** · PHPStan P2 **[OK]**.

**Prochain pas** : configurer `.env` Infomaniak (clé + secret/product_id) ; lot P3 (`erp-winbiz` contrôle) ; P2.5 schéma CMD v4 lignes/TVA.

---

**Fait :** uniformisation UI **terminée** sur tous les templates de contenu (suite du lot fondation+chrome).
Pilotage **superviseur + 6 agents** à contexte propre, périmètres disjoints :

- **Socle `head.php`** (`69de409`) : `<head>` centralisé (corrige `/mes-taches`, `404/500` qui restaient hors design system).
- **A** composants & partials (`f492e4d`) : §8 `design-system.css` (`.ds-card`, `.ds-chip--*`, `.ds-btn-*`, `.ds-divide-y`, `.ds-row-hover`, `.ds-field-changed`).
- **F** auth & erreurs (`867438f`) · **C** dashboard/recherche/tâches/chat (`12345c1`) · **D** admin paramètres & formulaires (`afbdf4e`) · **B** documents (`0d1f570`) · **E** admin listes & outillage (`ebedb87`).
- **Phase finale** (`a816992`) : balayage couleurs-état → tokens, classes `.ds-alert--*` (§9), **shim §5 réduit à un reliquat documenté** (0 template n'en dépend ; conservé uniquement pour le DOM injecté par `workflow-designer.js`).
- Suppression de `documents/index_clean.php` (`d365a22`) : vue legacy morte, 0 référence.

**Vérifié :** `migration_smoke` **141/141** · `phpunit` unit **230** / feature **58** (3 skip) · `phpstan` **[OK]** · **Playwright 7/7**.
Préservation comportementale contrôlée automatiquement (attributs `name`/`id`/`onclick`/`data-*` intacts ; hooks JS couplés convertis en classes sémantiques). Revue clair/sombre à l'œil recommandée en complément.

**Incident géré :** un sous-agent dévoyé a produit un refactor **hors-périmètre** (sous-système classifieur IA + doc WINBIZ, ~1990/995 l.) → **mis en quarantaine** (`git stash@{0}`, récupérable), exclu des commits de migration. Agent B coupé par limite de session sur le monolithe `index.php` (30 résidus JS) → terminé + validé par le superviseur.

**Reste (lots ultérieurs) :** migrer `public/js/workflow-designer.js` (~81 classes neutres en dur, dernier dépendant du shim §5) puis **retirer le shim** ; thème **Chart.js** (4 canvas dashboard, couleurs `rgb()` non pilotées par CSS) ; densité picto/hint complète (`DESIGN-SYSTEM-KARBONIC.md` §6).

---

## Session 2026-06-27 — uniformisation UI (design system Karbonic, lot fondation + chrome)

**Décisions (validées en début de lot)** : portée = **fondation + chrome** ; mode sombre = **tokens + bascule fonctionnelle** ; action primaire = **anthracite** (le bleu rétrogradé en simple accent focus/liens). Conforme à `docs/DESIGN-SYSTEM-KARBONIC.md`.

**Fait :**
- **`public/css/design-system.css` = feuille canonique Karbonic** (réécrite, ~270 l.) : tokens clair (`:root`) **et** sombre (`.dark`), `--paper` sombre pour la GED (le contenu n'est pas une page blanche), chargée **en dernier** dans `main.php`/`auth.php` (ses tokens/overrides gagnent la cascade).
- **Consolidation 4 feuilles → 1 palette** : `theme.css` et `app.css` **aliasent** désormais leurs variables (`--color-*`, `--primary-color`, `--bg-*`, `--text-*`) vers les tokens Karbonic avec **repli littéral** (`var(--surface, #fff)`…). Toutes les règles existantes (`.btn`, `.form-*`, `.badge`, `a{}`, `input{}`, `table{}`, cartes `.bg-white.rounded-lg.shadow`) deviennent theme-aware sans réécriture. Ancien `@media (prefers-color-scheme: dark)` de `app.css` retiré (conflit avec la bascule par classe).
- **Action primaire anthracite** : override ciblé (`.btn-primary`, `button[type=submit].bg-blue-600`, `button/a.bg-blue-600/700`) → `--primary` ; `color` neutralisé pour éviter `text-white` illisible sur primaire clair en sombre.
- **Chrome tokenisé** (classes `.ds-*`) : `sidebar_user`/`sidebar_admin`/`header`/`footer` réécrits (gris Tailwind en dur → tokens), en-têtes de section, hover/active uniformes + trait `inset` sur l'actif. IDs JS préservés (`#user-menu-toggle`/`#user-menu`). **Bug attrapé en revue** : règle legacy `app.css` `aside nav ul li a span:first-child{width:20px}` masquait les labels → neutralisée par `.ds-sidebar .ds-nav-item__main` (spécificité (0,2,0) > (0,1,6)).
- **Bascule clair / sombre / système** : `public/js/theme.js` (cycle, persistance `localStorage`, suivi `prefers-color-scheme`) + init **no-FOUC** inline dans le `<head>` (applique `.dark` avant rendu) + bouton `[data-theme-toggle]` (picto FA) dans le header. Login (`auth.php`) respecte aussi le thème.
- **Shim sombre** (`design-system.css` §5) : remap des utilitaires Tailwind neutres encore en dur (`bg-white`, `text-gray-*`, `border-gray-*`, `hover:*`) → tokens, **sans** toucher aux îlots volontairement sombres (`bg-gray-700/800/900`, `text-white`). Fait basculer le contenu des pages sans réécrire les ~70 templates (migration page par page = lots suivants).

**Vérifié (revue clair + sombre via captures Playwright `colorScheme`)** : chrome, dashboard, documents, admin OK dans les deux thèmes ; primaire anthracite (clair) / inversé (sombre) ; cartes/numéros lisibles en sombre après le correctif d'alias `--bg-primary`.

```cmd
php tests\migration_smoke_test.php        REM 141/141
vendor\bin\phpunit --testsuite=unit       REM 230 OK
vendor\bin\phpunit --testsuite=feature    REM 58 OK, 3 skipped
vendor\bin\phpstan analyse                REM [OK] No errors (baseline 256)
tools\run-live-smokes.bat                 REM smoke 24 + live 9 + full 64 OK + audit 8 pages (CSS 200, Login OK)
cd tests\visual && npm test               REM 7/7 Playwright (shell + SMQ)
```

**Reste (lots suivants)** : migrer les templates de contenu (utilitaires Tailwind en dur → classes/tokens, retire le shim peu à peu) ; set d'icônes par action ; densité picto/hint complète (`DESIGN-SYSTEM-KARBONIC.md` §6) ; thème des graphiques Chart.js (couleurs canvas non pilotées par les tokens).

### Commit clé 2026-06-27 (UI)

| Lot | Commit |
|-----|--------|
| Uniformisation UI — fondation + chrome + clair/sombre | _(voir `git log`)_ |

---

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
vendor\bin\phpstan analyse                REM level 5, [OK] No errors (256 baselined)
cd tests\visual && npm test               REM 7/7 Playwright (SMQ on via webServer env)
```

0 Deprecated/Warning/Fatal dans les réponses (la déprecation API était le seul leak, corrigée).

**Dette — traitée (zéro dette restante des items identifiés)** :
- `show.php` → `_trash` + bloc mort contrôleur ; outil `tools/apply-sql-migration.php` ; déprecations PHP 8.4 (`ApiController`, `WinBizConnector`) ; `DocumentServiceTest` orphelin → `_trash` (0 skip) ; `SMOKE-FULL-REPORT.md` gitignoré ; autoloader `Tests\` robuste.
- **DebugLogger** : 24 fichiers, ~622 lignes d'instrumentation `#region agent log` retirées (code fonctionnel mêlé **préservé**, ex. `AuthController::login`) ; classe inutilisée → `_trash`. Vérifié (login + 24/9/64 + 7/7).
- **Dépendances** : `composer.lock` resynchronisé ; **CVE `slim/slim` corrigée** (4.15.2, `composer audit` clean) ; **PHPStan actif** (level 5, `[OK]` via baseline de 256 erreurs legacy = backlog) ; `n0nag0n` déclaré (était orphelin).

**Prochains pas** :
1. **Uniformisation UI** via `docs/DESIGN-SYSTEM-KARBONIC.md` — ✅ **lot fondation + chrome + clair/sombre livré** (voir session 2026-06-27 ci-dessus) ; reste la migration page par page des templates de contenu.
2. **Burn-down PHPStan** : réduire la baseline (256 erreurs legacy, par niveau/dossier).
3. Phase A factures reste 🟡 — bridge WinBiz externe non déployé ; purge `_trash/` quand validé.

### Commits clés 2026-06-26/27 (`main`, poussés)

| Lot | Commits |
|-----|---------|
| Harness Playwright + fixes (dashboard 500, déprec API, debug `htmleditor_v3`) | `87b48d9` → `96b836a` |
| C.2 versioning documentaire (modale) | `331d3ae` |
| C.3 quittance lecture + durcissement | `a10e8d2`, `b2e1bf3` |
| C.4 vue filtrée « À quittancer » | `2e82a84` |
| Dette : déprec WinBiz + orphelin → `_trash` | `3e5981d` |
| Dette : autoloader `Tests\` robuste | `1a6014a` |
| Dette : **DebugLogger** retiré (24 fichiers, ~622 lignes) | `5dcbd69` |
| Dette : **dépendances** (CVE slim 4.15.2 + PHPStan baseline) | `8003587`, `d7dddd8` |

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

*Dernière mise à jour : 2026-06-29 — sprint finalisation tests (harness évaluation + specs Playwright persona/pipeline/a11y/chrome-coherence) + correctifs noyau (attribution, JSON API, heuristiques, upload relative_path) + remédiation contraste WCAG AA. Playwright 31/32 (smq-versions environnemental).*

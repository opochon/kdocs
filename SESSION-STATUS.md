# SESSION-STATUS — GEDv1 (K-Docs)



> Source de vérité état projet — migration initiale.

> Dépôt : `F:\DATA\DEVELOPPEMENT\GEDv1`



## État au 2026-06-18 (chantier GEDv1 — audits, P0 UI, fondations IA)

### Commits session 2026-06-18

| Hash | Message |
|------|---------|
| `1705658` | docs(ged): roadmap IA ClearMyDocs HTMLEDITOR split PDF |
| `61de7cd` | feat(ged): fondations plugin classificateur et split PDF |
| `24b2c93` | fix(ged): P0 UI chrome et dashboard |
| `a927a8e` | feat(ged): servir assets publics helper asset et outils audit |
| `14b7d23` | docs(ged): audits UI UX IA ingestion et synthese executive |
| `b55a49c` | fix(dev): nettoyer le port 8765 avant demarrage serveur |
| `fc9cbac` | fix(ged): diagnostic non-bloquant et snapshots total_size_bytes |
| `f7cf469` | fix(ged): redirect /kdocs sans slash vers /kdocs/ |
| `be14812` | docs(ged): diagnostic push 403 et mise a jour session |

**Branch** : `main` — **10 commits** en avance sur `origin/main` (403 persistant).

### Harness tests

```cmd
cd F:\DATA\DEVELOPPEMENT\GEDv1
php tests\migration_smoke_test.php    REM 37/37 offline
run-tests.bat                         REM migration + PHPUnit unit
```

**Dernier run** : **37 passés, 0 échoués** (migration_smoke_test, 2026-06-18)

### P0 UI — fait / reste

| Fait (lot `24b2c93` + `a927a8e`) | Reste (P0 audit) |
|-----------------------------------|------------------|
| Helper `asset()` + route `/public/*` — CSS/JS chargent sous base path | Séparer chrome user / admin (hub `/admin`) |
| Favicon SVG, pages 404/500 pro | Retirer emojis admin settings / documents |
| Masquer bannière sécurité root hors `APP_DEBUG` | Fix miniatures vides (placeholder uniforme) |
| Filtrer docs `test_*` dashboard + sidebar hors debug | Refactor `documents/index.php` (JS modale) |
| Compteur « En attente » aligné sidebar (`pending`/`needs_review`) | Design system composants Button/Card/Badge |
| Dashboard : icônes SVG à la place des emojis stats/actions | |

Docs audit : `docs/AUDIT-UI-UX.md`, `docs/AUDIT-SYNTHESE-EXECUTIVE.md`.

### IA — roadmap et fondations

| Livrable | Fichier / commit |
|----------|------------------|
| Roadmap architecture | `docs/IA-ROADMAP.md` (`1705658`) |
| Analyse ClearMyDocs v3 | `docs/IA-CLEARMYDOCS-INTEGRATION.md` (`1705658`) |
| `ClassifierInterface` | `app/Contracts/ClassifierInterface.php` |
| `PdfSplitInterface` + `PdfSplitService` | `app/Services/PdfSplit/PdfSplitService.php` |
| `UnifiedClassifier` (façade) | `app/Services/Classifiers/UnifiedClassifier.php` |
| `HtmleditorTaxonomyAdapter` | `app/Adapters/HtmleditorTaxonomyAdapter.php` |
| Config `.env.example` | `HTMLEDITOR_TAXONOMY_PATH`, `CLEARMYDOCS_*`, `IA_*` |

**ClearMyDocs** : trouvé à `F:\DATA\DEVELOPPEMENT\clearmydocs-v3` (Python v3, pipeline ingest→enrich→relations).

**Prochain lot IA recommandé (IA-2/IA-3)** :

1. Sidecar ClearMyDocs endpoint `/segment` (wrapper `segmenter.py`)
2. Brancher `PdfSplitService::detectPageGroups()` si `CLEARMYDOCS_ENABLED=true`
3. Endpoint GED `POST /api/classification/sync-taxonomy` + export HTMLEDITOR

### Push GitHub

| Élément | Détail |
|---------|--------|
| Remote | `https://github.com/opochon/kdocs.git` |
| Tentative 2026-06-18 | HTTP **403** — `Permission to opochon/kdocs.git denied` |
| Commits non poussés | `33106ab` … `1705658` (10 commits) |
| Action | Voir `docs/PUSH-DIAGNOSTIC.md` — PAT Contents write sur `kdocs` |

---

## État au 2026-06-17 (chantier lots 0–6)



### Lots complétés



| Lot | Description | Commit |

|-----|-------------|--------|

| 0 | Docs panorama + harness migration | `b999d56` |

| 1 | Stabilisation P0 (miniatures, OCR, badge validation) | `86852ad` |

| 2 | Infra dev (.env.example, env(), doc WAMP) | `a59aeee` |

| 3 | Plugin system + WinBiz + app invoices | `11fba6d` |

| 4 | Matching facture ↔ BL | `585bbb5` |

| 5 | Tests unit + harness étendu (37 checks) | `45006cb` |

| 6 | Documentation finale | *(ce commit)* |



### Fait cette session



- [x] Copie `C:\wamp64\www\kdocs` → `F:\DATA\DEVELOPPEMENT\GEDv1`

- [x] Documentation oracles, architecture, panorama REDX, delta

- [x] Harness `tests/migration_smoke_test.php` (37/37 offline)

- [x] Corrections P0 UI/OCR

- [x] `ConnectorInterface`, `PluginRegistry`, stubs `apps/invoices/`

- [x] `MatchingService::matchInvoiceToBL()` + UI rapprochement

- [x] Tests PHPUnit (matching, WinBiz, env)

- [x] Health check WinBiz dans `GET /health`



### Harness tests



```cmd

cd F:\DATA\DEVELOPPEMENT\GEDv1

php tests\migration_smoke_test.php    REM 37/37 offline

run-tests.bat                         REM migration + PHPUnit unit

```



**Dernier run** : 37 passés, 0 échoués (migration_smoke_test)



### Score parité REDX (estimé post-chantier)



| Indicateur | Avant | Après |

|------------|-------|-------|

| Parité fonctionnelle fiduciaire | ~48 % | **~52 %** |

| Gaps P0 bloquants | 4 partiels | **0 bloquants** (corrigés code) |

| Gaps P1 WinBiz/invoices | 6 partiels/absents | **3 partiels** (ODBC terrain restant) |



### Bloqueurs restants



| Bloqueur | Impact | Action |

|----------|--------|--------|

| WinBiz ODBC 32-bit non validé terrain | Pas d'intégration ERP réelle | Test poste avec driver FoxPro |

| Archivage légal Olico absent | Parité REDX impossible | Lot futur — `LegalArchiveService` |

| `composer.lock` désync | `composer install` échoue | `composer update` quand Composer dispo |

| App invoices désactivée par défaut | Routes inactives | `INVOICES_APP_ENABLED=true` dans `.env` |



### Prochaine fonction à traiter — Plugin WinBiz (liaison + consultation)

**Deux missions distinctes** du même plugin, via WinbizIntegrator (pas de duplication ODBC dans le core).

| Capacité | Priorité | Rôle |
|----------|----------|------|
| **`winbiz-matching`** | **P1** | Liaison document GED analysé ↔ références WinBiz (factures, BL, offres, stock) |
| **`winbiz-viewer`** | **P2** | Consultation lecture documents WinBiz depuis la GED (sans matching obligatoire) |

| Élément | Détail |
|---------|--------|
| Module externe | `F:\DATA\DEVELOPPEMENT\WinbizIntegrator` (`k-winbiz-bridge/`) |
| Stubs GEDv1 | `connectors/winbiz/`, `apps/invoices/` |
| Doc | `docs/WINBIZ-MODULE.md`, `docs/PLUGIN-SYSTEM.md` |
| Périmètre lecture | Factures (fourn. prioritaire), BL, offres, stock |

**Sous-tâches P1 — winbiz-matching :**

1. Créer `WinBizBridgeClient` — HTTP vers `k-winbiz-bridge` :5100 (prod ; ODBC = fallback dev)
2. Implémenter `WinBizMatchingService::matchDocumentToWinBiz()` — orchestration mission 1
3. Recherche croisée factures fournisseurs (`DO_COMPTE=2000`, `DO_TYPE` 20/30…)
4. Étendre matching BL (existant `matchInvoiceToBL`) + offres (`DO_TYPE` 1) + lignes stock
5. Persistance résultats (`persistMatch`, `getMatchStatus`) + UI écarts / date introduction WinBiz
6. Activer app invoices : `INVOICES_APP_ENABLED=true` après health check bridge

**Sous-tâches P2 — winbiz-viewer :**

7. Routes consultation : `/winbiz/documents`, `/winbiz/stock`, `/winbiz/search`
8. `WinBizViewerService` + templates lecture document (en-tête, lignes, partenaire, écritures)
9. Demander / consommer endpoints bridge dédiés (`GET /api/v1/documents/{numero}`, `/search`)

### Autres prochaines étapes

1. Couche archivage légal Olico (GAP-020+)
2. Extraire routes `index.php` → modules
3. OnlyOffice diagnostic terrain si édition requise



## Liens



- Panorama : `docs/PANORAMA-GED-REDX.md`

- Delta : `docs/DELTA-REDX.md`

- Corrections P0 : `docs/CORRECTIONS_PRIORITAIRES.md`

- Plugin system : `docs/PLUGIN-SYSTEM.md`



---

*Dernière mise à jour : 2026-06-18 — P0 UI crédibilité, fondations IA, audits*


## Blocage push GitHub (2026-06-17)

| Élément | Détail |
|---------|--------|
| Remote | `https://github.com/opochon/kdocs.git` |
| Commit local non poussé | `33106ab` — `docs(ged): spec plugin WinBiz liaison et consultation` |
| Erreur | HTTP 403 — PAT fine-grained sans accès écriture sur `kdocs` |
| Diagnostic | `docs/PUSH-DIAGNOSTIC.md` |
| Action utilisateur | Ajouter `opochon/kdocs` au PAT (Contents write) ou PAT classic `repo`, puis `gh auth login --with-token` |


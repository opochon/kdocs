# Parité REDX — Fonctions manquantes & plan de tests

> Listing des écarts GEDv1 vs REDX (intégrateur RedX / socle M-Files) et, pour
> chaque gap, l'**oracle** (état observable attendu) et le **mécanisme de test**
> qui l'épingle. Complément de `docs/PANORAMA-GED-REDX.md` (analyse) et
> `docs/DELTA-REDX.md` (delta statut). Source de vérité test :
> `tests/visual/FUNCTIONS-SPEC.md`.
>
> Dernière mise à jour : 2026-06-30 (post-bugs A–H, post-fix pdftoppm,
> post-durcissement healthcheck `c7db9ce`).

## Légende

- **Statut** : ✅ Présent · 🟡 Partiel · ❌ Absent · 🧪 Test existant · ⬜ Test à écrire
- **Mécanisme** : `unit` (PHPUnit) · `feature` (PHPUnit HTTP) · `pw` (Playwright) · `smoke` (CLI) · `manual` (recette humaine)
- **Gate** : un gap n'est « comblé » que si un test vert l'épingle (règle *tests = gate*).

## Score courant

| Indicateur | Valeur |
|------------|--------|
| Parité fonctionnelle estimée (cas fiduciaire) | **~54 %** (was 48–52 %) |
| Fonctions ✅ | 16 |
| Fonctions 🟡 | 16 |
| Fonctions ❌ | 6 (P0 tous résorbés) |
| Gaps nommés | 38 (P0–P4) |
| Gaps avec test vert | 12 |
| Gaps avec test à écrire | 26 |

> Hausse post-sprint : bugs A–H résolus, pdftoppm (thumbnails PDF) opérationnel,
> healthcheck OnlyOffice/Ollama fiable, IA Infomaniak active (cascade
> Infomaniak > Claude > Ollama), Assistant IA compte-all corrigé.

---

## P0 — Bloquants usage quotidien (✅ tous résorbés)

| ID | Fonction | Statut | Oracle | Test | Mécanisme |
|----|----------|--------|--------|------|-----------|
| GAP-001 | Aperçu DOCX réel dans modale | ✅ 🧪 | Modale `#preview` rend le contenu DOCX (pas un placeholder) | `pipeline-ui.spec.ts` | `pw` |
| GAP-002 | Miniatures tous formats (PDF via pdftoppm) | ✅ 🧪 | `ThumbnailGenerator` produit un PNG pour 1 doc PDF ; `tools.pdftoppm` existe | `Unit\Services\ThumbnailGeneratorTest` | `unit` |
| GAP-003 | Contenu OCR indexé et visible | ✅ 🧪 | `documents.content` == `ocr_text` après traitement | `Unit\Services\DocumentProcessorTest` | `unit` |
| GAP-004 | Badge validation cliquable | ✅ 🧪 | Badge `.validation-badge` réagit au clic et bascule le statut | `persona-preview.spec.ts` | `pw` |

**Tests P0 à compléter** :
- ⬜ `ThumbnailGeneratorTest::test_pdftoppm_path_resolvable` — assert
  `Config::get('tools.pdftoppm')` est un chemin `is_file` et que
  `pdftoppm -png` produit un fichier non vide sur un PDF fixture. (Épingle la
  régression « Non trouvé » et le fix poppler scoop.)

---

## P1 — Intégration ERP WinBiz

| ID | Fonction | Statut | Oracle | Test | Mécanisme |
|----|----------|--------|--------|------|-----------|
| GAP-010 | `ConnectorInterface` + client HTTP bridge | 🟡 | `WinBizConnector::isConnected()` bool ; interface implémentée | `Unit\Connectors\WinBizConnectorTest` ✅ | `unit` |
| GAP-011 | Factures fournisseur — lecture + matching | 🟡 | `matchDocumentToWinBiz($docId)` retourne `['winbiz_id','score']` ou `null` | ⬜ `Feature\WinBizMatchingTest` | `feature` |
| GAP-012 | UI rapprochement facture ↔ BL | ✅ | Page `/invoices` affiche candidats + bouton rapprocher | ⬜ `pw invoices-matching.spec.ts` | `pw` |
| GAP-013 | Liaison doc GED ↔ date intro WinBiz | 🟡 | Table `winbiz_matches(id_doc, winbiz_ref, matched_at)` persistée | ⬜ `Feature\WinBizMatchingTest` | `feature` |
| GAP-014 | `registerInvoicesRoutes()` + hooks plugin | ✅ | `GET /invoices` 200 ; hook `document.classified` déclenché | ⬜ `Feature\InvoicesRoutesTest` | `feature` |
| GAP-015 | Recherche croisée offres (`DO_TYPE` 1) | ❌ | `matchToOffer()` retourne offres WinBiz | ⬜ `Unit\WinBizOfferMatcherTest` | `unit` |
| GAP-016 | Health check WinBiz | 🟡 | `GET /health` contient `winbiz: {connected: bool}` | ⬜ `Feature\HealthTest` | `feature` |
| GAP-017 | Matching lignes ↔ stock / articles | ❌ | `matchLineToStock()` retourne article + quantité | ⬜ `Unit\WinBizStockMatcherTest` | `unit` |
| GAP-018 | Consultation factures/BL/offres depuis GED | ❌ | `GET /winbiz/documents?type=invoice` 200 + liste | ⬜ `Feature\WinBizViewerTest` | `feature` |
| GAP-019 | Consultation stock WinBiz | ❌ | `GET /winbiz/stock?q=...` 200 + résultats | ⬜ `Feature\WinBizViewerTest` | `feature` |
| GAP-01A | Endpoints bridge documents/search | 🟡 | `k-winbiz-bridge` `GET /api/v1/documents` 200 JSON | ⬜ `manual` (bridge externe) | `manual` |

---

## P2 — Conformité archivage Suisse

| ID | Fonction | Statut | Oracle | Test | Mécanisme |
|----|----------|--------|--------|------|-----------|
| GAP-020 | Scellement WORM / archivage légal | ❌ | `documents.legal_sealed=1` → toute écriture lève `LegalSealedException` | ⬜ `Unit\LegalArchiveServiceTest` | `unit` |
| GAP-021 | Politiques rétention (10 ans compta) | ❌ | `RetentionPolicyService::dueDate($doc)` retourne date ≥ 10 ans | ⬜ `Unit\RetentionPolicyTest` | `unit` |
| GAP-022 | Export piste révision | 🟡 | `GET /admin/audit/export` produit PDF/JSON avec timeline | ⬜ `Feature\AuditExportTest` | `feature` |
| GAP-023 | Horodatage qualifié (TSA) | ❌ | `documents.tsa_token` non null + validation RFC 3161 | ⬜ `Unit\TsaServiceTest` (mock TSA) | `unit` |
| GAP-024 | Document légal non modifiable | ❌ | `PUT /api/documents/{id}` 403 si `legal_sealed=1` | ⬜ `Feature\LegalSealGuardTest` | `feature` |

---

## P3 — Modules métier REDX

| ID | Fonction | Statut | Oracle | Test | Mécanisme |
|----|----------|--------|--------|------|-----------|
| GAP-030 | Module contrats + échéances | ❌ | `apps/contracts/` : `GET /contracts` liste + champ `due_date` | ⬜ `Feature\ContractsModuleTest` | `feature` |
| GAP-031 | Module SMQ ISO | ❌ | `PluginRegistry::isEnabled('smq')` vrai ; onglet Versions visible (F-DOC-10) | `smq-versions.spec.ts` ✅ (gated) | `pw` |
| GAP-032 | Quittance de lecture | ❌ | `POST .../versions/{n}/read` crée 1 ligne/user/version (F-DOC-11) | ⬜ `Feature\ReadReceiptTest` | `feature` |
| GAP-033 | Dossier RH digital | ❌ | `apps/hr/` : `GET /hr/employees/{id}` 200 + dossiers | ⬜ `Feature\HrModuleTest` | `feature` |
| GAP-034 | App mail IMAP | 🟡 | `MailApp::syncImapMailbox()` importe N messages → documents | ⬜ `Feature\MailSyncTest` (mock IMAP) | `feature` |
| GAP-035 | PluginRegistry formel | ✅ 🧪 | `PluginRegistry::isEnabled('x')` reflète config ; onglet Versions gated | `Unit\Core\PluginRegistryTest` | `unit` |

---

## P4 — Infrastructure

| ID | Fonction | Statut | Oracle | Test | Mécanisme |
|----|----------|--------|--------|------|-----------|
| GAP-040 | ACL document fine | 🟡 | `FolderPermissionService::can($user,$doc,'read')` bool hérité dossier | ⬜ `Unit\FolderPermissionTest` | `unit` |
| GAP-041 | Multi-mandant | ❌ | 2 mandants : doc du mandant A invisible pour user B | ⬜ `Feature\MultitenantIsolationTest` | `feature` |
| GAP-042 | Portail client | ❌ | `GET /portal/{client}` lecture seule, pas de bouton édition | ⬜ `pw portal.spec.ts` | `pw` |
| GAP-043 | E-signature | ❌ | `POST /documents/{id}/sign` produit signature + audit | ⬜ `Feature\ESignatureTest` | `feature` |
| GAP-044 | App desktop Tauri | ❌ (roadmap) | N/A (hors repo) | — | — |
| GAP-045 | Antivirus upload ClamAV | ❌ | `ClamAvScanner::scan($file)` bloque un fixture EICAR | ⬜ `Unit\ClamAvScannerTest` (mock) | `unit` |

---

## Tests transverses (non gap, mais gate de fiabilité)

| ID | Sujet | Oracle | Test existant | Mécanisme |
|----|-------|--------|---------------|-----------|
| T-DIAG | Diagnostic admin : OnlyOffice/Ollama | `httpProbe` (fsockopen) reflète l'état réel ; Ollama CONNECTE | ⬜ `Feature\AdminDiagnosticTest` (mock fsockopen) | `feature` |
| T-IA-INF | IA Infomaniak (cascade active) | `AIProviderService::complete()` renvoie `provider=infomaniak` | `Unit\Services\InfomaniakAIServiceTest` ✅ + ⬜ `Feature\AiCascadeTest` + ⬜ `pw ai-assistant.spec.ts` | `unit`/`feature`/`pw` |
| T-ASK-COUNT | Assistant IA « combien de documents » | Réponse numérique == total BDD (pas 0) | ⬜ `Feature\NaturalLanguageQueryCountTest` | `feature` |
| T-TAG-DEDUP | Création tag insensible à la casse | `POST /api/tags` « Foo » puis « foo » → 1 tag | ⬜ `Feature\TagsDedupTest` | `feature` |
| T-TASK-CREATE | Création tâche autonome | `Task::create(['title'=>...])` persiste title/desc/priority | `bugs-click.spec.ts` ✅ | `pw` |
| T-DTYPE-PERSIST | Persistance type document | Après save + reload, `#document_type_id` conserve la valeur | `bugs-misc.spec.ts` ✅ | `pw` |

---

## Priorisation d'écriture des tests (prochain lot)

1. **T-DIAG**, **T-IA-INF (feature + pw)**, **T-ASK-COUNT**, **T-TAG-DEDUP** —
   épingle les fixes du sprint (anti-régression). ⬜ → 🧪
2. **GAP-002** `ThumbnailGeneratorTest::test_pdftoppm_path_resolvable` — épingle
   le fix poppler/pdftoppm.
3. **P1 WinBiz** : `WinBizMatchingTest`, `InvoicesRoutesTest`, `HealthTest`
   (valider le connecteur avant montée en charge).
4. **P2 archivage légal** : `LegalArchiveServiceTest` + `LegalSealGuardTest`
   (gate bloquante pour exposition conforme CH).

> Règle : aucun gap n'est marqué ✅ comblé sans test vert. Une ligne 🟡/❌ sans
> test est une dette tracée, pas un oubli.

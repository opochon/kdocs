# Parité REDX — Fonctions manquantes & plan de tests

> Listing des écarts GEDv1 vs REDX (intégrateur RedX / socle M-Files) et, pour
> chaque gap, l'**oracle** (état observable attendu) et le **mécanisme de test**
> qui l'épingle. Complément de `docs/PANORAMA-GED-REDX.md` (analyse) et
> `docs/DELTA-REDX.md` (delta statut). Source de vérité test :
> `tests/visual/FUNCTIONS-SPEC.md`.
>
> Dernière mise à jour : 2026-07-02 (sprint parité : Lot E admin 35/35, P2 scellement
> WORM + rétention, quittance lecture épinglée, CmdV4 étape 6 suite ; WinBiz reporté plugin).

## Légende

- **Statut** : ✅ Présent · 🟡 Partiel · ❌ Absent · 🧪 Test existant · ⬜ Test à écrire · 🔌 Plugin (hors socle ECM)
- **Mécanisme** : `unit` (PHPUnit) · `feature` (PHPUnit HTTP) · `pw` (Playwright) · `smoke` (CLI) · `harness` (`run-harness.bat`) · `manual` (recette humaine)
- **Gate** : un gap n'est « comblé » que si un test vert l'épingle (règle *tests = gate*).

## Score courant

| Indicateur | Valeur |
|------------|--------|
| Parité fonctionnelle estimée (cas fiduciaire) | **~60 %** (+4 pts : P2 scellement WORM opérationnel, SMQ + quittance vérifiés) |
| Gaps ✅ | 18 (dont P2 GAP-020/021/024, P3 GAP-031/032 ce sprint) |
| Gaps 🟡 | 8 (WinBiz plugin ×5, GAP-022 export audit, GAP-034 mail, GAP-040 ACL) |
| Gaps ❌ | 12 (triés : WinBiz plugin ×4, TSA, modules P3 ×2, infra P4 ×5) |
| Gaps nommés | 38 (P0–P4) + 6 gates ECM (P1b) |
| Gaps avec test vert | **24** (+6 sprint parité 2026-07-02) |
| Gaps avec test à écrire | 14 |

> Les ❌ restants sont des **features roadmap triées** (plugin WinBiz reporté,
> modules Contrats/RH, TSA, multi-mandant, portail, e-signature, ClamAV, Tauri) —
> aucun encours ouvert ; chaque ligne reste une dette tracée avec oracle défini.

> Hausse post-lots 1–3 : types ECM catalogués, persona REDX, persistance type via PUT JSON
> (BodyParsingMiddleware), workflow identification UI+API, oracles PHPUnit hermétiques,
> harness `run-harness.bat` (43 Playwright passed). **WinBiz P1 = plugin — non compté
> tant que identification auto (Lot B) n'est pas verte.**

---

## P0 — Bloquants usage quotidien (✅ tous résorbés)

| ID | Fonction | Statut | Oracle | Test | Mécanisme |
|----|----------|--------|--------|------|-----------|
| GAP-001 | Aperçu DOCX réel dans modale | ✅ 🧪 | Modale `#preview` rend le contenu DOCX (pas un placeholder) | `pipeline-ui.spec.ts` | `pw` |
| GAP-002 | Miniatures tous formats (PDF via pdftoppm) | ✅ 🧪 | `ThumbnailGenerator` produit un PNG pour 1 doc PDF ; `tools.pdftoppm` existe | `Unit\Services\ThumbnailGeneratorTest` | `unit` |
| GAP-003 | Contenu OCR indexé et visible | ✅ 🧪 | `documents.content` == `ocr_text` après traitement | `Unit\Services\DocumentProcessorTest` | `unit` |
| GAP-004 | Badge validation cliquable | ✅ 🧪 | Badge `.validation-badge` réagit au clic et bascule le statut | `persona-preview.spec.ts` | `pw` |

**Tests P0 à compléter** :
- ✅ `ThumbnailGeneratorTest` — assert `Config::get('tools.pdftoppm')` exécutable (commit `0c11745`).

---

## P1b — Identification documentaire ECM (socle REDX, avant WinBiz)

> Référence workflow : `docs/WORKFLOW-DOCUMENTAIRE.md`. **WinBiz = P1 plugin séparé** —
> aucune gate persona ne couvre `/invoices` tant que P1b n'est pas opérationnel.

| ID | Fonction | Statut | Oracle | Test | Mécanisme |
|----|----------|--------|--------|------|-----------|
| GAP-050 | Catalogue types ECM (5 types) | ✅ 🧪 | BDD contient Facture, Note de crédit, Contrat, Courrier, Reçu | `eval-full` gate `G6-doc-types-ecm` | `smoke` |
| GAP-051 | Persona expert ECM REDX | ✅ 🧪 | `eval_redx_expert` login + fiche + droits validation | `persona-redx-expert.spec.ts` + gate `G6-persona-redx-expert` | `pw` / `smoke` |
| GAP-052 | Édition type en UI + persistance API | ✅ 🧪 | Save preview → `GET /api/documents/{id}` retourne `document_type_id` | `workflow-doc-identification.spec.ts` | `pw` |
| GAP-053 | Patterns identification types (regex) | ✅ 🧪 | Heuristique reconnaît libellés ECM sur texte fixture | `DocumentTypeIdentificationTest` | `unit` |
| GAP-054 | Badge certitude classification | ✅ 🧪 | `#ai-confidence-badge` attaché ; visible après classify | `ai-confidence-badge.spec.ts` | `pw` |
| GAP-055 | Identification **auto** fiable par type | ✅ 🧪 | Distribution lot eval : Reçu≥2, Courrier≥1, Contrat≥1, ECM≥5/8 | gate `G7-classify-distribution` + `DocumentTypeIdentificationTest` | `smoke` / `unit` |

**Commit lots 1–3** : `0c11745` · harness : `run-harness.bat` (43 pw passed, 2 skipped).

---

## P1 — Intégration ERP WinBiz 🔌 Plugin (reporté)

| ID | Fonction | Statut | Oracle | Test | Mécanisme |
|----|----------|--------|--------|------|-----------|
| GAP-010 | `ConnectorInterface` + client HTTP bridge | 🟡 | `WinBizConnector::isConnected()` bool ; interface implémentée | `Unit\Connectors\WinBizConnectorTest` ✅ | `unit` |
| GAP-011 | Factures fournisseur — lecture + matching | 🟡 | `matchDocumentToWinBiz($docId)` retourne `['winbiz_id','score']` ou `null` | ⬜ `Feature\WinBizMatchingTest` | `feature` |
| GAP-012 | UI rapprochement facture ↔ BL | ✅ | Page `/invoices` affiche candidats + bouton rapprocher | ⬜ `pw invoices-matching.spec.ts` | `pw` |
| GAP-013 | Liaison doc GED ↔ date intro WinBiz | 🟡 | Table `winbiz_matches(id_doc, winbiz_ref, matched_at)` persistée | ⬜ `Feature\WinBizMatchingTest` | `feature` |
| GAP-014 | `registerInvoicesRoutes()` + hooks plugin | ✅ | `GET /invoices` 200 ; hook `document.classified` déclenché | ⬜ `Feature\InvoicesRoutesTest` | `feature` |
| GAP-015 | Recherche croisée offres (`DO_TYPE` 1) | ❌ | `matchToOffer()` retourne offres WinBiz | ⬜ `Unit\WinBizOfferMatcherTest` | `unit` |
| GAP-016 | Health check WinBiz | 🟡 🧪 | `GET /api/admin/connectors/health` contient `erp-winbiz: {available: bool}` (disabled sans bridge) | `Unit\Core\ConnectorRegistryTest` ✅ + `admin-hub.spec.ts` F-ADM-04 ✅ | `unit`/`pw` |
| GAP-017 | Matching lignes ↔ stock / articles | ❌ | `matchLineToStock()` retourne article + quantité | ⬜ `Unit\WinBizStockMatcherTest` | `unit` |
| GAP-018 | Consultation factures/BL/offres depuis GED | ❌ | `GET /winbiz/documents?type=invoice` 200 + liste | ⬜ `Feature\WinBizViewerTest` | `feature` |
| GAP-019 | Consultation stock WinBiz | ❌ | `GET /winbiz/stock?q=...` 200 + résultats | ⬜ `Feature\WinBizViewerTest` | `feature` |
| GAP-01A | Endpoints bridge documents/search | 🟡 | `k-winbiz-bridge` `GET /api/v1/documents` 200 JSON | ⬜ `manual` (bridge externe) | `manual` |

---

## P2 — Conformité archivage Suisse

| ID | Fonction | Statut | Oracle | Test | Mécanisme |
|----|----------|--------|--------|------|-----------|
| GAP-020 | Scellement WORM / archivage légal | ✅ 🧪 | `documents.legal_sealed=1` → toute écriture lève `LegalSealedException` ; `POST /api/documents/{id}/legal-seal` (idempotent) | `Unit\Services\Compliance\LegalArchiveServiceTest` ✅ + `legal-seal.spec.ts` ✅ | `unit`/`pw` |
| GAP-021 | Politiques rétention (10 ans compta) | ✅ 🧪 | `RetentionPolicyService::dueDate($doc)` retourne date ≥ 10 ans (CO 958f) ; `retention_until` fixé au scellement | `Unit\Services\Compliance\RetentionPolicyTest` ✅ | `unit` |
| GAP-022 | Export piste révision | 🟡 | `GET /admin/audit/export` produit PDF/JSON avec timeline | ⬜ `Feature\AuditExportTest` | `feature` |
| GAP-023 | Horodatage qualifié (TSA) | ❌ | `documents.tsa_token` non null + validation RFC 3161 | ⬜ `Unit\TsaServiceTest` (mock TSA) | `unit` |
| GAP-024 | Document légal non modifiable | ✅ 🧪 | `PUT/DELETE /api/documents/{id}` (+ `/type`, `/correspondent`, `/fields`) 403 si `legal_sealed=1` ; GET reste 200 | `Unit\Controllers\LegalSealGuardTest` ✅ + `legal-seal.spec.ts` ✅ | `unit`/`pw` |

---

## P3 — Modules métier REDX

| ID | Fonction | Statut | Oracle | Test | Mécanisme |
|----|----------|--------|--------|------|-----------|
| GAP-030 | Module contrats + échéances | ❌ | `apps/contracts/` : `GET /contracts` liste + champ `due_date` | ⬜ `Feature\ContractsModuleTest` | `feature` |
| GAP-031 | Module SMQ ISO | ✅ 🧪 | `PluginRegistry::isEnabled('smq')` vrai ; onglet Versions visible (F-DOC-10) | `smq-versions.spec.ts` ✅ (gated) + `PluginRegistryTest` | `pw`/`unit` |
| GAP-032 | Quittance de lecture | ✅ 🧪 | `POST .../versions/{n}/read` crée 1 ligne/user/version (F-DOC-11), idempotent + `read-status` | `smq-versions.spec.ts` « quittance de lecture » ✅ | `pw` |
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
| T-DIAG | Diagnostic admin : OnlyOffice/Ollama | Page diagnostic rendue + `GET /api/admin/connectors/health` 200 JSON | `admin-hub.spec.ts` F-ADM-04 ✅ | `pw` |
| T-IA-INF | IA Infomaniak (cascade active) | `AIProviderService::complete()` renvoie `provider=infomaniak` | `InfomaniakAIServiceTest` ✅ + `AiCascadeInfomaniakTest` ✅ + `ai-assistant.spec.ts` ✅ | `unit`/`pw` |
| T-ASK-COUNT | Assistant IA « combien de documents » | Réponse numérique == total BDD (pas 0) | `NaturalLanguageQueryCountTest` ✅ | `unit` |
| T-TAG-DEDUP | Création tag insensible à la casse | find-or-create normalise la casse | `TagsDedupTest` ✅ (logique hermétique) | `unit` |
| T-TASK-CREATE | Création tâche autonome | `Task::create(['title'=>...])` persiste title/desc/priority | `bugs-click.spec.ts` ✅ | `pw` |
| T-DTYPE-PERSIST | Persistance type document | Après save + reload, type conservé | `bugs-misc.spec.ts` ✅ + `workflow-doc-identification.spec.ts` ✅ | `pw` |
| T-HARNESS | Gate bout en bout finalisation | migration + PHPUnit + eval-full + Playwright | `run-harness.bat` ✅ | `harness` |
| T-WORKFLOW-DOC | Doc workflow documentaire | `docs/WORKFLOW-DOCUMENTAIRE.md` à jour, cohérent avec gates | revue doc (Lot 0) ✅ | `manual` |

---

## Priorisation d'écriture des tests (prochain lot)

1. ~~**GAP-055** — identification auto fiable~~ ✅ (gate `G7-classify-distribution`).
2. ~~**F-CHROME-02** / **F-CHROME-08**~~ ✅ (commit `5b1d70a`, chrome-coherence 8/8).
3. ~~**P2 archivage légal**~~ ✅ (`LegalArchiveServiceTest` + `LegalSealGuardTest` +
   `legal-seal.spec.ts` — scellement WORM + rétention opérationnels).
4. **P1 WinBiz 🔌** — plugin reporté ; à l'activation du bridge : `WinBizMatchingTest`,
   `InvoicesRoutesTest`.
5. **GAP-022** export piste révision (JSON) + **GAP-040** ACL dossier — prochains
   candidats socle ; **GAP-023 TSA** derrière un fournisseur d'horodatage.

> Règle : aucun gap n'est marqué ✅ comblé sans test vert. Une ligne 🟡/❌ sans
> test est une dette tracée, pas un oubli.

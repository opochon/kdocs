# GEDv1 — Ingestion ClearMyDocs v3 (analyse complète)

> Date : 2026-06-18 · Repo : `F:\DATA\DEVELOPPEMENT\clearmydocs-v3`  
> Complète `IA-CLEARMYDOCS-INTEGRATION.md` (focus sidecar `/segment`) et `IA-ROADMAP.md`.

## Synthèse (5–8 bullets)

1. **ClearMyDocs v3** n'est pas qu'un segmenter : l'ingestion complète vit dans `indexer.py` (`run_full_indexation`) — scan → extraction/OCR → dédup → enrichissement LLM → segmentation PDF inline → vectorisation → (relations / synthèse / evaluate en aval).
2. **Point d'entrée production** : API `POST /api/index` (`legacy_routes.py`) ou appel direct `run_full_indexation(IndexManager)` ; le sidecar GED (`ged_sidecar.py`) expose **uniquement** `/segment` aujourd'hui.
3. **Formats** : `.msg`, `.pdf`, Office (doc/docx/xls/xlsx/pptx), images (Tesseract OCR), texte (txt/csv/xml/html/md) — aligné GED mais implémentation Python (extractor + LibreOffice/Tesseract).
4. **Classification à l'ingest** : `_classify_document` (legacy v2) + profils `builtin_profiles/` ; enrichissement produit `semantic_summary`, entités → table `facts` (ground truth evaluateur).
5. **Providers LLM** : routage `providers.py` — Ollama par défaut, Anthropic/OpenAI/Gemini, **Infomaniak AI Tools** si `llm_backend=infomaniak` (`providers_infomaniak.py`, POC HTMLEDITOR).
6. **GEDv1 actuel** : `DocumentProcessor` (OCR PHP, matching, thumbnail) → queue `IngestClassificationService` → split PDF (sidecar ou `PDFSplitterService`) → `UnifiedClassifier` (cascade training/Claude/Ollama + taxonomie HTMLEDITOR).
7. **Écart principal** : GED orchestre et persiste ; ClearMyDocs fait extraction + enrich + facts + embeddings dans **sa** DB PostgreSQL — pas le modèle document GED.
8. **Cible** : GED **orchestrateur** (entrée fichiers, BDD métier, workflows) + ClearMyDocs **worker ingestion** (extraction lourde, segment, enrich LLM) via sidecar étendu ou job Python dédié.

---

## Pipeline ClearMyDocs v3 — entrée → classifié

### Vue d'ensemble

```
sources (dossiers projet)
    │
    ▼
collect_files + filtres contenu
    │
    ▼
PHASE 1 — extraction (parallèle)
    │  extractor.py : PDF, Office, MSG, images OCR
    │  pj_preingest.py : PJ email, dédup hash
    ▼
PHASE 2 — flush buffer → documents (PostgreSQL)
    │  _enrich_document : summary, condensed, entities
    │  si PDF multi-pages : segment_pdf + classify par segment
    │  entities → facts (ground_truth)
    ▼
PHASE 3 — vectorisation (Ollama embeddings)
    │
    ▼
(avant UC4) relations_agent (transverse)
    │
    ▼
pipeline UC4 : synthesize → evaluate → (loop correctrice)
```

Le dispatcher `pipeline.py` couvre **relations + synthèse + evaluate** ; l'ingestion documentaire est **amont**, dans `indexer.py`.

### Modules et entry points

| Rôle | Fichier / route | Notes |
|------|-----------------|-------|
| Indexation complète | `core/indexer.py` → `run_full_indexation()` | Cœur ingest |
| API index | `api/legacy_routes.py` → `POST /api/index` | Lance sous-process `run_index.py` |
| API serveur | `api/server.py` | UC4 + routes legacy |
| Sidecar GED | `api/ged_sidecar.py` | `/health`, `/segment` seulement |
| Segmentation | `core/segmenter.py` | Heuristiques + option LLM confirm |
| Extraction | `core/extractor.py` | OCR Tesseract, pdftotext, etc. |
| Classification ingest | `_legacy_v2/classifier.py` | `classify_document()` |
| Enrichissement | `indexer._enrich_document` | LLM summary, entités |
| PJ / dédup | `core/pj_preingest.py` | Hash corpus + batch |
| Providers cloud | `core/providers_infomaniak.py` | Infomaniak AI Tools |
| Routage LLM | `providers.py` → `chat_llm()` | Ollama + frontier + Infomaniak |
| Pipeline aval | `core/pipeline.py` | Relations, synthèse, evaluate |
| CLI / bench | `scripts/run_all.py`, `harness_e2e.py` | `--skip-ingestion` pour réutiliser DB |
| Brique socle | `scripts/bricks/b0_socle.py` | Qualité `extracted_text` |
| v4 expérimental | `v4/cmd4/ingest.py` | Corpus `.txt/.md` seulement (faithfulness) |

### Formats supportés

Définis dans `core/database.py` (`EXTENSIONS`) :

| Type | Extensions | Extracteur |
|------|------------|------------|
| Email | `.msg` | `extract_msg` |
| PDF | `.pdf` | `extract_pdf` (+ segmentation si > 2 pages) |
| Word | `.doc`, `.docx` | `extract_doc`, `extract_docx` |
| Tableur | `.xls`, `.xlsx` | `extract_xls`, `extract_xlsx` |
| Présentation | `.pptx` | `extract_pptx` |
| Image | png, jpg, tiff, bmp… | `extract_image_ocr` (Tesseract) |
| Texte | txt, csv, xml, html, md | `extract_text_file` |

GED couvre un périmètre **similaire** via `OCRService.php` (pdftotext, Tesseract, PhpWord) mais sans MSG natif côté PHP documenté au même niveau.

### OCR et enrichissement

- **OCR** : Tesseract (chemins Windows dans `database.py`), PDF via couche extractor (pdftotext / rasterisation pages si besoin).
- **Enrichissement** (`with_llm_summary=true` par défaut) : résumé sémantique, texte condensé, score structurel, entités extraites → insertion `facts`.
- **Segmentation** : lors du flush buffer, PDF > 2 pages → `segment_pdf` → segments insérés comme `document_segments` + classification par segment.
- **Profils** : `legal_ch`, presets `builtin_profiles/` — pas la taxonomie Stoco HTMLEDITOR.

### Relations et evaluate

- **Relations** : `relations_agent.detect_transverse_relations` — nécessite enrichissement (`facts` non vide).
- **Evaluate** : `evaluator/orchestrator.run_evaluation_loop` + `llm_judge` — boucle correctrice sur livrable markdown.
- **Hors scope ingest GED** sauf inspiration pour audit qualité classification.

### Providers (Infomaniak, Ollama, frontier)

| Backend | Activation | Usage typique |
|---------|------------|---------------|
| Ollama | défaut `chat_model` | Index enrich, embeddings, segment LLM confirm |
| Anthropic / OpenAI / Gemini | clés API dans config | Classification, synthèse |
| Infomaniak | `llm_backend=infomaniak` + `INFOMANIAK_API_TOKEN` / `INFOMANIAK_PRODUCT_ID` | Drop-in `chat_llm` (max_tokens 5000) |

Référence POC : `htmleditor_v3/htmleditor/scripts/ai-poc-test-2-infomaniak.js`.

---

## Pipeline GEDv1 actuel

### Flux document entrant

```
Upload / ConsumeFolder / FolderIndexer
    │
    ▼
DocumentProcessor::process()
    │ 1. OCR (OCRService) → content / ocr_text
    │ 1.5 IngestClassificationService::queue()  [async]
    │ 2. MatchingService (tags, correspondants)
    │ 3. Thumbnail
    │ 4. Workflows
    ▼
Queue worker → ClassifyDocumentJob
    │
    ▼
IngestClassificationService::classify()
    │ si PDF : PdfSplitService
    │   → ClearMyDocsSidecarClient /segment OU PDFSplitterService (Claude)
    │ sinon : UnifiedClassifier::classifyDocument()
    ▼
classification_suggestions + persistance champs
```

Fichiers clés :

| Composant | Fichier |
|-----------|---------|
| Traitement entrée | `app/Services/DocumentProcessor.php` |
| Import dossier | `app/Services/ConsumeFolderService.php` |
| Classification ingest | `app/Services/Classification/IngestClassificationService.php` |
| Façade classif | `app/Services/Classifiers/UnifiedClassifier.php` |
| Split PDF | `app/Services/PdfSplit/PdfSplitService.php` |
| Client sidecar | `app/Services/ClearMyDocsSidecarClient.php` |

---

## Tableau comparatif GEDv1 vs ClearMyDocs v3

| Dimension | GEDv1 (PHP) | ClearMyDocs v3 (Python) |
|-----------|-------------|-------------------------|
| **Orchestration ingest** | `DocumentProcessor` + queue | `run_full_indexation` |
| **Point d'entrée API** | Routes GED `/api/documents` | `POST /api/index` (+ sidecar `/segment`) |
| **Stockage métier** | MySQL `documents`, tags, workflows | PostgreSQL `documents`, `facts`, `chunks` |
| **OCR / extraction** | PHP `OCRService` | `extractor.py` + Tesseract/LibreOffice |
| **Dédup** | Hash fichier GED | `file_hash`, `pj_preingest`, `excluded_files` |
| **Split PDF multi-doc** | Sidecar segment ou Claude page-par-page | `segmenter.py` intégré à l'indexation |
| **Classification** | `UnifiedClassifier` (training + Claude + Ollama + HTMLEDITOR taxonomie) | `classify_document` + profils domaine CMD |
| **Enrichissement LLM** | Partiel (champs IA, suggestions) | Complet (summary, entities → facts) |
| **Embeddings / recherche** | Ollama/Qdrant (GED) | Ollama embeddings dans index CMD |
| **Relations transverses** | Absent | `relations_agent` |
| **Evaluate / ground truth** | `classification_audit_logs` | `facts` + `evaluator` + llm_judge |
| **Taxonomie Stoco** | `HtmleditorTaxonomyAdapter` | Non — profils CMD séparés |
| **kDrive Infomaniak** | Connecteur stockage GED | Non (sources locales projet) |
| **Infomaniak IA** | Stub `InfomaniakClassifierAdapter` | `providers_infomaniak.py` opérationnel |

---

## Déléguer à ClearMyDocs vs garder en PHP GED

| Capacité | Déléguer ClearMyDocs | Garder GED PHP | Commentaire |
|----------|---------------------|--------------|-------------|
| Scan dossiers consume / kDrive | Non | **Oui** | GED = point d'entrée métier |
| OCR / extraction binaire | **Oui** (option A) | Fallback PHP | Python plus riche (MSG, bench qualité) |
| Dédup PJ email | **Oui** | Hash simple GED | `pj_preingest` mature |
| Split PDF | **Oui** (déjà partiel) | Fallback Claude | Sidecar `/segment` en place |
| Classification taxonomie Stoco | Non | **Oui** | `UnifiedClassifier` + HTMLEDITOR |
| Enrichissement → facts | **Oui** (lot futur) | Persistance champs GED | Mapper facts → `custom_fields` |
| Embeddings document | Partagé | **Oui** (Qdrant GED) | Éviter double index si possible |
| Relations transverses | **Oui** (P2) | — | Pas P0 GED |
| Synthèse / evaluate livrable | Non | Chat GED si besoin | Hors ingest |
| Workflows, WinBiz, droits | Non | **Oui** | Métier GED |
| Training utilisateur | Non | **Oui** | Corrections → cascade GED |
| Queue / workers | Non | **Oui** | `QueueService`, workers PHP |

---

## Architecture cible : GED orchestrateur + ClearMyDocs worker

```
┌─────────────────────────────────────────────────────────────┐
│ GEDv1 (PHP) — orchestrateur                                 │
│  ConsumeFolder / upload / kDrive                            │
│  DocumentProcessor (léger : thumb, matching, queue)         │
│  IngestClassificationService                                │
│  UnifiedClassifier + taxonomie HTMLEDITOR                   │
│  MySQL documents, workflows, WinBiz                         │
└───────────────────────────┬─────────────────────────────────┘
                            │ HTTP sidecar étendu ou job CLI
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ ClearMyDocs worker (Python)                                 │
│  POST /ingest  {path, profile, options}                     │
│    → extract + enrich + segment + facts (réponse JSON)      │
│  POST /segment  (existant)                                  │
│  Option batch : run_full_indexation sur corpus temporaire   │
└───────────────────────────┬─────────────────────────────────┘
                            │ JSON : segments, doc_type, facts, summary
                            ▼
                    GED persiste + UnifiedClassifier mappe taxonomie
```

Le worker **ne remplace pas** la BDD GED : il retourne des artefacts structurés que GED fusionne.

---

## Options ingestion unifiée

### Option A — Sidecar ClearMyDocs full ingest (recommandée)

Étendre `ged_sidecar.py` :

| Endpoint | Rôle |
|----------|------|
| `/segment` | Existant — split PDF |
| `/extract` | OCR + texte brut (sans DB CMD) |
| `/ingest` | extract + enrich + classify + segment (sans persistance CMD) |
| `/health` | Existant |

GED appelle `/ingest` après upload, mappe la réponse vers `documents` + `IngestClassificationService`.

**Avantages** : un seul moteur Python, bench CMD réutilisable, Infomaniak/Ollama centralisés.  
**Risques** : latence, déploiement Python sur serveur (Cloud Infomaniak : Node managé ≠ Python — worker sur VM ou service dédié).

### Option B — Port progressif patterns → PHP

Reprendre en PHP : heuristiques `segmenter.py`, scoring structure, règles dédup PJ.  
Garder LLM via `UnifiedClassifier` / Claude API.

**Avantages** : pas de sidecar, aligné hébergement PHP mutualisé.  
**Risques** : double maintenance, écart qualité vs bench CMD.

### Priorités lots

| Lot | Contenu | Priorité |
|-----|---------|----------|
| IA-8 | `/extract` sidecar + GED fallback OCR | P1 |
| IA-9 | `/ingest` sidecar (enrich + classify profil `legal_ch` ou mapping GED) | P1 |
| IA-10 | Mapper réponse CMD → champs GED + taxonomie HTMLEDITOR | P1 |
| IA-11 | Bench A/B GED : OCR PHP vs CMD sur corpus multi-PDF | P2 |
| IA-12 | Relations transverses (optionnel, chat / audit) | P3 |
| IA-13 | Déployer worker Python (systemd) sur Cloud Infomaniak | P2 infra |

---

## Flowy — pourquoi mentionné, pertinent ou non ?

Voir mise à jour `IA-ROADMAP.md`. En bref :

- **Flowy** apparaît dans la requête utilisateur initiale (« IA doit reprendre HTMLEDITOR ou FLOWY > Infomaniak ») et dans `AUDIT-IA-CLASSIFICATEUR.md`.
- **Aucun dépôt Flowy** sur `F:\DATA\DEVELOPPEMENT` ni référence dans `htmleditor_v3`.
- **Infomaniak** dans ce projet = hébergement kDrive + API AI Tools (ClearMyDocs / POC HTMLEDITOR), pas un produit « Flowy » documenté localement.
- **Verdict** : specs Flowy **non requises** pour la trajectoire GED + ClearMyDocs + taxonomie HTMLEDITOR, sauf si l'utilisateur fournit un repo ou une API Flowy distincte.

---

## Références

- `docs/IA-CLEARMYDOCS-INTEGRATION.md` — sidecar `/segment`
- `docs/IA-ROADMAP.md` — lots IA
- `docs/AUDIT-IA-CLASSIFICATEUR.md` — audit classificateur
- HTMLEDITOR Infomaniak : `htmleditor_v3/htmleditor/Release/ARCHITECTURE-INFOMANIAK.md`
- ClearMyDocs : `clearmydocs-v3/README.md`, `TESTING.md`, `src/clearmydocs/core/indexer.py`

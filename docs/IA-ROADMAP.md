# GEDv1 — Roadmap IA (UnifiedClassifier + split PDF)

> Date : 2026-06-18 · Chantier GEDv1 lot IA fondations

## Objectif

Unifier la classification documentaire GEDv1 en s'appuyant sur :

- la **cascade GED existante** (training → Claude → Ollama → règles) ;
- la **taxonomie HTMLEDITOR** (`_variables.json`, sections, tags) ;
- l'**ingestion ClearMyDocs v3** (extraction, enrich, segment — voir `IA-CLEARMYDOCS-INGESTION.md`) ;
- **Infomaniak kDrive** (stockage GED) + **Infomaniak AI Tools** (provider ClearMyDocs / POC HTMLEDITOR) ;
- modèles **frontier** (Claude API présente).

> **Flowy** : cité dans la requête utilisateur initiale (« HTMLEDITOR ou FLOWY > Infomaniak »).
> Aucun dépôt Flowy localisé sur le poste dev ; pas de référence dans HTMLEDITOR.
> **Non bloquant** pour cette roadmap — remplacé par ClearMyDocs (ingest) + taxonomie HTMLEDITOR.
> Réouvrir seulement si un repo ou API Flowy est fourni (cf. `AUDIT-IA-CLASSIFICATEUR.md`).

## Architecture cible

```
                    ┌─────────────────────────┐
                    │   UnifiedClassifier     │
                    │   (plugin / façade)     │
                    └───────────┬─────────────┘
          ┌─────────────────────┼─────────────────────┐
          ▼                     ▼                     ▼
 ┌─────────────────┐  ┌──────────────────┐  ┌──────────────────┐
 │ GEDNative       │  │ Htmleditor       │  │ ClearMyDocs      │
 │ AIClassifier +  │  │ TaxonomyAdapter  │  │ Sidecar (Python) │
 │ TrainingService │  │ (_variables.json)│  │ segment + enrich │
 └─────────────────┘  └──────────────────┘  └──────────────────┘
          │                     │                     │
          └─────────────────────┴─────────────────────┘
                                ▼
                    ┌─────────────────────────┐
                    │ PdfSplitService         │
                    │ (contrat PHP + legacy   │
                    │  PDFSplitterService)    │
                    └─────────────────────────┘
```

## Contrats PHP (lot 1 — implémentés)

| Interface | Fichier | Rôle |
|-----------|---------|------|
| `ClassifierInterface` | `app/Contracts/ClassifierInterface.php` | classify + syncTaxonomy |
| `PdfSplitInterface` | `app/Contracts/PdfSplitInterface.php` | detectPageGroups + split |
| `UnifiedClassifier` | `app/Services/Classifiers/UnifiedClassifier.php` | Façade cascade adapters |
| `HtmleditorTaxonomyAdapter` | `app/Adapters/HtmleditorTaxonomyAdapter.php` | Lit export JSON HTMLEDITOR |
| `PdfSplitService` | `app/Services/PdfSplit/PdfSplitService.php` | Délègue à `PDFSplitterService` |

## Modèles et providers

| Provider | Usage | Fallback |
|----------|-------|----------|
| Claude (Anthropic) | Classification JSON, analyse pages PDF | Ollama local |
| Ollama | `llama3.1:8b`, embeddings `nomic-embed-text` | Règles regex |
| Infomaniak AI Tools | ClearMyDocs `providers_infomaniak.py` (`llm_backend=infomaniak`) | Claude |
| Training local | Corrections utilisateur + embeddings | — |

Variables `.env` : voir `.env.example` (`ANTHROPIC_API_KEY`, `OLLAMA_URL`, `IA_*`).

## Sync taxonomie HTMLEDITOR → GED

| Source HTMLEDITOR | Cible GED | Mécanisme |
|-------------------|-----------|-----------|
| `_variables.json` | `classification_fields` | `HtmleditorTaxonomyAdapter` + endpoint sync |
| Sections / tags doc | `tags`, `document_types` | Export projet + webhook |
| Blocs `externalIds` | `custom_fields` | Event save HTMLEDITOR |

Chemin export : `HTMLEDITOR_TAXONOMY_PATH` (fichier JSON partagé ou copie).

## Split PDF multi-contenu

**Cas métier** : un scan de N pages contient N documents (factures empilées, bordereau).

| Couche | Implémentation | Statut |
|--------|----------------|--------|
| GED legacy | `PDFSplitterService` + Claude page-par-page | Existant |
| Façade | `PdfSplitService` | Stub lot 1 |
| ClearMyDocs | `segmenter.py` — heuristiques en-têtes + profils | À intégrer sidecar |

Pipeline cible (ingest) :

1. Upload / consume / index → `DocumentProcessor::process()` (OCR, matching, thumbnail)
2. Hook unique post-OCR : `IngestClassificationService::queue()` (`DocumentProcessor` §1.5)
3. Worker `classify_document` → `PdfSplitService::detectPageGroups()` si PDF
4. Split → N jobs enfants ; sinon `UnifiedClassifier::classifyDocument()`
5. Persistance `classification_suggestions` + `ai_additional_categories`

Providers : Claude (frontier) → Ollama (fallback `AIProviderService`) → Infomaniak AI Tools via sidecar ClearMyDocs (`providers_infomaniak.py`). Hébergement Infomaniak : voir `htmleditor/Release/ARCHITECTURE-INFOMANIAK.md` (kDrive + Cloud Server ; pas un produit « Flowy »).

## Lots suivants

| Lot | Contenu | Priorité |
|-----|---------|----------|
| IA-2 | Endpoint `POST /api/classification/sync-taxonomy` | **Fait** |
| IA-3 | Sidecar ClearMyDocs (HTTP) pour `/segment` | **Fait** |
| IA-8 | Sidecar `/extract` + `/ingest` (full ingest worker) | P1 |
| IA-9 | Mapper réponse ingest CMD → champs GED + taxonomie | P1 |
| IA-4 | Pont HTMLEDITOR `GET /api/projects/{id}/taxonomy-export` | P1 |
| IA-5 | Enregistrement plugin dans `PluginRegistry` | P2 |
| IA-6 | Tests unit `UnifiedClassifier`, `HtmleditorTaxonomyAdapter` | **Fait** |
| IA-7 | Brancher `UnifiedClassifier` sur ingest documentaire | **Fait** |

## Références

- `docs/AUDIT-IA-CLASSIFICATEUR.md`
- `docs/IA-CLEARMYDOCS-INTEGRATION.md`
- `docs/IA-CLEARMYDOCS-INGESTION.md`
- `htmleditor/Release/ARCHITECTURE-INFOMANIAK.md` (hébergement + kDrive)
- HTMLEDITOR : `htmleditor/src/server/variables/store.js` (`_variables.json`)
- ClearMyDocs : `F:\DATA\DEVELOPPEMENT\clearmydocs-v3`

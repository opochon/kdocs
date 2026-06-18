# GEDv1 — Roadmap IA (UnifiedClassifier + split PDF)

> Date : 2026-06-18 · Chantier GEDv1 lot IA fondations

## Objectif

Unifier la classification documentaire GEDv1 en s'appuyant sur :

- la **cascade GED existante** (training → Claude → Ollama → règles) ;
- la **taxonomie HTMLEDITOR** (`_variables.json`, sections, tags) ;
- les **patterns ClearMyDocs** (segmentation PDF, enrichissement, Infomaniak) ;
- **Infomaniak kDrive** (API déjà partielle dans GED) et modèles **frontier** (Claude API présente).

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
| Infomaniak AI | Via ClearMyDocs `providers_infomaniak.py` | Claude |
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

Pipeline cible :

1. Ingest PDF → OCR si besoin
2. `PdfSplitService::detectPageGroups()` — heuristiques + LLM
3. Split physique → N enregistrements `documents`
4. `UnifiedClassifier::classify()` sur chaque pièce

## Lots suivants

| Lot | Contenu | Priorité |
|-----|---------|----------|
| IA-2 | Endpoint `POST /api/classification/sync-taxonomy` | P1 |
| IA-3 | Sidecar ClearMyDocs (HTTP ou CLI) pour segment + enrich | P1 |
| IA-4 | Pont HTMLEDITOR `GET /api/projects/{id}/taxonomy-export` | P1 |
| IA-5 | Enregistrement plugin dans `PluginRegistry` | P2 |
| IA-6 | Tests unit `UnifiedClassifier`, `HtmleditorTaxonomyAdapter` | P2 |

## Références

- `docs/AUDIT-IA-CLASSIFICATEUR.md`
- `docs/IA-CLEARMYDOCS-INTEGRATION.md`
- HTMLEDITOR : `htmleditor/src/server/variables/store.js` (`_variables.json`)
- ClearMyDocs : `F:\DATA\DEVELOPPEMENT\clearmydocs-v3`

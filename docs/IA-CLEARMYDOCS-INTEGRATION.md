# GEDv1 — Intégration ClearMyDocs

> Date : 2026-06-18 · Analyse `F:\DATA\DEVELOPPEMENT\clearmydocs-v3`

## ClearMyDocs trouvé

| Chemin | Version | État |
|--------|---------|------|
| `F:\DATA\DEVELOPPEMENT\clearmydocs-v3` | v3 (Python) | **Actif** — pipeline core + API |
| `clearmydocsv2` | v2 legacy | Référence migration |
| `ClearMyDocs` | Dossier parallèle | Ancien |

## Synthèse (5 lignes)

1. **ClearMyDocs v3** est un moteur Python d'analyse documentaire : ingest → enrich → relations → synthesize → evaluate, avec ground truth en base (`facts`, `relations`).
2. **Segmentation PDF** : `src/clearmydocs/core/segmenter.py` détecte les ruptures multi-documents (en-têtes institutionnels, bordereaux, patterns par profil) — complément direct au `PDFSplitterService` GED (Claude page-par-page).
3. **Classification / enrichissement** : pipeline LLM avec providers Infomaniak (`providers_infomaniak.py`), Ollama, profils JSON (`builtin_profiles/`) — pas de taxonomie Stoco native.
4. **Réutilisable en GED** : patterns segmenter, schéma profils, evaluateur — via **sidecar HTTP/CLI** ; pas de port PHP direct du core Python.
5. **À réimplémenter en PHP** : orchestration légère (contrats `ClassifierInterface`, `PdfSplitInterface`) ; appels lourds LLM/enrichissement restent Python ou API frontier.

## Pipeline ClearMyDocs v3

```
ingest → enrich → relations → synthesize → evaluate → (loop)
```

Fichiers clés :

| Module | Fichier | Intérêt GED |
|--------|---------|-------------|
| Pipeline | `src/clearmydocs/core/pipeline.py` | Orchestration étapes |
| Segmenter | `src/clearmydocs/core/segmenter.py` | Split PDF multi-sujets |
| Indexer | `src/clearmydocs/core/indexer.py` | Indexation texte |
| Infomaniak | `src/clearmydocs/core/providers_infomaniak.py` | Provider cloud CH |
| Ground truth | `src/clearmydocs/core/ground_truth.py` | Faits vérifiables |
| API | `src/clearmydocs/api/server.py` | Point d'entrée sidecar |

## Mapping ClearMyDocs → GEDv1

| Capacité ClearMyDocs | Équivalent GED actuel | Stratégie |
|----------------------|----------------------|-----------|
| Segmentation PDF | `PDFSplitterService` | Façade `PdfSplitService` + appel sidecar segmenter |
| Enrichissement LLM | `AIClassifierService`, `FieldAIClassifierService` | Adapter Python ou Claude direct |
| Profils domaine | `document_types`, `classification_fields` | Import profils JSON → BDD GED |
| Relations transverses | — (absent) | Lot futur — pas P0 |
| Synthèse narrative | `ChatApiController` | Réutiliser côté chat, pas classification |
| Evaluateur | `classification_audit_logs` | Inspiration checks, pas port direct |
| Infomaniak AI | kDrive partiel GED | Unifier config `.env` |

## Sidecar GED (implémenté — lot IA-3)

```
GEDv1 (PHP)                          ClearMyDocs (Python :5101)
     │                                      │
     │  POST /segment  {pdf_path, profile}  │
     ├─────────────────────────────────────►│ ged_sidecar.py → segmenter.py
     │◄─────────────────────────────────────┤ {page_groups[]}
```

### Lancer le sidecar

```bash
cd F:\DATA\DEVELOPPEMENT\clearmydocs-v3
pip install -e ".[dev]"
python -m clearmydocs.api.ged_sidecar
# ou : uvicorn clearmydocs.api.ged_sidecar:app --host 127.0.0.1 --port 5101
```

### Tester

```cmd
curl -s http://127.0.0.1:5101/health
curl -s -X POST http://127.0.0.1:5101/segment -H "Content-Type: application/json" -d "{\"pdf_path\":\"F:\\\\chemin\\\\scan.pdf\",\"options\":{\"use_llm_confirm\":false}}"
```

Indices de pages **0-based**. Client PHP : `app/Services/ClearMyDocsSidecarClient.php`.

Configuration GED `.env` :

```env
CLEARMYDOCS_PATH=F:\DATA\DEVELOPPEMENT\clearmydocs-v3
CLEARMYDOCS_SIDECAR_URL=http://127.0.0.1:5101
CLEARMYDOCS_ENABLED=false
IA_PDF_SPLIT_ENABLED=true
```

## Ce qu'on ne porte pas en PHP

- Boucle evaluateur complète (ground truth + llm_judge)
- Relations agent transverses
- Bench / harness Python (`scripts/run_all.py`, `harness_e2e.py`)

## Installation ClearMyDocs (référence)

```bash
cd F:\DATA\DEVELOPPEMENT\clearmydocs-v3
pip install -e ".[dev]"
python scripts/run_all.py ci
```

Config utilisateur Windows : `%APPDATA%\ClearMyDocs\config.json`.

## Prochaine étape recommandée

1. ~~Démarrer sidecar minimal (`ged_sidecar.py`) avec endpoint `/segment`~~ — **fait** (lot IA-3).
2. ~~Brancher `PdfSplitService::detectPageGroups()`~~ — **fait** si `CLEARMYDOCS_ENABLED=true`.
3. Tester sur corpus GED (PDF multi-factures) vs `PDFSplitterService` seul.
4. Lot suivant : brancher `UnifiedClassifier` sur ingest + enrich sidecar.

## Références

- `docs/IA-ROADMAP.md`
- `docs/AUDIT-IA-CLASSIFICATEUR.md`
- ClearMyDocs README : `clearmydocs-v3/README.md`

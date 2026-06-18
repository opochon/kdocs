# Ingest dual-mode — GED K-Docs + ClearMyDocs v3

GED reste **orchestrateur** (MySQL, workflows, taxonomie HTMLEDITOR). ClearMyDocs v3 fournit le moteur lourd via sidecar HTTP **stateless** (pas d'écriture PostgreSQL CMD).

## Modes

| `INGEST_ENGINE` | Comportement |
|-----------------|--------------|
| `auto` (défaut) | Sidecar CMD v3 joignable + version ≥ `CLEARMYDOCS_MIN_VERSION` → couplé ; sinon natif |
| `coupled` | Exige CMD ; fallback natif si indisponible avec flag `coupled_unavailable` |
| `native` | Jamais d'appel sidecar (tests, hébergement sans Python) |

## Variables `.env`

```env
CLEARMYDOCS_PATH=F:\DATA\DEVELOPPEMENT\clearmydocs-v3
CLEARMYDOCS_ENABLED=true
CLEARMYDOCS_SIDECAR_URL=http://127.0.0.1:5101
CLEARMYDOCS_MIN_VERSION=3.0.0
INGEST_ENGINE=auto
HTMLEDITOR_TAXONOMY_PATH=...\sources\_variables.json
```

## Démarrer le sidecar

```cmd
cd F:\DATA\DEVELOPPEMENT\GEDv1
tools\start-cmd-sidecar.bat
```

Ou manuellement :

```cmd
cd F:\DATA\DEVELOPPEMENT\clearmydocs-v3
pip install -e ".[dev]"
python -m clearmydocs.api.ged_sidecar
```

Vérifier : `GET http://127.0.0.1:5101/health` → `version: 3.0.0`, `capabilities: [segment, extract, analyze, ingest]`.

## Matrice capacités

| Capacité | Mode couplé (CMD) | Mode natif (GED) |
|----------|-------------------|------------------|
| Extraction texte | `POST /extract` ou `/ingest` | `OCRService` (Tesseract, pdftotext, PhpWord…) |
| Split PDF multi-doc | `POST /segment` dans `/ingest` | `PdfSplitService` + sidecar si activé |
| Classification | `POST /analyze` — skip UnifiedClassifier si confiance ≥ `IA_UNIFIED_MIN_CONFIDENCE` | `UnifiedClassifier` + taxonomie HTMLEDITOR |
| Recherche sémantique | *(lot 2)* proxy CMD | `AISearchService` / embeddings MySQL |

## Point d'accroche PHP

`DocumentProcessor::process()` → `IngestEngineRouter::process()` :

- `ClearMyDocsIngestEngine` : `POST /ingest` + `CmdResultMapper`
- `GedNativeIngestEngine` : OCR + `IngestClassificationService::queue()`

Admin : page **Diagnostic** affiche le statut CMD (version, URL, moteur actif).

## Tests

```cmd
cd F:\DATA\DEVELOPPEMENT\GEDv1
php tests\migration_smoke_test.php
vendor\bin\phpunit tests\Unit\Services\Ingest
```

Scénarios manuels :

1. `INGEST_ENGINE=native` — upload PDF, pas d'appel port 5101
2. Sidecar up + `auto` — ingest couplé, classification CMD si confiante
3. Sidecar down + `auto` — fallback natif sans erreur 500

## Fichiers clés

| Fichier | Rôle |
|---------|------|
| `app/Services/Ingest/IngestEngineRouter.php` | Sélection moteur |
| `app/Services/Ingest/ClearMyDocsCapabilityProbe.php` | Health + version |
| `clearmydocs-v3/.../ged_sidecar.py` | Endpoints sidecar |
| `docs/IA-CLEARMYDOCS-INTEGRATION.md` | Architecture détaillée |

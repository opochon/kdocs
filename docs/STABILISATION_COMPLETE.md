# K-Docs - Stabilisation Complete

## Date: 2026-01-31

## Changements effectues

### Infrastructure
- Desactivation de Qdrant (Docker non requis)
- Desactivation des embeddings par defaut
- MySQL FULLTEXT comme index de recherche disponible

### Configuration
- `config.php`: embeddings.enabled = false
- `config.php`: qdrant.enabled = false
- Recherche fonctionne sans Docker

### Recherche
- Index FULLTEXT sur documents (title, ocr_text, content)
- AdvancedSearchParser avec support AND/OR/NOT/"phrase"/wildcards
- Scoring de pertinence via LIKE (FULLTEXT disponible pour optimisation)

### Indexation
- Detection des deltas (checksum)
- Extraction texte via LibreOffice (si installe)
- Miniatures via Ghostscript + GD

### IA
- Service unifie avec fallbacks (Claude > Ollama > Regles)
- Limitation automatique des tokens envoyes
- Regles metier toujours disponibles

### Tests
- Smoke test complet (35 checks)
- Validation de toutes les dependances
- Tests optionnels pour Docker (OnlyOffice, Qdrant)

## Dependances

### Obligatoires
- PHP >= 8.1
- MySQL/MariaDB >= 5.7
- Tesseract OCR + langue francaise
- Ghostscript

### Recommandees
- LibreOffice (extraction texte Office)

### Optionnelles
- OnlyOffice (Docker) - edition collaborative
- Ollama - IA locale
- Claude API - IA cloud

## Resultats Smoke Test

```
31/35 OK, 2 warnings, 2 FAILED

Warnings:
- LibreOffice: non installe (extraction Office limitee)
- EmbeddingService: desactive (normal)

Failed (Docker non demarre):
- OnlyOffice: non accessible
- Qdrant: non accessible (desactive)
```

## Prochaines etapes

1. Installer LibreOffice si extraction Office necessaire
2. Monitoring des performances
3. Module factures/ERP

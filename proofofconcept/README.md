# K-DOCS POC - Pipeline d'indexation

## Vue d'ensemble

Ce POC valide le pipeline complet d'indexation **AVANT** intégration dans la GED.

```
Fichier → Extraction → Embedding → Classification → Miniature → DB
```

## Les 3 Flux

```
┌─────────────────────────────────────────────────────────────────────┐
│                        K-DOCS - 3 FLUX                              │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  1. CONSUME (bulk scan avec split)                                  │
│  ═══════════════════════════════════                                │
│                                                                     │
│  Scanner → PDF multi-pages → Analyse page/page → Split → Index      │
│                                                                     │
│  ┌──────┐    ┌─────────┐    ┌──────────┐    ┌───────┐    ┌────┐   │
│  │Scan  │───►│Extraire │───►│Analyser  │───►│Split? │───►│Index│   │
│  │bulk  │    │pages    │    │chaque pg │    │Y/N    │    │docs │   │
│  └──────┘    └─────────┘    └──────────┘    └───────┘    └────┘   │
│                                                                     │
│  2. DÉTECTION (indexation auto)                                     │
│  ══════════════════════════════                                     │
│                                                                     │
│  Surveille storage/documents → Détecte nouveau/modifié → Index auto │
│                                                                     │
│  ┌──────┐    ┌─────────┐    ┌──────────┐    ┌───────┐              │
│  │Watch │───►│Comparer │───►│Extraire  │───►│Index  │              │
│  │dossier│   │hash     │    │+ embed   │    │auto   │              │
│  └──────┘    └─────────┘    └──────────┘    └───────┘              │
│                                                                     │
│  3. DROP UI (selon préférence user)                                 │
│  ══════════════════════════════════                                 │
│                                                                     │
│  Upload UI → Proposer classement → Confirmer → Index                │
│                                                                     │
│  ┌──────┐    ┌─────────┐    ┌──────────┐    ┌───────┐              │
│  │Upload│───►│Analyser │───►│Proposer  │───►│User   │──► Index    │
│  │fichier│   │contenu  │    │type/tags │    │valide │              │
│  └──────┘    └─────────┘    └──────────┘    └───────┘              │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Structure

```
proofofconcept/
├── config.php              # Config isolée (même DB, outils)
├── helpers.php             # Fonctions utilitaires
├── 01_detect_changes.php   # Détection delta (nouveau/modifié/supprimé)
├── 02_ocr_extract.php      # Extraction texte (LibreOffice/Tesseract)
├── 03_semantic_embed.php   # Embedding Ollama → MySQL BLOB
├── 04_suggest_classify.php # Suggestions (type, correspondant, tags)
├── 05_thumbnail.php        # Miniatures (LibreOffice → GS → JPG)
├── 06_consume_flow.php     # FLUX CONSUME (bulk scan + split)
├── 07_detect_flow.php      # FLUX DÉTECTION (indexation auto)
├── pipeline_full.php       # Chaîne complète simple
├── test_all.php            # TEST COMPLET de tout le pipeline
├── samples/                # Fichiers de test
└── output/                 # Résultats (non versionnés)
```

## Exécution

### Test complet (RECOMMANDÉ)

```bash
cd C:\wamp64\www\kdocs\proofofconcept

# 1. Place tes documents de test dans samples/
#    - PDF multi-pages (plusieurs factures scannées)
#    - PDF texte natif
#    - PDF image/scanné
#    - DOCX, DOC

# 2. Lance le test complet
php test_all.php
```

### Tests par flux

```bash
cd C:\wamp64\www\kdocs\proofofconcept

# FLUX CONSUME - PDF multi-pages avec split auto
php 06_consume_flow.php samples/scan_multi.pdf

# FLUX DÉTECTION - Scan et indexation auto
php 07_detect_flow.php
php 07_detect_flow.php --full          # Réindexation complète
php 07_detect_flow.php --compare-db    # Comparer avec DB

# Pipeline simple (1 fichier)
php pipeline_full.php samples/test.pdf
```

### Tests individuels

```bash
# 1. Détection changements
php 01_detect_changes.php

# 2. Extraction texte
php 02_ocr_extract.php samples/test.pdf

# 3. Embedding
php 03_semantic_embed.php "texte à vectoriser"

# 4. Classification
php 04_suggest_classify.php samples/test.pdf

# 5. Miniature
php 05_thumbnail.php samples/test.docx
```

### Vérification outils

```bash
php -r "require 'helpers.php'; print_r(poc_config()['tools']);"
```

## Config

Le POC utilise `config.php` isolé:
- **dry_run = true** : ne modifie PAS la DB GED
- Pointe vers mêmes chemins/outils que la GED
- Output dans `proofofconcept/output/`

Pour tester avec écriture DB:
```php
// config.php
'poc' => [
    'dry_run' => false,  // ATTENTION: écrit dans la DB
],
```

## Pipeline détaillé

### 01 - Détection changements

| Entrée | Sortie |
|--------|--------|
| Dossier à scanner | Liste {nouveau, modifié, supprimé} |

Compare:
- Fichiers filesystem vs `.index` précédent
- Hash MD5 pour détecter modifications
- Compare aussi avec DB (documents.content_hash)

### 02 - Extraction OCR

| Type | Méthode |
|------|---------|
| PDF texte | pdftotext (direct) |
| PDF scanné | Ghostscript → Tesseract |
| Image | Tesseract direct |
| DOCX | Extraction XML native |

Sortie: `{text, method, confidence, lang}`

### 03 - Embedding sémantique

| Entrée | Sortie |
|--------|--------|
| Texte | Vecteur float[768] |

- Ollama + nomic-embed-text
- Stockage MySQL BLOB (`pack('f*', ...)`)
- Similarité cosinus pour recherche

### 04 - Suggestion classement

| Méthode | Description |
|---------|-------------|
| Règles | Patterns, mots-clés |
| Sémantique | Similarité avec docs existants |
| Hybride | Règles prioritaires si confiance > 0.6 |

Sortie:
```json
{
  "document_type": {"id": 1, "label": "Facture", "confidence": 0.85},
  "correspondent": {"id": 5, "name": "Swisscom", "confidence": 0.72},
  "tags": [{"id": 3, "name": "2024", "confidence": 0.8}],
  "date_detected": "2024-01-15",
  "amount_detected": 125.50
}
```

### 05 - Miniature

| Type | Chaîne |
|------|--------|
| PDF | Ghostscript → JPG |
| DOCX/Office | LibreOffice → PDF → Ghostscript → JPG |
| Image | GD resize → JPG |
| Autre | Placeholder stylisé |

## Intégration GED

Une fois POC validé:

### Fichiers à cherry-pick

| POC | GED cible |
|-----|-----------|
| `02_ocr_extract.php` | `app/Services/OCRService.php` |
| `03_semantic_embed.php` | `app/Services/EmbeddingService.php` |
| `04_suggest_classify.php` | `app/Services/ClassificationService.php` |
| `05_thumbnail.php` | `app/Services/ThumbnailGenerator.php` |

### Checklist intégration

- [ ] Backup fichiers GED avant modification
- [ ] Copier fonctions validées
- [ ] Adapter namespaces/imports
- [ ] Tester avec smoke_test.php
- [ ] Git commit atomique par composant

## Side effects identifiés

| Composant | Effet | Mitigation |
|-----------|-------|------------|
| OCR | Fichiers temp | Nettoyage systématique |
| Embedding | Appel HTTP Ollama | Timeout 30s |
| Thumbnail | LibreOffice process | Unique instance |

## Résultats attendus

Chaque script affiche:
- ✓ Vert = OK
- ✗ Rouge = Échec

Les rapports JSON sont dans `output/`:
- `01_changes_report.json`
- `02_ocr_result.json`
- `03_embedding_result.json`
- `04_classification_result.json`
- `05_thumbnail_result.json`
- `pipeline_result.json`

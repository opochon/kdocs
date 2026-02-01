# K-DOCS — PILOTAGE

> **Claude : lis ce fichier en entier avant de faire quoi que ce soit.**

---

## LA LOI

```
1. Les fichiers utilisateur ne sont JAMAIS déplacés sans demande explicite
2. UTF-8 partout, requêtes SQL préparées, pas de credentials dans le code
3. PHP 8.x, MySQL, pas de framework lourd
4. Mode 100% local toujours disponible (Ollama, Tesseract)
5. Controllers = HTTP, Services = logique, Repositories = données
```

---

## OÙ ON EN EST

**POC validé à 100%** — `proofofconcept/`

| Composant | Status | Fichier |
|-----------|--------|---------|
| Extraction PDF natif | ✅ | 02_ocr_extract.php |
| Extraction PDF scanné (OCR) | ✅ | 02_ocr_extract.php |
| Extraction DOCX/XLSX/PPTX | ✅ | 02_ocr_extract.php |
| Extraction MSG Outlook | ✅ | 02_ocr_extract.php |
| Miniatures | ✅ | 05_thumbnail.php |
| Split PDF multi-docs | ✅ | 06_consume_flow.php |
| Classification CASCADE | ✅ | 04_suggest_classify.php |
| Classification Anthropic | ⚠️ SSL Windows | 04_suggest_classify.php |
| Classification Ollama | ✅ llama3.1:8b | 04_suggest_classify.php |
| Classification Règles | ✅ | 04_suggest_classify.php |
| Extraction champs | ✅ | 04_suggest_classify.php |
| Embeddings Ollama | ✅ | helpers.php |
| Training/Apprentissage | ✅ | 08_training.php |
| Recherche FULLTEXT | ✅ | 04_search.php |
| Recherche sémantique | ✅ | 04_search.php |
| Flux consume | ✅ | 06_consume_flow.php |
| Flux détection | ✅ | 07_detect_flow.php |

**Cascade classification:** Anthropic → Ollama → Règles (fallback automatique)

**Prochain :** Intégration POC → GED, interface validation UI

---

## LES 3 FLUX

```
CONSUME (scanner)
storage/consume/ → Extraction → Analyse page/page → Split → Validation UI → Classement
Status: pending → validated → indexed

FILESYSTEM (dépôt direct)  
storage/documents/ → Détection hash → Extraction → Indexation auto
Status: indexed (pas de validation)

DROP UI (interface web)
Upload HTTP → Selon préférence user → CONSUME ou FILESYSTEM
```

---

## STRUCTURE

```
kdocs/
├── app/                    # GED (à enrichir avec le POC)
├── proofofconcept/         # POC validé
│   ├── 01_detect_changes.php
│   ├── 02_ocr_extract.php
│   ├── 03_semantic_embed.php
│   ├── 04_suggest_classify.php
│   ├── 05_thumbnail.php
│   ├── 06_consume_flow.php
│   ├── 07_detect_flow.php
│   ├── test_all.php
│   ├── config.php
│   └── helpers.php
├── storage/
│   ├── documents/          # Fichiers indexés
│   ├── consume/            # Dossier scanner
│   └── thumbnails/
└── docs/pilotage/          # Ce dossier
```

---

## OUTILS CONFIGURÉS

| Outil | Chemin |
|-------|--------|
| Tesseract | C:/Program Files/Tesseract-OCR/tesseract.exe |
| Ghostscript | C:/Program Files/gs/gs10.03.0/bin/gswin64c.exe |
| LibreOffice | C:/Program Files/LibreOffice/program/soffice.exe |
| Ollama | localhost:11434 (nomic-embed-text) |
| MySQL | localhost:3307 / kdocs |

---

## TESTS DE RÉGRESSION

Avant de valider une modification :

```bash
cd C:\wamp64\www\kdocs\proofofconcept
php test_all.php
```

**Critères de validation :**
- Taux ≥ 85%
- Pas de régression sur ce qui marchait

---

## HISTORIQUE RÉCENT

### 2026-02-01 (soir - MERGE)
- **MERGE POC → K-DOCS réussi**
- AIHelper.php: parseJsonResponse, ensureUtf8, cosineSimilarity
- TrainingService.php: corrections storage, learned rules, similarity matching
- AIProviderService: cascade Training → Claude → Ollama → Rules
- PDFSplitterService: page indicators (Page 1/2), date extraction, heuristics
- Config: ai.training, ollama sections
- Default model: llama3.1:8b (tested)

### 2026-02-01 (après-midi)
- **POC validé à 100%** (59/59 tests)
- Classification CASCADE : Anthropic → Ollama (llama3.1:8b) → Règles
- Extraction champs via IA : montant, date, IBAN, référence, correspondant
- Training : stockage corrections + apprentissage patterns
- Recherche FULLTEXT + sémantique
- Fix UTF-8, rapport HTML avec miniatures

### 2026-02-01 (matin)
- POC créé et validé (91%)
- 3 flux implémentés
- Split PDF intelligent (12 pages → 2 documents)

---

## SI TU MODIFIES QUELQUE CHOSE

1. Teste avec `php test_all.php`
2. Mets à jour la section "OÙ ON EN EST" ci-dessus
3. Ajoute une ligne dans "HISTORIQUE RÉCENT"

---

*Dernière mise à jour : 2026-02-01 14:30*

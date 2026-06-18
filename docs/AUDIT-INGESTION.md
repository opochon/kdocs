# GEDv1 (K-Docs) — Audit pipeline ingestion

> Date : 2026-06-18 · Périmètre : upload, consume folder, OCR, indexation, queue workers  
> Références : `docs/architecture/CONSUME_FOLDER_FLOW.md`, `docs/architecture/QUEUE_SYSTEM.md`, `docs/DELTA-REDX.md`

---

## Synthèse

| Dimension | Score (1–10) | Commentaire |
|-----------|--------------|-------------|
| Complétude pipeline | **6** | Upload + consume + OCR + classification + embeddings prévus |
| Fiabilité production | **4** | Traitement sync fin requête HTTP ; workers optionnels |
| Performance volume | **4** | Pauses indexation, troncature TEXT 65 Ko, pas de benchmarks CI |
| Formats supportés | **5** | Liste config restrictive vs consume interne plus large |
| Observabilité | **5** | Logs Monolog + `.indexing_progress.json` ; pas de métriques |

**Score ingestion global : 5 / 10** — architecture documentée et riche, exécution **hybride sync/async** non industrialisée.

---

## Pipeline ingestion — vue d'ensemble

```
                    ┌─────────────────────────────────────┐
                    │  Sources                             │
                    │  · Upload UI / API                   │
                    │  · storage/consume/ (watch)          │
                    │  · Email IMAP (EmailIngestionService)│
                    │  · Import MSG (MSGImportService)     │
                    └──────────────┬──────────────────────┘
                                   ↓
                    ┌──────────────────────────────────────┐
                    │  Enregistrement BDD (documents)       │
                    │  status: pending | validated         │
                    │  file_path → storage/documents/…     │
                    └──────────────┬───────────────────────┘
                                   ↓
         ┌─────────────────────────┼─────────────────────────┐
         ↓                         ↓                         ↓
  DocumentProcessor          QueueService              FolderIndexer
  (fin requête index.php)    (job_queue_jobs SQL)      (filesystem .index)
         ↓                         ↓                         ↓
  OCR → Metadata →           queue_worker.php          CrawlerAutoTrigger
  Thumbnail → Matching →     OCR / thumb / index       indexation incrémentale
  Classification cascade
         ↓
  Embeddings (Ollama) → MySQL fulltext + Qdrant (opt.)
         ↓
  Workflows / Webhooks / Validation
```

---

## Étapes détaillées

### 1. Upload manuel

| Étape | Composant | Détail |
|-------|-----------|--------|
| Entrée web | `DocumentsController::upload` | Formulaire `/documents/upload` |
| Entrée API | `DocumentsApiController::create` | JSON multipart |
| Stockage | `storage/documents/{yyyy}/{mm}/` | Filesystem-first |
| Extensions | `config.storage.allowed_extensions` | **pdf, png, jpg, jpeg, tiff, doc, docx** (8 types) |
| Post-traitement | `DocumentProcessor::processPendingDocuments()` | Appelé **en fin de `index.php`** |

**Risque P0** : traitement lourd (OCR Tesseract, LibreOffice) dans le cycle HTTP → timeouts sur PDF volumineux ou batch upload.

### 2. Consume folder

Documenté dans `docs/architecture/CONSUME_FOLDER_FLOW.md`.

| Phase | Comportement |
|-------|--------------|
| Dépôt | Fichiers dans `storage/consume/` |
| Scan | Auto sur `/admin/consume` si fichiers + aucun `pending` ; manuel POST `/admin/consume/scan` |
| Lock | `storage/.consume_scan.lock` — anti concurrence |
| Doublon | MD5 checksum → `processed/` si déjà validé |
| Import | Document BDD `status=pending`, fichier **reste dans consume/** jusqu'à validation |
| Traitement | OCR + classification + split PDF IA si activé |
| Validation | Utilisateur `/admin/consume` → déplacement vers chemin final + `processed/` |

**Service** : `ConsumeFolderService` — extensions scan internes plus larges que config upload : `pdf, png, jpg, jpeg, tiff, tif, gif, webp`.

**Incohérence** : doc dit « plus de toclassify » mais UI documents montre encore dossier `toclassify` dans l'arborescence.

### 3. OCR et extraction texte

| Outil | Rôle | Config |
|-------|------|--------|
| `pdftotext` | PDF natif | `config.tools.pdftotext` |
| Tesseract | Images, PDF scannés | `config.ocr.tesseract_path` |
| LibreOffice | DOCX/XLSX → PDF/miniature | `config.tools.libreoffice` |
| Ghostscript | Rendu PDF | `config.tools.ghostscript` |

**Service** : `OCRService` → appelé par `DocumentProcessor`.

**Limites identifiées** :

- Contenu tronqué à **65 000 caractères** (`TEXT` MySQL) — perte sur gros PDF.
- Erreurs OCR stockées dans `ocr_text` (« OCR échoué… ») — pollue l'index si non filtré.
- Pas de pipeline OCR async garanti si worker non démarré.

### 4. Classification (post-ingestion)

Cascade configurable (`config.ai.cascade`) :

```
training → claude → ollama → rules
```

- `TrainingService` : corrections utilisateur + embeddings dans `storage/training.json`.
- `AIClassifierService` : prompt JSON structuré (correspondant, type, tags, montant, synthèse).
- `AutoClassifierService` : regex dates/montants + règles attribution.
- **Suggestions ≠ application auto** (oracle) — sauf `classification.auto_apply` si confiance > seuil.

### 5. Indexation et recherche

| Couche | Technologie | État |
|--------|-------------|------|
| Fulltext MySQL | Colonnes `content`, métadonnées | Actif |
| Embeddings | Ollama `nomic-embed-text` (768d) | `embeddings.enabled=true`, `auto_sync=true` |
| Vector store | Qdrant port 6333 | **`qdrant.enabled=false`** — timeout visible en settings |
| Filesystem | `.index` incrémental, `FolderIndexerService` | Actif |
| Queue SQL | `n0nag0n/simple-job-queue` → `job_queue_jobs` | Documenté ; worker `app/workers/queue_worker.php` |

**Paramètres indexation** (`config.indexing`) :

- `max_concurrent_queues: 2`, `memory_limit: 128 MB`, pauses 50–500 ms, `batch_size: 20`.
- Mode turbo désactivé par défaut.

### 6. Workers CLI

| Worker | Fichier | Rôle |
|--------|---------|------|
| Queue | `app/workers/queue_worker.php` | OCR, thumbnail, index jobs |
| Indexation | workers indexation | Crawl dossiers |
| Start | `app/workers/start_worker.bat` | Windows dev |

**Gap production** : pas de supervisor/cron documenté comme obligatoire ; traitement fallback sync HTTP.

---

## Formats supportés

| Canal | Extensions |
|-------|------------|
| Config upload (`allowed_extensions`) | pdf, png, jpg, jpeg, tiff, doc, docx |
| Consume scan interne | + tif, gif, webp |
| Absents vs REDX/Paperless typique | xlsx, pptx, msg (MSGImportService existe côté code), eml, csv |

**MSG / Email** : services présents (`MSGImportService`, `EmailIngestionService`) — flux à valider en conditions réelles.

---

## Goulots d'étranglement

| Goulot | Impact | Sévérité |
|--------|--------|----------|
| **DocumentProcessor sync HTTP** | Timeout, blocage UI upload | P0 |
| **Tesseract séquentiel** | Latence OCR images/PDF scannés | P1 |
| **LibreOffice conversion** | Process lourd, mono-instance | P1 |
| **Troncature 65 Ko** | Recherche incomplète gros docs | P1 |
| **Qdrant off** | Recherche sémantique dégradée | P2 |
| **BDD MariaDB locale** | Index fulltext OK ; pas de sharding | P2 |
| **Lock consume** | Fichier lock orphelin bloque scan | P1 |
| **58 fichiers pending validation** | Backlog visible — pipeline classification ne vide pas la file | P1 |

---

## Comparaison attentes REDX / M-Files

Source : `docs/DELTA-REDX.md` — parité fonctionnelle **~52 %**.

| Attendu REDX / M-Files | K-Docs | Gap |
|------------------------|--------|-----|
| Ingestion fiable haute volumétrie | Hybrid sync/async | Industrialisation workers |
| OCR + index immédiat searchable | OK si OCR réussit | Échecs silencieux en `ocr_text` |
| Classification métier fiduciaire | Cascade IA + règles | Pas aligné référentiels HTMLEDITOR/WinBiz |
| Rapprochement ERP | MatchingService MVP | WinBiz bridge incomplet |
| Archivage légal CH (WORM, rétention) | Absent | GAP-020 à GAP-024 |
| Audit trail ingestion | `audit_logs` partiel | Piste ingestion bout-en-bout à compléter |

---

## Tests mesurables recommandés

### Benchmarks à implémenter (CI ou script `tools/bench-ingestion.php`)

| Test | Métrique cible | Méthode |
|------|----------------|---------|
| Upload PDF 1 Mo natif | < 3 s jusqu'à searchable | `curl` API + poll `/api/documents/{id}` |
| Upload PDF 20 Mo scanné | OCR < 60 s (worker) | Worker actif, mesure `content` rempli |
| Consume batch 50 fichiers | Scan + import < 5 min | Dépôt + `/api/consume/scan` |
| Recherche fulltext post-OCR | < 500 ms | `POST /api/search` |
| Embedding génération | < 10 s/doc | `/api/embeddings/status` |
| Recherche sémantique | < 1 s | `/api/semantic-search` (Qdrant on) |

### État actuel

- **Aucun benchmark automatisé** dans le dépôt.
- Smoke test vérifie HTTP 200 pages, pas latence ingestion.
- Logs : `storage/logs/indexing_2026-06-18.log` — à parser pour baseline manuelle.

### Protocole manuel rapide (dev)

```powershell
# 1. Health
curl http://127.0.0.1:8765/kdocs/health

# 2. Upload API (session cookie)
# Mesurer temps jusqu'à content non vide via GET /api/documents/{id}

# 3. Indexation status
curl http://127.0.0.1:8765/kdocs/admin/indexing/status
```

---

## Recommandations fiabilisation pro

### P0 — Fiabilité (S–M)

1. **Désactiver traitement sync** dans `index.php` — tout passer par `QueueService` + worker toujours actif (supervisor Windows/Linux).
2. **Idempotence jobs** : clé document_id + job_type pour éviter double OCR.
3. **Dead letter queue** : table ou fichier jobs échoués après N tentatives.
4. **Health check ingestion** : étendre `/health` avec statut worker, queue depth, Tesseract/LibreOffice.

### P1 — Performance (M)

5. **Augmenter colonne content** : `MEDIUMTEXT` ou table `document_contents` séparée — supprimer troncature 65 Ko.
6. **Paralléliser OCR** : pool workers configurable (`indexing.max_concurrent_queues`).
7. **Activer Qdrant** en prod ou retirer UI timeout — recherche sémantique cohérente.
8. **Harmoniser extensions** upload vs consume vs doc utilisateur.
9. **Métriques** : temps moyen upload→indexed par type MIME (Prometheus ou log structuré).

### P2 — Conformité (L)

10. **Piste audit ingestion** : qui, quand, source, checksum, étapes pipeline.
11. **Politiques rétention** (REDX GAP-021).
12. **Tests charge** : 1000 PDF/jour simulés.

---

## État données dev observé (2026-06-18)

| Indicateur | Valeur | Interprétation |
|------------|--------|----------------|
| Documents totaux dashboard | 43 | — |
| Sidebar Documents | 13 | Filtre exclut pending — **compteurs divergents** |
| Fichiers à valider | 58 | Backlog consume/validation |
| Badge « À classer » | Quasi systématique | Classification auto non appliquée ou seuil non atteint |
| Qdrant settings | Connection timeout | Service non démarré |

---

*Références : `app/Services/DocumentProcessor.php`, `ConsumeFolderService.php`, `QueueService.php`, `IndexingService.php`, `docs/architecture/REFONTE_INDEXATION.md`*

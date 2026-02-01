# Side Effects - POC K-Docs

## Effets de bord identifiés pendant le développement POC

Ce fichier est mis à jour au fur et à mesure des découvertes.

---

## 01_detect_changes.php

| Effet | Impact | Mitigation |
|-------|--------|------------|
| Scan récursif | CPU si gros volume | Limiter profondeur ou batch |
| Hash MD5 | I/O disque | Cache ou hash partiel pour gros fichiers |
| Fichier .index | Persistence état | Versionner ou sauvegarder |

---

## 02_ocr_extract.php

| Effet | Impact | Mitigation |
|-------|--------|------------|
| Fichiers temp | Espace disque | Nettoyage systématique |
| Process Ghostscript | CPU | Limiter parallélisme |
| Process Tesseract | CPU + RAM | Un fichier à la fois |
| PDF multi-pages | Lent | OCR première page seulement pour preview |

---

## 03_semantic_embed.php

| Effet | Impact | Mitigation |
|-------|--------|------------|
| Appel HTTP Ollama | Latence réseau | Timeout 30s, retry |
| Texte tronqué | Perte info | Chunking intelligent |
| Vecteur 768 floats | ~3KB/doc en DB | OK jusqu'à 100k docs |

---

## 04_suggest_classify.php

| Effet | Impact | Mitigation |
|-------|--------|------------|
| Chargement tous embeddings | RAM si gros volume | Pagination ou index |
| Similarité O(n) | Lent si >10k docs | Index vectoriel si besoin |
| Faux positifs classification | UX | Seuil confiance 0.7 |

---

## 05_thumbnail.php

| Effet | Impact | Mitigation |
|-------|--------|------------|
| Process LibreOffice | Lent, ~5s/fichier | Batch, queue async |
| LibreOffice lock | Une instance max | Mutex ou queue |
| PDF temporaire | Espace disque | Nettoyage immédiat |
| Placeholder bleu | UX dégradée | Placeholder stylisé |

---

## Général

| Effet | Impact | Mitigation |
|-------|--------|------------|
| Dry run oublié | Modification DB prod | Default true |
| Config hardcodée | Portabilité | Utiliser config.php |
| Logs verbeux | Espace disque | Rotation ou niveau |

---

## À surveiller lors de l'intégration

1. **Concurrence** : Deux indexations simultanées
2. **Mémoire** : Gros fichiers PDF
3. **Timeout** : Ollama lent au premier appel
4. **Permissions** : LibreOffice user différent de PHP
5. **Encodage** : UTF-8 partout (OCR, DB, JSON)

---

## Notes de debug

```bash
# Vérifier process LibreOffice zombie
tasklist | findstr soffice

# Vérifier espace temp
dir %TEMP%\kdocs_*

# Logs PHP
tail -f C:\wamp64\logs\php_error.log
```

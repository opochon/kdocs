# Checklist de Stabilisation K-Docs

## Pré-requis vérifiés
- [ ] PHP 8.x installé
- [ ] MySQL/MariaDB accessible
- [ ] Composer dependencies installées
- [ ] Config.php valide

## Phase 1: Recherche FULLTEXT
- [ ] Migration 028_fulltext_search.sql créée
- [ ] Migration exécutée sans erreur
- [ ] Index FULLTEXT visible dans `SHOW INDEX`
- [ ] SearchService.php modifié
- [ ] Méthode buildFulltextQuery ajoutée
- [ ] Test recherche "facture" fonctionne
- [ ] Test recherche "+term -exclude" fonctionne

## Phase 2: Qdrant optionnel
- [ ] config.php: `qdrant.enabled = false`
- [ ] config.php: `embeddings.enabled = false`
- [ ] VectorStoreService: check isEnabled()
- [ ] EmbeddingService: check isEnabled()
- [ ] Aucune erreur si Qdrant absent

## Phase 3: Cohérence données
- [ ] Migration 029_fix_deleted.sql créée
- [ ] Migration exécutée
- [ ] Vérification: 0 incohérences is_deleted/deleted_at
- [ ] SnapshotService utilise deleted_at (pas is_deleted)

## Phase 4: Outils externes
- [ ] LibreOffice path correct dans config
- [ ] Ghostscript path correct
- [ ] Tesseract path correct
- [ ] ThumbnailGenerator génère miniatures PDF
- [ ] ThumbnailGenerator génère miniatures DOCX (ou placeholder)

## Phase 5: Tests
- [ ] smoke_test.php étendu (30+ checks)
- [ ] test_fulltext_search.php créé
- [ ] Smoke test: 80%+ pass
- [ ] Aucune erreur PHP fatale

## Phase 6: Finalisation
- [ ] Git commit effectué
- [ ] RAPPORT_EXECUTION.md créé
- [ ] ERRORS.md documenté (si erreurs)

## Critères de succès
- [ ] Application démarre sans erreur
- [ ] Recherche fonctionne sans Qdrant
- [ ] Classification fonctionne (IA ou règles)
- [ ] Modale documents s'ouvre
- [ ] Miniatures s'affichent

---

## Commandes utiles

```bash
# Smoke test
php tests/smoke_test.php

# Test recherche
php tests/test_fulltext_search.php

# Vérifier index FULLTEXT
mysql -u root kdocs -e "SHOW INDEX FROM documents WHERE Index_type='FULLTEXT'"

# Vérifier cohérence
mysql -u root kdocs -e "SELECT COUNT(*) FROM documents WHERE (is_deleted=1 AND deleted_at IS NULL) OR (is_deleted=0 AND deleted_at IS NOT NULL)"

# Git status
git status
git diff --stat
```


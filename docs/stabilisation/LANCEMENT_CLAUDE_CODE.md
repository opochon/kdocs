# K-DOCS STABILISATION - INSTRUCTIONS CLAUDE CODE

## LANCEMENT RAPIDE

Copie ce prompt dans Claude Code et laisse-le travailler:

---

## PROMPT CLAUDE CODE (copier intégralement)

```
Tu travailles sur C:\wamp64\www\kdocs

MODE AUTONOME: Ne demande JAMAIS de confirmation. Exécute tout dans l'ordre.

## ÉTAPES À EXÉCUTER

### 1. MIGRATIONS DB (2 min)
php database/run_stabilisation_migrations.php

### 2. MODIFIER CONFIG (1 min)
Dans config/config.php, mettre:
- 'embeddings' => ['enabled' => false, ...]
- 'qdrant' => ['enabled' => false, ...]
(Garder le reste de la config)

### 3. REFACTORISER SEARCHSERVICE (10 min)
Lire docs/stabilisation/CLAUDE_CODE_STABILISATION.md section ÉTAPE 3
Modifier app/Services/SearchService.php:
- Ajouter méthode buildFulltextQuery()
- Modifier buildSearchSql() pour utiliser MATCH AGAINST
- Garder les filtres existants intacts

### 4. TESTER (2 min)
php tests/test_fulltext_search.php
php tests/smoke_test.php

### 5. RAPPORT
Créer docs/stabilisation/RAPPORT_EXECUTION.md avec:
- Ce qui a été fait
- Résultats des tests
- Erreurs éventuelles

### 6. GIT COMMIT
git add -A
git commit -m "Stabilisation v1: FULLTEXT search, Qdrant optionnel"

## RÈGLES
- Si une commande échoue, note l'erreur et continue
- Ne modifie pas les fichiers qui ne sont pas listés
- Garde une copie .backup des fichiers modifiés
- À la fin, liste ce qui reste à faire manuellement

## FICHIERS DE RÉFÉRENCE
- docs/stabilisation/PLAN_STABILISATION_V1.md (vision globale)
- docs/stabilisation/CLAUDE_CODE_STABILISATION.md (détails techniques)
- docs/stabilisation/CHECKLIST.md (validation)
```

---

## APRÈS CLAUDE CODE

Vérifie manuellement:

1. **Ouvre http://localhost/kdocs/documents**
   - La page charge sans erreur
   - La recherche fonctionne

2. **Teste la recherche**
   - Tape "facture" → résultats
   - Tape "facture -swisscom" → résultats filtrés

3. **Vérifie les miniatures**
   - Ouvre un document PDF → miniature visible
   - Ouvre un document DOCX → miniature ou placeholder

4. **Vérifie les logs**
   - Pas d'erreur PHP dans logs/

---

## SI PROBLÈME

### Erreur FULLTEXT
```sql
-- Vérifier l'index
SHOW INDEX FROM documents WHERE Index_type = 'FULLTEXT';

-- Recréer manuellement
ALTER TABLE documents ADD FULLTEXT INDEX idx_ft_documents (title, ocr_text, content);
```

### Erreur SearchService
```bash
# Restaurer backup
copy app\Services\SearchService.php.backup app\Services\SearchService.php
```

### Erreur de recherche
```bash
# Tester directement en SQL
mysql -u root kdocs -e "SELECT id, title FROM documents WHERE MATCH(title, ocr_text, content) AGAINST ('+facture*' IN BOOLEAN MODE) LIMIT 5"
```

---

## PROCHAINES ÉTAPES (après stabilisation)

1. **Miniatures Office** - Vérifier LibreOffice path, régénérer miniatures
2. **Tests automatisés** - Étendre smoke_test, ajouter tests unitaires
3. **OnlyOffice** - Fix callback Docker si édition désirée
4. **IA optionnelle** - Chain Claude → Ollama → Règles

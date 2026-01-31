# K-DOCS STABILISATION - EXÉCUTION UNIQUE

## Contexte
- 2 jours de discussions, trop de docs, pas assez d'action
- Objectif : recherche MySQL FULLTEXT fonctionnelle, Qdrant optionnel, testé, committé

## Prérequis
- Accès MySQL (root, port 3307)
- K-Docs dans C:\wamp64\www\kdocs

---

## ÉTAPE 1 : Migration FULLTEXT (copier-coller dans phpMyAdmin ou CLI)

```sql
USE kdocs;

-- Supprimer ancien index si existe (ignore erreur si n'existe pas)
-- DROP INDEX idx_ft_documents ON documents;

-- Créer index FULLTEXT
ALTER TABLE documents ADD FULLTEXT INDEX idx_ft_documents (title, ocr_text, content);

-- Vérifier
SHOW INDEX FROM documents WHERE Index_type = 'FULLTEXT';
```

**Résultat attendu** : 1 ligne avec idx_ft_documents

---

## ÉTAPE 2 : Config Qdrant optionnel

Dans `config/config.php`, vérifier :

```php
'embeddings' => [
    'enabled' => false,  // <-- FALSE par défaut
    // ...
],
'qdrant' => [
    'enabled' => false,  // <-- FALSE par défaut  
    // ...
],
```

---

## ÉTAPE 3 : Test rapide

```bash
cd C:\wamp64\www\kdocs
php -r "
require 'vendor/autoload.php';
$db = \KDocs\Core\Database::getInstance();

// Test 1: Index existe
$r = $db->query(\"SHOW INDEX FROM documents WHERE Index_type='FULLTEXT'\");
echo $r->fetch() ? '[OK] Index FULLTEXT existe' : '[FAIL] Index manquant';
echo PHP_EOL;

// Test 2: Requête fonctionne
try {
    $db->query(\"SELECT COUNT(*) FROM documents WHERE MATCH(title, ocr_text, content) AGAINST ('test' IN BOOLEAN MODE)\");
    echo '[OK] Requête FULLTEXT fonctionne';
} catch (Exception $e) {
    echo '[FAIL] ' . $e->getMessage();
}
echo PHP_EOL;
"
```

**Résultat attendu** : 2x [OK]

---

## ÉTAPE 4 : Git commit

```bash
cd C:\wamp64\www\kdocs
git add -A
git commit -m "feat: MySQL FULLTEXT search, Qdrant optional"
```

---

## C'EST TOUT.

30 minutes max. Testé. Committé. On passe à autre chose.

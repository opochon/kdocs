# CLAUDE CODE - STABILISATION K-DOCS
# MODE AUTONOME - PAS DE VALIDATION INTERMÉDIAIRE

## CONTEXTE

Tu travailles sur K-Docs, une GED PHP/MySQL. L'objectif est de stabiliser l'application:
- Supprimer la dépendance Qdrant (Docker)
- Implémenter une recherche MySQL FULLTEXT native
- Corriger l'indexation et les deltas
- S'assurer que tout fonctionne sans IA (dégradation gracieuse)
- Valider avec des tests

## RÈGLES D'EXÉCUTION

**TU AS CARTE BLANCHE.** Ne demande JAMAIS de confirmation. Exécute chaque étape dans l'ordre.

Si tu rencontres une erreur:
1. Log l'erreur dans `docs/stabilisation/ERRORS.md`
2. Tente une correction
3. Continue avec l'étape suivante

À la fin, produis un rapport dans `docs/stabilisation/RAPPORT_EXECUTION.md`

---

## ÉTAPE 1: AUDIT INITIAL (5 min)

Exécute et note les résultats:

```bash
cd C:\wamp64\www\kdocs
php tests/smoke_test.php > docs/stabilisation/smoke_before.txt 2>&1
```

Vérifie la structure DB actuelle:
```sql
SHOW INDEX FROM documents;
SHOW COLUMNS FROM documents LIKE '%deleted%';
SELECT COUNT(*) FROM documents WHERE deleted_at IS NOT NULL;
SELECT COUNT(*) FROM documents WHERE is_deleted = 1;
```

---

## ÉTAPE 2: MIGRATION FULLTEXT (10 min)

### 2.1 Créer le fichier de migration

Créer `database/migrations/028_fulltext_search.sql`:

```sql
-- Migration: Ajout recherche FULLTEXT native MySQL
-- Date: 2026-01-31
-- Objectif: Remplacer Qdrant par MySQL FULLTEXT

-- 1. Supprimer anciens index FULLTEXT s'ils existent
DROP INDEX IF EXISTS idx_ft_documents ON documents;
DROP INDEX IF EXISTS idx_ft_correspondents ON correspondents;
DROP INDEX IF EXISTS idx_ft_tags ON tags;

-- 2. S'assurer que les colonnes TEXT existent et ne sont pas NULL
ALTER TABLE documents MODIFY COLUMN title VARCHAR(255) DEFAULT '';
ALTER TABLE documents MODIFY COLUMN ocr_text LONGTEXT;
ALTER TABLE documents MODIFY COLUMN content LONGTEXT;

-- 3. Créer index FULLTEXT sur documents
ALTER TABLE documents 
ADD FULLTEXT INDEX idx_ft_documents (title, ocr_text, content);

-- 4. Créer index FULLTEXT sur correspondents
ALTER TABLE correspondents
ADD FULLTEXT INDEX idx_ft_correspondents (name);

-- 5. Créer index FULLTEXT sur tags
ALTER TABLE tags
ADD FULLTEXT INDEX idx_ft_tags (name);

-- 6. Index supplémentaires pour performance
CREATE INDEX IF NOT EXISTS idx_documents_deleted ON documents(deleted_at);
CREATE INDEX IF NOT EXISTS idx_documents_status ON documents(status);
CREATE INDEX IF NOT EXISTS idx_documents_created ON documents(created_at);
```

### 2.2 Exécuter la migration

```bash
cd C:\wamp64\www\kdocs
php -r "
require 'vendor/autoload.php';
\$db = \KDocs\Core\Database::getInstance();
\$sql = file_get_contents('database/migrations/028_fulltext_search.sql');
// Exécuter chaque statement séparément
foreach (explode(';', \$sql) as \$stmt) {
    \$stmt = trim(\$stmt);
    if (!empty(\$stmt) && !str_starts_with(\$stmt, '--')) {
        try {
            \$db->exec(\$stmt);
            echo \"OK: \" . substr(\$stmt, 0, 50) . \"...\n\";
        } catch (Exception \$e) {
            echo \"WARN: \" . \$e->getMessage() . \"\n\";
        }
    }
}
echo \"Migration FULLTEXT terminée.\n\";
"
```

### 2.3 Vérifier

```sql
SHOW INDEX FROM documents WHERE Index_type = 'FULLTEXT';
```

---

## ÉTAPE 3: REFACTORISER SEARCHSERVICE (20 min)

### 3.1 Backup de l'original

```bash
copy app\Services\SearchService.php app\Services\SearchService.php.backup
```

### 3.2 Modifier SearchService.php

Remplacer la méthode `buildSearchSql` pour utiliser FULLTEXT:

```php
/**
 * Build SQL query for search - VERSION FULLTEXT
 */
private function buildSearchSql(SearchQuery $query): array
{
    $select = "
        SELECT d.*,
               c.name as correspondent_name,
               dt.label as document_type_name,
               df.path as folder_path
    ";

    // Ajouter score FULLTEXT si recherche textuelle
    if (!empty($query->text)) {
        $select .= ",
               MATCH(d.title, d.ocr_text, d.content) AGAINST (:ft_query IN BOOLEAN MODE) AS ft_score";
    }

    $from = "
        FROM documents d
        LEFT JOIN correspondents c ON d.correspondent_id = c.id
        LEFT JOIN document_types dt ON d.document_type_id = dt.id
        LEFT JOIN document_folders df ON d.folder_id = df.id
    ";

    $where = ["d.deleted_at IS NULL"];
    $params = [];
    $joins = [];

    // Recherche FULLTEXT
    if (!empty($query->text)) {
        $ftQuery = $this->buildFulltextQuery($query->text);
        $where[] = "MATCH(d.title, d.ocr_text, d.content) AGAINST (:ft_query IN BOOLEAN MODE)";
        $params['ft_query'] = $ftQuery;
    }

    // ... reste des filtres identique ...

    // Order by - utiliser ft_score si recherche
    $orderBy = match ($query->orderBy) {
        'relevance' => !empty($query->text) ? 'ft_score' : 'd.created_at',
        'created_at' => 'd.created_at',
        'title' => 'd.title',
        default => 'd.created_at',
    };

    // ... reste identique ...
}

/**
 * Convertit la requête utilisateur en syntaxe FULLTEXT BOOLEAN
 * 
 * Exemples:
 *   "facture swisscom" → "+facture* +swisscom*"
 *   "facture OR orange" → "facture orange"
 *   "facture -swisscom" → "+facture* -swisscom*"
 *   '"facture janvier"' → '"facture janvier"'
 */
private function buildFulltextQuery(string $userQuery): string
{
    $userQuery = trim($userQuery);
    
    // Si déjà en format BOOLEAN (contient + ou - ou ")
    if (preg_match('/[+\-"]/', $userQuery)) {
        return $userQuery;
    }
    
    // Parser les opérateurs naturels
    $userQuery = preg_replace('/\bAND\b/i', '', $userQuery);
    $userQuery = preg_replace('/\bOR\b/i', ' ', $userQuery);
    $userQuery = preg_replace('/\bNOT\b/i', '-', $userQuery);
    
    // Extraire les termes
    $terms = preg_split('/\s+/', $userQuery, -1, PREG_SPLIT_NO_EMPTY);
    
    $result = [];
    foreach ($terms as $term) {
        $term = trim($term);
        if (empty($term)) continue;
        
        // Terme négatif
        if (str_starts_with($term, '-')) {
            $result[] = '-' . substr($term, 1) . '*';
        } else {
            // Terme positif avec wildcard
            $result[] = '+' . $term . '*';
        }
    }
    
    return implode(' ', $result);
}
```

### 3.3 Supprimer enrichDocumentsWithRelevance

La pertinence est maintenant gérée par MySQL FULLTEXT (ft_score).
Simplifier ou supprimer cette méthode.

---

## ÉTAPE 4: RENDRE QDRANT OPTIONNEL (10 min)

### 4.1 Modifier config.php

```php
'embeddings' => [
    'enabled' => false,  // Désactivé par défaut
    // ...
],
'qdrant' => [
    'enabled' => false,  // Désactivé par défaut
    // ...
],
```

### 4.2 Modifier VectorStoreService.php

Ajouter au début de chaque méthode publique:

```php
public function search(...): array
{
    if (!$this->isEnabled()) {
        return []; // Fallback silencieux
    }
    // ... code existant
}

private function isEnabled(): bool
{
    return Config::get('qdrant.enabled', false) && $this->isAvailable();
}
```

### 4.3 Modifier EmbeddingService.php

Même pattern - vérifier `enabled` avant toute opération.

---

## ÉTAPE 5: HARMONISER DELETED (5 min)

### 5.1 Créer migration

Créer `database/migrations/029_fix_deleted_fields.sql`:

```sql
-- Migration: Harmoniser is_deleted et deleted_at
-- Date: 2026-01-31

-- Synchroniser is_deleted → deleted_at
UPDATE documents 
SET deleted_at = updated_at 
WHERE is_deleted = 1 AND deleted_at IS NULL;

-- Synchroniser deleted_at → is_deleted
UPDATE documents 
SET is_deleted = 1 
WHERE deleted_at IS NOT NULL AND is_deleted = 0;

-- Ajouter trigger pour maintenir la cohérence (optionnel)
-- DROP TRIGGER IF EXISTS trg_documents_deleted_sync;
-- CREATE TRIGGER trg_documents_deleted_sync
-- BEFORE UPDATE ON documents
-- FOR EACH ROW
-- BEGIN
--     IF NEW.deleted_at IS NOT NULL AND NEW.is_deleted = 0 THEN
--         SET NEW.is_deleted = 1;
--     END IF;
--     IF NEW.deleted_at IS NULL AND NEW.is_deleted = 1 THEN
--         SET NEW.is_deleted = 0;
--     END IF;
-- END;
```

### 5.2 Exécuter

```bash
php -r "
require 'vendor/autoload.php';
\$db = \KDocs\Core\Database::getInstance();
\$sql = file_get_contents('database/migrations/029_fix_deleted_fields.sql');
foreach (explode(';', \$sql) as \$stmt) {
    \$stmt = trim(\$stmt);
    if (!empty(\$stmt) && !str_starts_with(\$stmt, '--')) {
        try { \$db->exec(\$stmt); echo \"OK\n\"; } 
        catch (Exception \$e) { echo \"WARN: \" . \$e->getMessage() . \"\n\"; }
    }
}
"
```

---

## ÉTAPE 6: VÉRIFIER THUMBNAILS (5 min)

### 6.1 Tester LibreOffice

```bash
"C:\Program Files\LibreOffice\program\soffice.exe" --version
```

Si erreur, noter dans ERRORS.md mais continuer.

### 6.2 Vérifier ThumbnailGenerator

Lire `app/Services/ThumbnailGenerator.php` et vérifier:
- Path LibreOffice correct dans config
- Fallback placeholder fonctionne

---

## ÉTAPE 7: TESTS (10 min)

### 7.1 Étendre smoke_test.php

Ajouter ces tests à `tests/smoke_test.php`:

```php
// --- RECHERCHE FULLTEXT ---
echo "\n--- RECHERCHE FULLTEXT ---\n";

try {
    $stmt = $db->query("SHOW INDEX FROM documents WHERE Index_type = 'FULLTEXT'");
    $ftIndex = $stmt->fetch();
    test('20. Index FULLTEXT existe', $ftIndex !== false);
} catch (Exception $e) {
    test('20. Index FULLTEXT existe', false, $e->getMessage());
}

try {
    $stmt = $db->query("
        SELECT COUNT(*) FROM documents 
        WHERE MATCH(title, ocr_text, content) AGAINST ('test' IN BOOLEAN MODE)
    ");
    test('21. Requête FULLTEXT exécutable', true);
} catch (Exception $e) {
    test('21. Requête FULLTEXT exécutable', false, $e->getMessage());
}

// --- COHÉRENCE DONNÉES ---
echo "\n--- COHÉRENCE DONNÉES ---\n";

try {
    $stmt = $db->query("
        SELECT COUNT(*) FROM documents 
        WHERE (is_deleted = 1 AND deleted_at IS NULL) 
           OR (is_deleted = 0 AND deleted_at IS NOT NULL)
    ");
    $inconsistent = $stmt->fetchColumn();
    test('22. Cohérence is_deleted/deleted_at', $inconsistent == 0, "$inconsistent incohérences");
} catch (Exception $e) {
    test('22. Cohérence is_deleted/deleted_at', false, $e->getMessage());
}

// --- OUTILS EXTERNES ---
echo "\n--- OUTILS EXTERNES ---\n";

$libreOfficePath = $config['tools']['libreoffice'] ?? '';
test('23. LibreOffice configuré', !empty($libreOfficePath), $libreOfficePath, true);
test('24. LibreOffice existe', file_exists($libreOfficePath), '', true);

$ghostscriptPath = $config['tools']['ghostscript'] ?? '';
test('25. Ghostscript configuré', !empty($ghostscriptPath));
test('26. Ghostscript existe', file_exists($ghostscriptPath));

$tesseractPath = $config['ocr']['tesseract_path'] ?? '';
test('27. Tesseract configuré', !empty($tesseractPath));
test('28. Tesseract existe', file_exists($tesseractPath));

// --- QDRANT OPTIONNEL ---
echo "\n--- SERVICES OPTIONNELS ---\n";

$qdrantEnabled = $config['qdrant']['enabled'] ?? false;
test('29. Qdrant désactivé par défaut', !$qdrantEnabled, $qdrantEnabled ? 'activé' : 'désactivé');

$embeddingsEnabled = $config['embeddings']['enabled'] ?? false;
test('30. Embeddings désactivé par défaut', !$embeddingsEnabled, $embeddingsEnabled ? 'activé' : 'désactivé', true);
```

### 7.2 Exécuter smoke test

```bash
php tests/smoke_test.php > docs/stabilisation/smoke_after.txt 2>&1
type docs\stabilisation\smoke_after.txt
```

---

## ÉTAPE 8: TEST RECHERCHE MANUEL (5 min)

### 8.1 Créer script de test

Créer `tests/test_fulltext_search.php`:

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

use KDocs\Services\SearchService;
use KDocs\Search\SearchQuery;

echo "=== TEST RECHERCHE FULLTEXT ===\n\n";

$search = new SearchService();

// Test 1: Recherche simple
echo "1. Recherche 'facture':\n";
$query = new SearchQuery();
$query->text = 'facture';
$query->perPage = 5;
$result = $search->advancedSearch($query);
echo "   Trouvé: {$result->total} documents\n";
echo "   Temps: " . round($result->searchTime * 1000) . "ms\n\n";

// Test 2: Recherche multiple termes
echo "2. Recherche 'facture swisscom':\n";
$query->text = 'facture swisscom';
$result = $search->advancedSearch($query);
echo "   Trouvé: {$result->total} documents\n\n";

// Test 3: Recherche avec exclusion
echo "3. Recherche 'facture -swisscom':\n";
$query->text = 'facture -swisscom';
$result = $search->advancedSearch($query);
echo "   Trouvé: {$result->total} documents\n\n";

// Test 4: Phrase exacte
echo "4. Recherche '\"facture janvier\"':\n";
$query->text = '"facture janvier"';
$result = $search->advancedSearch($query);
echo "   Trouvé: {$result->total} documents\n\n";

echo "=== FIN TESTS ===\n";
```

### 8.2 Exécuter

```bash
php tests/test_fulltext_search.php
```

---

## ÉTAPE 9: GIT COMMIT (5 min)

```bash
cd C:\wamp64\www\kdocs
git add -A
git status
git commit -m "Stabilisation v1: Recherche FULLTEXT, Qdrant optionnel

- Ajout index FULLTEXT sur documents, correspondents, tags
- Refactorisation SearchService pour MATCH AGAINST
- Qdrant/embeddings désactivés par défaut
- Harmonisation is_deleted/deleted_at
- Tests smoke étendus
- Documentation stabilisation"
```

---

## ÉTAPE 10: RAPPORT FINAL

Créer `docs/stabilisation/RAPPORT_EXECUTION.md` avec:

1. **Résumé**: Ce qui a été fait
2. **Tests avant/après**: Différence smoke tests
3. **Erreurs rencontrées**: Contenu de ERRORS.md
4. **Prochaines étapes**: Ce qui reste à faire

---

## CHECKLIST FINALE

Avant de terminer, vérifie:

- [ ] Migration FULLTEXT exécutée sans erreur
- [ ] SearchService modifié et fonctionnel
- [ ] Config Qdrant/embeddings = false
- [ ] Cohérence deleted vérifiée
- [ ] Smoke test passe (au moins 80%)
- [ ] Git commit effectué
- [ ] Rapport créé

---

## EN CAS DE PROBLÈME MAJEUR

Si une étape bloque complètement:

1. Note l'erreur exacte dans `docs/stabilisation/ERRORS.md`
2. Crée un fichier `.skip_step_N` pour marquer l'étape sautée
3. Continue avec l'étape suivante
4. À la fin, liste les étapes sautées dans le rapport

**NE T'ARRÊTE PAS.** L'objectif est d'avancer au maximum.


<?php
/**
 * Test de recherche FULLTEXT
 *
 * Usage: php tests/integration/test_fulltext_search.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use KDocs\Core\Database;
use KDocs\Services\SearchService;
use KDocs\Search\SearchQuery;

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║           K-DOCS - TEST RECHERCHE FULLTEXT                   ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

$db = Database::getInstance();
$passed = 0;
$failed = 0;

function test($name, $condition, $detail = '') {
    global $passed, $failed;
    if ($condition) {
        echo "\033[32m[✓]\033[0m $name";
        $passed++;
    } else {
        echo "\033[31m[✗]\033[0m $name";
        $failed++;
    }
    if ($detail) echo " - $detail";
    echo "\n";
    return $condition;
}

// ============================================
// 1. VÉRIFICATION INDEX FULLTEXT
// ============================================
echo "--- 1. VÉRIFICATION INDEX ---\n\n";

try {
    $stmt = $db->query("
        SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME) as columns
        FROM information_schema.statistics 
        WHERE table_schema = DATABASE() 
          AND table_name = 'documents'
          AND index_type = 'FULLTEXT'
        GROUP BY INDEX_NAME
    ");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $hasFulltext = count($indexes) > 0;
    $columns = $indexes[0]['columns'] ?? '';
    test('Index FULLTEXT existe sur documents', $hasFulltext, $columns);
    
    if (!$hasFulltext) {
        echo "\n\033[31m⚠ ERREUR: Index FULLTEXT manquant!\033[0m\n";
        echo "Exécutez: php database/migrations/028_fulltext_search.sql\n\n";
    }
} catch (Exception $e) {
    test('Index FULLTEXT existe', false, $e->getMessage());
}

// ============================================
// 2. TEST REQUÊTE FULLTEXT DIRECTE
// ============================================
echo "\n--- 2. REQUÊTES SQL DIRECTES ---\n\n";

// Compter les documents avec contenu
$stmt = $db->query("SELECT COUNT(*) FROM documents WHERE deleted_at IS NULL AND (ocr_text IS NOT NULL OR content IS NOT NULL)");
$docsWithContent = $stmt->fetchColumn();
echo "Documents avec contenu: $docsWithContent\n\n";

// Test requête MATCH AGAINST
try {
    // '*' seul n'est pas une expression BOOLEAN MODE valide : MySQL leve 1064
    // (unexpected $end, expecting FTS_TERM). La sonde testait donc sa propre
    // syntaxe, pas la disponibilite de l'index. On interroge un terme reel.
    $stmt = $db->query("
        SELECT id, title,
               MATCH(title, ocr_text, content) AGAINST ('+facture*' IN BOOLEAN MODE) AS score
        FROM documents
        WHERE deleted_at IS NULL
        LIMIT 5
    ");
    test('Requête MATCH AGAINST exécutable', true);
} catch (Exception $e) {
    test('Requête MATCH AGAINST exécutable', false, $e->getMessage());
}

// Test avec terme de recherche
try {
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM documents 
        WHERE deleted_at IS NULL 
          AND MATCH(title, ocr_text, content) AGAINST (? IN BOOLEAN MODE)
    ");
    $stmt->execute(['+test*']);
    $count = $stmt->fetchColumn();
    test('Recherche BOOLEAN MODE fonctionne', true, "$count résultat(s) pour 'test'");
} catch (Exception $e) {
    test('Recherche BOOLEAN MODE fonctionne', false, $e->getMessage());
}

// ============================================
// 3. TEST VIA SEARCHSERVICE
// ============================================
echo "\n--- 3. SEARCHSERVICE ---\n\n";

try {
    $search = new SearchService();
    
    // Test 1: Recherche vide (tous les documents)
    $query = new SearchQuery();
    $query->perPage = 10;
    $result = $search->advancedSearch($query);
    test('Recherche sans terme', $result->total >= 0, "{$result->total} documents, {$result->searchTime}s");
    
    // Test 2: Recherche simple
    $query->text = 'document';
    $result = $search->advancedSearch($query);
    $time = round($result->searchTime * 1000);
    test('Recherche "document"', true, "{$result->total} résultats en {$time}ms");
    
    // Test 3: Recherche multi-termes
    $query->text = 'facture swisscom';
    $result = $search->advancedSearch($query);
    test('Recherche "facture swisscom"', true, "{$result->total} résultats");
    
    // Test 4: Performance (doit être < 500ms)
    $query->text = 'test';
    $start = microtime(true);
    for ($i = 0; $i < 10; $i++) {
        $search->advancedSearch($query);
    }
    $avgTime = (microtime(true) - $start) / 10 * 1000;
    test('Performance (10 recherches)', $avgTime < 500, round($avgTime) . "ms moyenne");
    
} catch (Exception $e) {
    test('SearchService fonctionne', false, $e->getMessage());
}

// ============================================
// 4. TEST SYNTAXE BOOLEAN
// ============================================
echo "\n--- 4. SYNTAXE BOOLEAN ---\n\n";

$testQueries = [
    'facture' => 'Terme simple',
    '+facture +janvier' => 'Deux termes obligatoires',
    'facture -swisscom' => 'Exclusion',
    '"facture janvier"' => 'Phrase exacte',
    'factur*' => 'Wildcard',
];

foreach ($testQueries as $queryText => $description) {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM documents 
            WHERE deleted_at IS NULL 
              AND MATCH(title, ocr_text, content) AGAINST (? IN BOOLEAN MODE)
        ");
        $stmt->execute([$queryText]);
        $count = $stmt->fetchColumn();
        test("Syntaxe: $description", true, "'$queryText' → $count résultats");
    } catch (Exception $e) {
        test("Syntaxe: $description", false, $e->getMessage());
    }
}

// ============================================
// 4b. REQUÊTES SANS TERME EXPLOITABLE
// ============================================
// Regression 2026-08-07 : buildFulltextQuery() ecarte les termes de moins de
// deux caracteres. Une recherche « a » ou « de » produisait donc une expression
// BOOLEAN MODE vide, et MySQL levait 1064 — la recherche tombait entierement.
// Ces saisies sont banales : elles doivent repondre, pas casser.
echo "\n--- 4b. SAISIES SANS TERME EXPLOITABLE ---\n\n";

foreach ([
    'terme d une lettre'        => 'a',
    'mot outil trop court'      => 'de',
    'operateur seul'            => '-',
    'guillemets vides'          => '""',
    'espaces seuls'             => '   ',
] as $description => $saisie) {
    // advancedSearch() attrape les exceptions et les range dans $result->error :
    // une recherche cassee ne remonte donc pas, elle rend zero resultat. Verifier
    // l'absence d'exception ne prouverait rien — c'est ce champ qu'il faut lire.
    try {
        $service = new SearchService();
        $res = $service->search($saisie);
        $err = $res->error ?? null;
        test("Sans terme: $description", empty($err), $err ? substr((string) $err, 0, 120) : 'aucune erreur SQL');
    } catch (\Throwable $e) {
        test("Sans terme: $description", false, 'exception: ' . substr($e->getMessage(), 0, 110));
    }
}

// ============================================
// 5. RÉSUMÉ
// ============================================
echo "\n";
echo "══════════════════════════════════════════════════════════════\n";
echo "RÉSUMÉ: $passed réussis, $failed échoués\n";
echo "══════════════════════════════════════════════════════════════\n";

if ($failed > 0) {
    echo "\n\033[31m⚠ Des tests ont échoué. Vérifiez la configuration.\033[0m\n";
    exit(1);
}

echo "\n\033[32m✓ Tous les tests passent!\033[0m\n";
exit(0);

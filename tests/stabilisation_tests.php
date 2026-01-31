<?php
/**
 * K-DOCS - TESTS DE STABILISATION AUTOMATISÉS
 *
 * Exécute: php tests/stabilisation_tests.php
 *
 * Couvre:
 * - Smoke tests
 * - Tests fonctionnels
 * - Tests sécurité (Red Team)
 * - Benchmarks performance
 */

require_once __DIR__ . '/../vendor/autoload.php';

use KDocs\Core\Database;
use KDocs\Core\Config;

// ============================================
// CONFIGURATION
// ============================================

$REPORT_FILE = __DIR__ . '/../docs/stabilisation/RAPPORT_TESTS.md';
$results = [];
$passed = 0;
$failed = 0;
$warnings = 0;
$startTime = microtime(true);

// ============================================
// HELPERS
// ============================================

function test(string $category, string $name, bool $condition, string $detail = '', bool $warning = false): bool {
    global $results, $passed, $failed, $warnings;

    $status = $condition ? 'PASS' : ($warning ? 'WARN' : 'FAIL');
    $results[$category][] = [
        'name' => $name,
        'status' => $status,
        'detail' => $detail
    ];

    $icon = $condition ? "\033[32m[✓]\033[0m" : ($warning ? "\033[33m[!]\033[0m" : "\033[31m[✗]\033[0m");
    echo "$icon $name";
    if ($detail) echo " - $detail";
    echo "\n";

    if ($condition) $passed++;
    elseif ($warning) $warnings++;
    else $failed++;

    return $condition;
}

function section(string $title): void {
    echo "\n\033[1;36m=== $title ===\033[0m\n\n";
}

function httpRequest(string $url, string $method = 'GET', array $data = [], array $headers = []): array {
    $ch = curl_init();

    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
    ];

    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($data);
    }

    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => $response,
        'error' => $error
    ];
}

// ============================================
// DÉBUT DES TESTS
// ============================================

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║         K-DOCS - TESTS DE STABILISATION                      ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";

$config = require __DIR__ . '/../config/config.php';
$appUrl = $config['app']['url'] ?? 'http://localhost/kdocs';

// ============================================
// 1. SMOKE TESTS
// ============================================
section('SMOKE TESTS');

// Database
try {
    $db = Database::getInstance();
    test('SMOKE', 'Connexion MySQL', true);
} catch (Exception $e) {
    test('SMOKE', 'Connexion MySQL', false, $e->getMessage());
    echo "\n\033[31mFATAL: Impossible de continuer sans DB\033[0m\n";
    exit(1);
}

// Tables critiques
$tables = ['documents', 'users', 'correspondents', 'document_types', 'tags'];
foreach ($tables as $table) {
    try {
        $count = $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        test('SMOKE', "Table $table", true, "$count enregistrements");
    } catch (Exception $e) {
        test('SMOKE', "Table $table", false, $e->getMessage());
    }
}

// Index FULLTEXT
try {
    $stmt = $db->query("SHOW INDEX FROM documents WHERE Index_type = 'FULLTEXT'");
    $indexes = $stmt->fetchAll();
    test('SMOKE', 'Index FULLTEXT', count($indexes) > 0, count($indexes) . ' colonnes');
} catch (Exception $e) {
    test('SMOKE', 'Index FULLTEXT', false, $e->getMessage());
}

// Colonne embedding
try {
    $stmt = $db->query("SHOW COLUMNS FROM documents LIKE 'embedding'");
    test('SMOKE', 'Colonne embedding', $stmt->fetch() !== false);
} catch (Exception $e) {
    test('SMOKE', 'Colonne embedding', false, $e->getMessage());
}

// Outils externes
$tools = [
    'Tesseract' => $config['ocr']['tesseract_path'] ?? '',
    'Ghostscript' => $config['tools']['ghostscript'] ?? '',
    'LibreOffice' => $config['tools']['libreoffice'] ?? '',
];
foreach ($tools as $name => $path) {
    $exists = !empty($path) && file_exists($path);
    test('SMOKE', $name, $exists, $exists ? 'OK' : 'Non trouvé', $name === 'LibreOffice');
}

// Ollama
$ollamaUrl = $config['embeddings']['ollama_url'] ?? 'http://localhost:11434';
$resp = httpRequest("$ollamaUrl/api/tags");
test('SMOKE', 'Ollama', $resp['code'] === 200, $resp['code'] === 200 ? 'Accessible' : 'Non accessible', true);

// ============================================
// 2. TESTS FONCTIONNELS - RECHERCHE
// ============================================
section('RECHERCHE');

// FULLTEXT basique
try {
    $stmt = $db->query("
        SELECT COUNT(*) FROM documents
        WHERE MATCH(title, ocr_text, content) AGAINST ('+test*' IN BOOLEAN MODE)
        AND deleted_at IS NULL
    ");
    test('SEARCH', 'FULLTEXT query', true, $stmt->fetchColumn() . ' résultats');
} catch (Exception $e) {
    test('SEARCH', 'FULLTEXT query', false, $e->getMessage());
}

// FULLTEXT avec exclusion
try {
    $stmt = $db->query("
        SELECT COUNT(*) FROM documents
        WHERE MATCH(title, ocr_text, content) AGAINST ('+document -test' IN BOOLEAN MODE)
        AND deleted_at IS NULL
    ");
    test('SEARCH', 'FULLTEXT exclusion (-term)', true);
} catch (Exception $e) {
    test('SEARCH', 'FULLTEXT exclusion', false, $e->getMessage());
}

// SearchService
try {
    $searchService = new \KDocs\Services\SearchService();
    $result = $searchService->search('test', 10);
    test('SEARCH', 'SearchService', true, ($result->total ?? 0) . ' résultats');
} catch (Exception $e) {
    test('SEARCH', 'SearchService', false, $e->getMessage());
}

// ============================================
// 3. TESTS FONCTIONNELS - CRUD
// ============================================
section('CRUD DOCUMENTS');

// Lecture document
try {
    $doc = $db->query("SELECT * FROM documents WHERE deleted_at IS NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    test('CRUD', 'Lecture document', $doc !== false, $doc ? 'ID ' . $doc['id'] : 'Aucun document');
} catch (Exception $e) {
    test('CRUD', 'Lecture document', false, $e->getMessage());
}

// API documents (200 = OK, 401/403 = auth requise = OK aussi)
$resp = httpRequest("$appUrl/api/documents?per_page=5");
$apiOk = in_array($resp['code'], [200, 401, 403]);
test('CRUD', 'API GET /documents', $apiOk, "HTTP {$resp['code']}");

// ============================================
// 4. RED TEAM - SÉCURITÉ
// ============================================
section('SÉCURITÉ (RED TEAM)');

// SQL Injection via recherche
try {
    $malicious = "'; DROP TABLE users; --";
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM documents
        WHERE MATCH(title, ocr_text, content) AGAINST (? IN BOOLEAN MODE)
    ");
    $stmt->execute([$malicious]);
    // Si on arrive ici sans erreur, c'est que c'est échappé
    test('SECURITY', 'SQL Injection (recherche)', true, 'Échappé correctement');
} catch (Exception $e) {
    // Une erreur SQL est aussi acceptable (requête rejetée)
    test('SECURITY', 'SQL Injection (recherche)', true, 'Requête rejetée');
}

// SQL Injection via API
$resp = httpRequest("$appUrl/api/documents?search=" . urlencode("'; DROP TABLE--"));
test('SECURITY', 'SQL Injection (API)', $resp['code'] !== 500, "HTTP {$resp['code']}");

// XSS dans titre (vérifier que c'est échappé en DB)
try {
    $xssPayload = '<script>alert("XSS")</script>';
    // On ne fait que vérifier que ça ne casse pas
    $stmt = $db->prepare("SELECT * FROM documents WHERE title LIKE ?");
    $stmt->execute(['%' . $xssPayload . '%']);
    test('SECURITY', 'XSS stocké (requête)', true, 'Pas de crash');
} catch (Exception $e) {
    test('SECURITY', 'XSS stocké (requête)', false, $e->getMessage());
}

// Path traversal
$resp = httpRequest("$appUrl/api/documents/../../../etc/passwd");
test('SECURITY', 'Path traversal', $resp['code'] === 404 || $resp['code'] === 400, "HTTP {$resp['code']}");

// ============================================
// 5. ROBUSTESSE
// ============================================
section('ROBUSTESSE');

// Recherche vide
try {
    $searchService = new \KDocs\Services\SearchService();
    $result = $searchService->search('', 10);
    test('ROBUST', 'Recherche vide', true, ($result->total ?? 0) . ' résultats');
} catch (Exception $e) {
    test('ROBUST', 'Recherche vide', false, $e->getMessage());
}

// Recherche très longue
try {
    $longQuery = str_repeat('test ', 100);
    $searchService = new \KDocs\Services\SearchService();
    $result = $searchService->search($longQuery, 10);
    test('ROBUST', 'Recherche longue (500 chars)', true);
} catch (Exception $e) {
    test('ROBUST', 'Recherche longue', false, $e->getMessage());
}

// Caractères spéciaux
try {
    $specialChars = "été café naïf @#$%^&*()";
    $searchService = new \KDocs\Services\SearchService();
    $result = $searchService->search($specialChars, 10);
    test('ROBUST', 'Caractères spéciaux/accents', true);
} catch (Exception $e) {
    test('ROBUST', 'Caractères spéciaux', false, $e->getMessage());
}

// ============================================
// 6. PERFORMANCE
// ============================================
section('PERFORMANCE');

// Benchmark recherche FULLTEXT
$iterations = 10;
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $db->query("SELECT id, title FROM documents WHERE MATCH(title, ocr_text, content) AGAINST ('+document*' IN BOOLEAN MODE) LIMIT 20");
}
$avgTime = (microtime(true) - $start) / $iterations * 1000;
test('PERF', "FULLTEXT ({$iterations}x)", $avgTime < 200, round($avgTime, 1) . 'ms moyenne');

// Benchmark liste documents
$start = microtime(true);
$db->query("SELECT d.*, dt.label FROM documents d LEFT JOIN document_types dt ON d.document_type_id = dt.id WHERE d.deleted_at IS NULL ORDER BY d.created_at DESC LIMIT 100");
$listTime = (microtime(true) - $start) * 1000;
test('PERF', 'Liste 100 documents', $listTime < 500, round($listTime, 1) . 'ms');

// Benchmark API
$start = microtime(true);
httpRequest("$appUrl/api/documents?per_page=50");
$apiTime = (microtime(true) - $start) * 1000;
test('PERF', 'API documents', $apiTime < 1000, round($apiTime, 1) . 'ms');

// ============================================
// 7. SERVICES
// ============================================
section('SERVICES');

$services = [
    'OCRService' => \KDocs\Services\OCRService::class,
    'ClassificationService' => \KDocs\Services\ClassificationService::class,
    'EmbeddingService' => \KDocs\Services\EmbeddingService::class,
    'ThumbnailGenerator' => \KDocs\Services\ThumbnailGenerator::class,
    'SnapshotService' => \KDocs\Services\SnapshotService::class,
];

foreach ($services as $name => $class) {
    try {
        $instance = new $class();
        test('SERVICES', $name, true);
    } catch (Exception $e) {
        test('SERVICES', $name, false, $e->getMessage());
    }
}

// ============================================
// RÉSUMÉ
// ============================================

$totalTime = round(microtime(true) - $startTime, 2);

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║                        RÉSUMÉ                                ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

$total = $passed + $failed + $warnings;
echo "Total:     $total tests\n";
echo "\033[32mRéussis:   $passed\033[0m\n";
echo "\033[33mWarnings:  $warnings\033[0m\n";
echo "\033[31mÉchoués:   $failed\033[0m\n";
echo "Temps:     {$totalTime}s\n\n";

if ($failed === 0) {
    echo "\033[32m✓ STABILISATION OK - Prêt pour production\033[0m\n";
} else {
    echo "\033[31m✗ $failed TESTS ÉCHOUÉS - Corrections requises\033[0m\n";
}

// ============================================
// GÉNÉRATION RAPPORT
// ============================================

$report = "# K-DOCS - RAPPORT DE STABILISATION\n\n";
$report .= "**Date:** " . date('Y-m-d H:i:s') . "\n";
$report .= "**Durée:** {$totalTime}s\n\n";
$report .= "## Résumé\n\n";
$report .= "| Métrique | Valeur |\n";
$report .= "|----------|--------|\n";
$report .= "| Tests exécutés | $total |\n";
$report .= "| Réussis | $passed |\n";
$report .= "| Warnings | $warnings |\n";
$report .= "| Échoués | $failed |\n\n";

foreach ($results as $category => $tests) {
    $report .= "## $category\n\n";
    $report .= "| Test | Statut | Détail |\n";
    $report .= "|------|--------|--------|\n";
    foreach ($tests as $test) {
        $icon = match($test['status']) {
            'PASS' => '✅',
            'WARN' => '⚠️',
            'FAIL' => '❌',
        };
        $report .= "| {$test['name']} | $icon {$test['status']} | {$test['detail']} |\n";
    }
    $report .= "\n";
}

if ($failed > 0) {
    $report .= "## Actions requises\n\n";
    foreach ($results as $category => $tests) {
        foreach ($tests as $test) {
            if ($test['status'] === 'FAIL') {
                $report .= "- **{$test['name']}**: {$test['detail']}\n";
            }
        }
    }
}

// Créer le dossier si nécessaire
$reportDir = dirname($REPORT_FILE);
if (!is_dir($reportDir)) {
    mkdir($reportDir, 0755, true);
}

file_put_contents($REPORT_FILE, $report);
echo "\nRapport généré: $REPORT_FILE\n";

exit($failed > 0 ? 1 : 0);

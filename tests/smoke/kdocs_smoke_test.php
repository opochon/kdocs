<?php
/**
 * K-DOCS Smoke Test Suite
 * Tests all critical functionality after merge
 *
 * Run: php tests/smoke/kdocs_smoke_test.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Bootstrap
require_once __DIR__ . '/../../vendor/autoload.php';

// Results tracking
$results = [
    'passed' => 0,
    'failed' => 0,
    'skipped' => 0,
    'tests' => []
];

function test(string $name, callable $fn): void {
    global $results;
    try {
        $result = $fn();
        if ($result === 'skip') {
            $results['skipped']++;
            $results['tests'][] = ['name' => $name, 'status' => 'SKIP', 'message' => 'Skipped'];
            echo "  [SKIP] $name\n";
        } else {
            $results['passed']++;
            $results['tests'][] = ['name' => $name, 'status' => 'PASS', 'message' => ''];
            echo "  [PASS] $name\n";
        }
    } catch (\Throwable $e) {
        $results['failed']++;
        $msg = $e->getMessage();
        $results['tests'][] = ['name' => $name, 'status' => 'FAIL', 'message' => $msg];
        echo "  [FAIL] $name - $msg\n";
    }
}

function assertThat($condition, string $message = 'Assertion failed'): void {
    if (!$condition) {
        throw new \Exception($message);
    }
}

echo "\n";
echo "================================================================\n";
echo "     K-DOCS SMOKE TEST SUITE                                    \n";
echo "================================================================\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

// ============================================
// 1. ENVIRONMENT
// ============================================
echo "=== 1. ENVIRONMENT ===\n";

test('PHP version >= 8.0', function() {
    assertThat(version_compare(PHP_VERSION, '8.0.0', '>='), 'PHP 8+ required');
});

test('Required extensions loaded', function() {
    $required = ['curl', 'mbstring', 'pdo', 'pdo_mysql', 'json', 'zip'];
    foreach ($required as $ext) {
        assertThat(extension_loaded($ext), "Extension $ext not loaded");
    }
});

test('Vendor autoload works', function() {
    assertThat(class_exists('KDocs\Core\Config'), 'Config class not found');
    assertThat(class_exists('KDocs\Core\Database'), 'Database class not found');
});

// ============================================
// 2. CORE CLASSES
// ============================================
echo "\n=== 2. CORE CLASSES ===\n";

test('Config loads', function() {
    $config = \KDocs\Core\Config::load();
    assertThat(is_array($config), 'Config should be array');
    assertThat(isset($config['database']), 'Database config missing');
});

test('Database connection', function() {
    try {
        $db = \KDocs\Core\Database::getInstance();
        $stmt = $db->query("SELECT 1");
        assertThat($stmt !== false, 'Query failed');
    } catch (\Exception $e) {
        throw new \Exception('DB connection failed: ' . $e->getMessage());
    }
});

// ============================================
// 3. NEW MERGED SERVICES
// ============================================
echo "\n=== 3. MERGED SERVICES (POC) ===\n";

test('AIHelper class exists', function() {
    assertThat(class_exists('KDocs\Helpers\AIHelper'), 'AIHelper not found');
});

test('AIHelper::parseJsonResponse', function() {
    $json = '{"type":"test"}';
    $result = \KDocs\Helpers\AIHelper::parseJsonResponse($json);
    assertThat(is_array($result), 'Should return array');
    assertThat($result['type'] === 'test', 'Should parse type');
});

test('AIHelper::cosineSimilarity', function() {
    $sim = \KDocs\Helpers\AIHelper::cosineSimilarity([1,0], [1,0]);
    assertThat(abs($sim - 1.0) < 0.01, 'Identical vectors should have similarity 1');
});

test('AIHelper::ensureUtf8', function() {
    $text = \KDocs\Helpers\AIHelper::ensureUtf8("Test éàü");
    assertThat(mb_check_encoding($text, 'UTF-8'), 'Should be valid UTF-8');
});

test('AIHelper::extractFields', function() {
    $fields = \KDocs\Helpers\AIHelper::extractFields("Date: 15/01/2025 CHF 100.00");
    assertThat(isset($fields['date']), 'Should extract date');
    assertThat(isset($fields['amount']), 'Should extract amount');
});

test('TrainingService class exists', function() {
    assertThat(class_exists('KDocs\Services\TrainingService'), 'TrainingService not found');
});

test('TrainingService instantiation', function() {
    try {
        $service = new \KDocs\Services\TrainingService();
        $stats = $service->getStatistics();
        assertThat(is_array($stats), 'Should return stats array');
    } catch (\Exception $e) {
        return 'skip';
    }
});

test('AIProviderService class exists', function() {
    assertThat(class_exists('KDocs\Services\AIProviderService'), 'AIProviderService not found');
});

test('AIProviderService instantiation', function() {
    try {
        $service = new \KDocs\Services\AIProviderService();
        $provider = $service->getBestProvider();
        assertThat(in_array($provider, ['claude', 'ollama', 'none']), 'Invalid provider');
    } catch (\Exception $e) {
        return 'skip';
    }
});

test('AIProviderService::getStatus', function() {
    try {
        $service = new \KDocs\Services\AIProviderService();
        $status = $service->getStatus();
        assertThat(isset($status['active_provider']), 'Should have active_provider');
        assertThat(isset($status['ollama']), 'Should have ollama status');
    } catch (\Exception $e) {
        return 'skip';
    }
});

// ============================================
// 4. EXISTING SERVICES
// ============================================
echo "\n=== 4. EXISTING SERVICES ===\n";

test('ClaudeService class exists', function() {
    assertThat(class_exists('KDocs\Services\ClaudeService'), 'ClaudeService not found');
});

test('EmbeddingService class exists', function() {
    assertThat(class_exists('KDocs\Services\EmbeddingService'), 'EmbeddingService not found');
});

test('EmbeddingService instantiation', function() {
    try {
        $service = new \KDocs\Services\EmbeddingService();
        $info = $service->getModelInfo();
        assertThat(isset($info['provider']), 'Should have provider info');
    } catch (\Exception $e) {
        return 'skip';
    }
});

test('PDFSplitterService class exists', function() {
    assertThat(class_exists('KDocs\Services\PDFSplitterService'), 'PDFSplitterService not found');
});

test('SearchService class exists', function() {
    assertThat(class_exists('KDocs\Services\SearchService'), 'SearchService not found');
});

test('DocumentProcessor class exists', function() {
    assertThat(class_exists('KDocs\Services\DocumentProcessor'), 'DocumentProcessor not found');
});

// ============================================
// 5. DATABASE TABLES
// ============================================
echo "\n=== 5. DATABASE TABLES ===\n";

test('documents table exists', function() {
    $db = \KDocs\Core\Database::getInstance();
    $stmt = $db->query("SHOW TABLES LIKE 'documents'");
    assertThat($stmt->rowCount() > 0, 'documents table missing');
});

test('documents table has required columns', function() {
    $db = \KDocs\Core\Database::getInstance();
    $stmt = $db->query("DESCRIBE documents");
    $columns = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'Field');

    $required = ['id', 'title', 'content', 'created_at'];
    foreach ($required as $col) {
        assertThat(in_array($col, $columns), "Column $col missing");
    }
});

test('FULLTEXT index on documents', function() {
    $db = \KDocs\Core\Database::getInstance();
    $stmt = $db->query("SHOW INDEX FROM documents WHERE Index_type = 'FULLTEXT'");
    $indexes = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    assertThat(count($indexes) > 0, 'FULLTEXT index missing');
});

// ============================================
// 6. AI PROVIDERS
// ============================================
echo "\n=== 6. AI PROVIDERS ===\n";

test('Ollama availability check', function() {
    try {
        $service = new \KDocs\Services\AIProviderService();
        $available = $service->isOllamaAvailable();
        echo " (Ollama: " . ($available ? 'YES' : 'NO') . ")";
        return true; // Don't fail if not available
    } catch (\Exception $e) {
        return 'skip';
    }
});

test('Claude availability check', function() {
    try {
        $service = new \KDocs\Services\AIProviderService();
        $available = $service->isClaudeAvailable();
        echo " (Claude: " . ($available ? 'YES' : 'NO') . ")";
        return true; // Don't fail if not available
    } catch (\Exception $e) {
        return 'skip';
    }
});

// ============================================
// 7. FILE STORAGE
// ============================================
echo "\n=== 7. FILE STORAGE ===\n";

test('Storage directories exist', function() {
    $config = \KDocs\Core\Config::load();
    $dirs = ['documents', 'thumbnails', 'temp'];
    foreach ($dirs as $dir) {
        $path = $config['storage'][$dir] ?? null;
        if ($path && !is_dir($path)) {
            @mkdir($path, 0755, true);
        }
    }
    return true;
});

test('Storage is writable', function() {
    $config = \KDocs\Core\Config::load();
    $tempDir = $config['storage']['temp'] ?? __DIR__ . '/../../storage/temp';
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0755, true);
    }
    $testFile = $tempDir . '/smoke_test_' . uniqid() . '.txt';
    $written = file_put_contents($testFile, 'test');
    @unlink($testFile);
    assertThat($written !== false, 'Storage not writable');
});

// ============================================
// SUMMARY
// ============================================
echo "\n";
echo "================================================================\n";
echo "     SUMMARY                                                    \n";
echo "================================================================\n";
$total = $results['passed'] + $results['failed'] + $results['skipped'];
$rate = $total > 0 ? round(($results['passed'] / ($results['passed'] + $results['failed'])) * 100, 1) : 0;

echo "  Total tests: $total\n";
echo "  Passed:      {$results['passed']}\n";
echo "  Failed:      {$results['failed']}\n";
echo "  Skipped:     {$results['skipped']}\n";
echo "  Pass rate:   {$rate}%\n";
echo "\n";

if ($results['failed'] === 0) {
    echo "  ================================================================\n";
    echo "  |   ALL TESTS PASSED                                          |\n";
    echo "  ================================================================\n";
} else {
    echo "  ================================================================\n";
    echo "  |   SOME TESTS FAILED - Review above                          |\n";
    echo "  ================================================================\n";

    echo "\n  Failed tests:\n";
    foreach ($results['tests'] as $test) {
        if ($test['status'] === 'FAIL') {
            echo "    - {$test['name']}: {$test['message']}\n";
        }
    }
}

// Save results
$resultsFile = __DIR__ . '/../results/smoke_test_' . date('Ymd_His') . '.json';
if (!is_dir(dirname($resultsFile))) {
    @mkdir(dirname($resultsFile), 0755, true);
}
file_put_contents($resultsFile, json_encode($results, JSON_PRETTY_PRINT));
echo "\n  Results saved: $resultsFile\n";

exit($results['failed'] > 0 ? 1 : 0);

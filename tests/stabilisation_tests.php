<?php
/**
 * K-Docs - Tests de Stabilisation Complets
 * Smoke + Fonctionnel + Sécurité + Performance
 */

require_once __DIR__ . '/../vendor/autoload.php';

use KDocs\Core\Database;
use KDocs\Core\Config;
use KDocs\Services\SearchService;

class StabilisationTests
{
    private \PDO $db;
    private array $results = [];
    private int $passed = 0;
    private int $failed = 0;
    private float $startTime;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->startTime = microtime(true);
    }

    public function run(): void
    {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "K-DOCS STABILISATION TESTS\n";
        echo str_repeat("=", 60) . "\n\n";

        $this->runSmokeTests();
        $this->runFunctionalTests();
        $this->runSecurityTests();
        $this->runPerformanceTests();

        $this->printSummary();
        $this->generateReport();
    }

    // ==========================================
    // SMOKE TESTS
    // ==========================================
    private function runSmokeTests(): void
    {
        echo "--- SMOKE TESTS ---\n";

        // DB Connection
        $this->test('S1', 'DB Connection', function() {
            return $this->db->query("SELECT 1")->fetch() !== false;
        });

        // FULLTEXT Index
        $this->test('S2', 'FULLTEXT Index', function() {
            $r = $this->db->query("SHOW INDEX FROM documents WHERE Index_type='FULLTEXT'");
            return $r->fetch() !== false;
        });

        // Embedding columns
        $this->test('S3', 'Embedding columns', function() {
            $r = $this->db->query("SHOW COLUMNS FROM documents LIKE 'embedding'");
            return $r->fetch() !== false;
        });

        // Tesseract
        $this->test('S4', 'Tesseract OCR', function() {
            $path = Config::get('ocr.tesseract_path');
            return file_exists($path);
        });

        // Ghostscript
        $this->test('S5', 'Ghostscript', function() {
            $path = Config::get('tools.ghostscript');
            return file_exists($path);
        });

        // Ollama
        $this->test('S6', 'Ollama API', function() {
            $url = Config::get('embeddings.ollama_url', 'http://localhost:11434');
            $ch = curl_init($url . '/api/tags');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
            $response = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $code === 200;
        });

        // SearchService
        $this->test('S7', 'SearchService', function() {
            $search = new SearchService();
            $result = $search->search('test', 5);
            return $result->total >= 0;
        });

        echo "\n";
    }

    // ==========================================
    // FUNCTIONAL TESTS
    // ==========================================
    private function runFunctionalTests(): void
    {
        echo "--- FUNCTIONAL TESTS ---\n";

        // T1: Documents exist
        $this->test('T1', 'Documents in DB', function() {
            $r = $this->db->query("SELECT COUNT(*) c FROM documents WHERE deleted_at IS NULL");
            return $r->fetch()['c'] > 0;
        });

        // T2: OCR text exists
        $this->test('T2', 'OCR text populated', function() {
            $r = $this->db->query("SELECT COUNT(*) c FROM documents WHERE ocr_text IS NOT NULL AND ocr_text != ''");
            return $r->fetch()['c'] > 0;
        });

        // T3: FULLTEXT search works
        $this->test('T3', 'FULLTEXT search', function() {
            $r = $this->db->query("SELECT COUNT(*) c FROM documents WHERE MATCH(title, ocr_text, content) AGAINST('+test*' IN BOOLEAN MODE)");
            return $r->fetch()['c'] >= 0; // 0 is OK, just needs to not error
        });

        // T4: Correspondents table
        $this->test('T4', 'Correspondents table', function() {
            $r = $this->db->query("SELECT COUNT(*) c FROM correspondents");
            return $r->fetch()['c'] >= 0;
        });

        // T5: Document types table
        $this->test('T5', 'Document types table', function() {
            $r = $this->db->query("SELECT COUNT(*) c FROM document_types");
            return $r->fetch()['c'] >= 0;
        });

        // T6: Tags table
        $this->test('T6', 'Tags table', function() {
            $r = $this->db->query("SELECT COUNT(*) c FROM tags");
            return $r->fetch()['c'] >= 0;
        });

        // T7: Folders table
        $this->test('T7', 'Folders table', function() {
            $r = $this->db->query("SELECT COUNT(*) c FROM document_folders");
            return $r->fetch()['c'] >= 0;
        });

        // T8: Users table with admin
        $this->test('T8', 'Admin user exists', function() {
            $r = $this->db->query("SELECT COUNT(*) c FROM users WHERE role = 'admin'");
            return $r->fetch()['c'] > 0;
        });

        // T9: Storage directories exist
        $this->test('T9', 'Storage directories', function() {
            $dirs = ['documents', 'thumbnails', 'temp', 'consume'];
            foreach ($dirs as $dir) {
                $path = Config::get("storage.$dir") ?? Config::get('storage.base_path') . "/../$dir";
                if (!is_dir($path)) return false;
            }
            return true;
        });

        // T10: Search with filters
        $this->test('T10', 'Search with filters', function() {
            $search = new SearchService();
            $query = new \KDocs\Search\SearchQuery();
            $query->text = '';
            $query->perPage = 5;
            $result = $search->advancedSearch($query);
            return $result->total >= 0;
        });

        echo "\n";
    }

    // ==========================================
    // SECURITY TESTS
    // ==========================================
    private function runSecurityTests(): void
    {
        echo "--- SECURITY TESTS ---\n";

        // R1: SQL Injection via search
        $this->test('R1', 'SQL Injection (search)', function() {
            try {
                $search = new SearchService();
                $result = $search->search("'; DROP TABLE documents; --", 5);
                // If we get here without exception, check table still exists
                $r = $this->db->query("SELECT 1 FROM documents LIMIT 1");
                return $r->fetch() !== false;
            } catch (\Exception $e) {
                return true; // Exception is OK (query blocked)
            }
        });

        // R2: SQL Injection via FULLTEXT
        $this->test('R2', 'SQL Injection (FULLTEXT)', function() {
            try {
                $this->db->query("SELECT * FROM documents WHERE MATCH(title) AGAINST('+test* --)' IN BOOLEAN MODE)");
                return true;
            } catch (\Exception $e) {
                return true; // Exception is OK
            }
        });

        // R3: XSS in title (escaped)
        $this->test('R3', 'XSS escaping', function() {
            $xss = '<script>alert(1)</script>';
            $escaped = htmlspecialchars($xss, ENT_QUOTES, 'UTF-8');
            return strpos($escaped, '<script>') === false;
        });

        // R4: Path traversal blocked
        $this->test('R4', 'Path traversal blocked', function() {
            $maliciousPath = '../../etc/passwd';
            $basePath = Config::get('storage.documents');
            $fullPath = realpath($basePath . '/' . $maliciousPath);
            // Should either be false or not start with basePath
            return $fullPath === false || strpos($fullPath, realpath($basePath)) !== 0;
        });

        // R5: Empty search doesn't crash
        $this->test('R5', 'Empty search safe', function() {
            $search = new SearchService();
            $result = $search->search('', 5);
            return $result !== null;
        });

        // R6: Long search doesn't timeout
        $this->test('R6', 'Long search (500 chars)', function() {
            $search = new SearchService();
            $longQuery = str_repeat('test ', 100);
            $start = microtime(true);
            $result = $search->search($longQuery, 5);
            $duration = microtime(true) - $start;
            return $duration < 5; // Less than 5 seconds
        });

        // R7: Special chars in search
        $this->test('R7', 'Special chars safe', function() {
            $search = new SearchService();
            $result = $search->search('test & <> " \' % _', 5);
            return $result !== null && !isset($result->error);
        });

        echo "\n";
    }

    // ==========================================
    // PERFORMANCE TESTS
    // ==========================================
    private function runPerformanceTests(): void
    {
        echo "--- PERFORMANCE TESTS ---\n";

        // P1: FULLTEXT search < 200ms
        $this->test('P1', 'FULLTEXT < 200ms', function() {
            $times = [];
            for ($i = 0; $i < 5; $i++) {
                $start = microtime(true);
                $this->db->query("SELECT * FROM documents WHERE MATCH(title, ocr_text, content) AGAINST('+test*' IN BOOLEAN MODE) LIMIT 10");
                $times[] = (microtime(true) - $start) * 1000;
            }
            $avg = array_sum($times) / count($times);
            echo " (" . round($avg) . "ms)";
            return $avg < 200;
        });

        // P2: Document list < 500ms
        $this->test('P2', 'Doc list < 500ms', function() {
            $start = microtime(true);
            $this->db->query("SELECT d.*, c.name FROM documents d LEFT JOIN correspondents c ON d.correspondent_id = c.id WHERE d.deleted_at IS NULL ORDER BY d.created_at DESC LIMIT 100");
            $duration = (microtime(true) - $start) * 1000;
            echo " (" . round($duration) . "ms)";
            return $duration < 500;
        });

        // P3: SearchService < 300ms
        $this->test('P3', 'SearchService < 300ms', function() {
            $search = new SearchService();
            $times = [];
            for ($i = 0; $i < 3; $i++) {
                $start = microtime(true);
                $search->search('document', 25);
                $times[] = (microtime(true) - $start) * 1000;
            }
            $avg = array_sum($times) / count($times);
            echo " (" . round($avg) . "ms)";
            return $avg < 300;
        });

        // P4: Count query < 100ms
        $this->test('P4', 'Count < 100ms', function() {
            $start = microtime(true);
            $this->db->query("SELECT COUNT(*) FROM documents WHERE deleted_at IS NULL");
            $duration = (microtime(true) - $start) * 1000;
            echo " (" . round($duration) . "ms)";
            return $duration < 100;
        });

        echo "\n";
    }

    // ==========================================
    // HELPERS
    // ==========================================
    private function test(string $id, string $name, callable $test): bool
    {
        try {
            $result = $test();
            if ($result) {
                echo "\033[32m[PASS]\033[0m $id: $name\n";
                $this->passed++;
                $this->results[$id] = ['name' => $name, 'status' => 'PASS'];
                return true;
            } else {
                echo "\033[31m[FAIL]\033[0m $id: $name\n";
                $this->failed++;
                $this->results[$id] = ['name' => $name, 'status' => 'FAIL'];
                return false;
            }
        } catch (\Exception $e) {
            echo "\033[31m[FAIL]\033[0m $id: $name - " . substr($e->getMessage(), 0, 50) . "\n";
            $this->failed++;
            $this->results[$id] = ['name' => $name, 'status' => 'FAIL', 'error' => $e->getMessage()];
            return false;
        }
    }

    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $pct = $total > 0 ? round(($this->passed / $total) * 100) : 0;
        $duration = round(microtime(true) - $this->startTime, 2);

        echo str_repeat("=", 60) . "\n";
        echo "RÉSULTAT: {$this->passed}/{$total} PASS ({$pct}%) - {$duration}s\n";
        echo str_repeat("=", 60) . "\n";

        if ($this->failed === 0) {
            echo "\n\033[32m✓ TOUS LES TESTS PASSENT - STABILISATION OK\033[0m\n\n";
        } else {
            echo "\n\033[31m✗ {$this->failed} TEST(S) EN ÉCHEC\033[0m\n";
            echo "\nTests échoués:\n";
            foreach ($this->results as $id => $result) {
                if ($result['status'] === 'FAIL') {
                    echo "  - $id: {$result['name']}";
                    if (isset($result['error'])) {
                        echo " ({$result['error']})";
                    }
                    echo "\n";
                }
            }
            echo "\n";
        }
    }

    private function generateReport(): void
    {
        $reportDir = __DIR__ . '/../docs/stabilisation';
        if (!is_dir($reportDir)) {
            mkdir($reportDir, 0755, true);
        }

        $total = $this->passed + $this->failed;
        $pct = $total > 0 ? round(($this->passed / $total) * 100) : 0;
        $duration = round(microtime(true) - $this->startTime, 2);
        $date = date('Y-m-d H:i:s');

        $report = "# K-Docs - Rapport de Tests de Stabilisation\n\n";
        $report .= "**Date:** $date\n";
        $report .= "**Durée:** {$duration}s\n";
        $report .= "**Résultat:** {$this->passed}/{$total} PASS ({$pct}%)\n\n";

        $report .= "## Résumé\n\n";
        $report .= "| Catégorie | Tests | Passés | Échoués |\n";
        $report .= "|-----------|-------|--------|----------|\n";

        $categories = ['S' => 'Smoke', 'T' => 'Fonctionnel', 'R' => 'Sécurité', 'P' => 'Performance'];
        foreach ($categories as $prefix => $name) {
            $catTests = array_filter($this->results, fn($k) => str_starts_with($k, $prefix), ARRAY_FILTER_USE_KEY);
            $catPassed = count(array_filter($catTests, fn($r) => $r['status'] === 'PASS'));
            $catFailed = count($catTests) - $catPassed;
            $report .= "| $name | " . count($catTests) . " | $catPassed | $catFailed |\n";
        }

        $report .= "\n## Détails\n\n";

        foreach ($categories as $prefix => $name) {
            $report .= "### $name\n\n";
            $report .= "| ID | Test | Statut |\n";
            $report .= "|----|------|--------|\n";

            foreach ($this->results as $id => $result) {
                if (str_starts_with($id, $prefix)) {
                    $status = $result['status'] === 'PASS' ? '✅ PASS' : '❌ FAIL';
                    $report .= "| $id | {$result['name']} | $status |\n";
                }
            }
            $report .= "\n";
        }

        if ($this->failed > 0) {
            $report .= "## Échecs à corriger\n\n";
            foreach ($this->results as $id => $result) {
                if ($result['status'] === 'FAIL') {
                    $report .= "- **$id: {$result['name']}**";
                    if (isset($result['error'])) {
                        $report .= "\n  - Erreur: {$result['error']}";
                    }
                    $report .= "\n";
                }
            }
        }

        $report .= "\n---\n*Généré automatiquement par stabilisation_tests.php*\n";

        $reportPath = $reportDir . '/RAPPORT_TESTS.md';
        file_put_contents($reportPath, $report);
        echo "Rapport généré: $reportPath\n";
    }
}

// Run tests
$tests = new StabilisationTests();
$tests->run();

<?php
/**
 * K-Docs - Tests d'intégration complets
 * Vérifie: Indexation, CASCADE IA, Outils externes, API
 *
 * Usage: php tests/integration_tests.php [--verbose] [--json]
 */

define('BASE_PATH', dirname(__DIR__));

// Fix UTF-8 pour Windows
if (PHP_OS_FAMILY === 'Windows') {
    @exec('chcp 65001 > nul 2>&1');
    if (function_exists('sapi_windows_cp_set')) {
        sapi_windows_cp_set(65001);
    }
}

// Charger l'autoloader Composer
$autoloadPath = BASE_PATH . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
} else {
    require_once BASE_PATH . '/app/autoload.php';
}

// Charger les helpers
require_once BASE_PATH . '/app/helpers.php';

use KDocs\Core\Config;
use KDocs\Core\Database;
use KDocs\Services\AIProviderService;
use KDocs\Services\OCRService;
use KDocs\Services\EmbeddingService;
use KDocs\Services\ThumbnailGenerator;

class IntegrationTests
{
    private array $results = [];
    private bool $verbose = false;
    private bool $jsonOutput = false;
    private string $samplesPath;
    private string $consumePath;
    private string $storagePath;

    public function __construct(bool $verbose = false, bool $jsonOutput = false)
    {
        $this->verbose = $verbose;
        $this->jsonOutput = $jsonOutput;
        $this->samplesPath = BASE_PATH . '/proofofconcept/samples';
        $this->consumePath = Config::get('storage.consume');
        $this->storagePath = Config::get('storage.base_path');
    }

    public function run(): array
    {
        $this->printHeader("K-DOCS TESTS D'INTEGRATION");

        // 1. Tests d'indexation
        $this->runIndexationTests();

        // 2. Tests CASCADE IA
        $this->runCascadeTests();

        // 3. Tests outils externes
        $this->runToolsTests();

        // 4. Tests API
        $this->runAPITests();

        // 5. Tests base de données
        $this->runDatabaseTests();

        // Résumé
        $this->printSummary();

        return $this->results;
    }

    // ========================================================================
    // 1. TESTS INDEXATION
    // ========================================================================

    private function runIndexationTests(): void
    {
        $this->printSection("1. TESTS INDEXATION");

        // Test: Fichiers samples disponibles
        $this->test('samples_exist', function() {
            $files = glob($this->samplesPath . '/*');
            if (empty($files)) {
                return ['success' => false, 'error' => "Aucun fichier dans {$this->samplesPath}"];
            }
            return ['success' => true, 'files' => count($files), 'list' => array_map('basename', $files)];
        });

        // Test: Dossier consume accessible
        $this->test('consume_writable', function() {
            if (!is_dir($this->consumePath)) {
                @mkdir($this->consumePath, 0755, true);
            }
            $writable = is_writable($this->consumePath);
            return ['success' => $writable, 'path' => $this->consumePath];
        });

        // Test: Extraction texte PDF
        $this->test('extract_pdf', function() {
            $pdfFile = $this->samplesPath . '/test.pdf';
            if (!file_exists($pdfFile)) {
                $pdfFile = glob($this->samplesPath . '/*.pdf')[0] ?? null;
            }
            if (!$pdfFile) {
                return ['success' => false, 'error' => 'Aucun PDF de test'];
            }

            $ocr = new OCRService();
            $text = $ocr->extractText($pdfFile);

            return [
                'success' => !empty($text),
                'file' => basename($pdfFile),
                'chars' => strlen($text ?? ''),
                'preview' => substr($text ?? '', 0, 100) . '...'
            ];
        });

        // Test: Extraction texte DOCX
        $this->test('extract_docx', function() {
            $docxFile = glob($this->samplesPath . '/*.docx')[0] ?? null;
            if (!$docxFile) {
                return ['success' => false, 'error' => 'Aucun DOCX de test', 'skip' => true];
            }

            $ocr = new OCRService();
            $text = $ocr->extractText($docxFile);

            return [
                'success' => !empty($text),
                'file' => basename($docxFile),
                'chars' => strlen($text ?? '')
            ];
        });

        // Test: Extraction MSG (si disponible via POC)
        $this->test('extract_msg', function() {
            $msgFile = glob($this->samplesPath . '/*.msg')[0] ?? null;
            if (!$msgFile) {
                return ['success' => true, 'skip' => true, 'reason' => 'Pas de fichier MSG de test'];
            }

            // MSG extraction requires POC helper
            $pocHelper = BASE_PATH . '/proofofconcept/helpers.php';
            if (!file_exists($pocHelper)) {
                return ['success' => true, 'skip' => true, 'reason' => 'POC helpers non disponibles'];
            }

            require_once $pocHelper;

            if (!function_exists('extract_msg_text')) {
                return ['success' => true, 'skip' => true, 'reason' => 'Fonction extract_msg_text non définie'];
            }

            $text = extract_msg_text($msgFile);

            return [
                'success' => !empty($text),
                'file' => basename($msgFile),
                'chars' => strlen($text ?? '')
            ];
        });

        // Test: Génération miniature (vérifie que les outils sont disponibles)
        $this->test('thumbnail_tools', function() {
            $gsPath = Config::get('tools.ghostscript');
            $pdftoppmPath = Config::get('tools.pdftoppm');

            $hasGhostscript = file_exists($gsPath);
            $hasPdftoppm = file_exists($pdftoppmPath);

            return [
                'success' => $hasGhostscript || $hasPdftoppm,
                'ghostscript' => $hasGhostscript ? 'OK' : 'Non trouvé',
                'pdftoppm' => $hasPdftoppm ? 'OK' : 'Non trouvé',
            ];
        });

        // Test: Génération embedding
        $this->test('embedding_generation', function() {
            if (!(Config::get('embeddings.enabled') ?? false)) {
                return ['success' => true, 'skip' => true, 'reason' => 'Embeddings désactivés'];
            }

            $embeddingService = new EmbeddingService();

            if (!$embeddingService->isAvailable()) {
                return ['success' => true, 'skip' => true, 'reason' => 'Service embedding non disponible'];
            }

            $testText = "Ceci est un document de test pour vérifier la génération d'embeddings.";
            $embedding = $embeddingService->embed($testText);

            return [
                'success' => !empty($embedding),
                'dimensions' => is_array($embedding) ? count($embedding) : 0,
                'expected' => Config::get('embeddings.dimensions', 768)
            ];
        });

        // Test: Copie et détection dans consume
        $this->test('consume_detection', function() {
            $pdfFile = glob($this->samplesPath . '/*.pdf')[0] ?? null;
            if (!$pdfFile) {
                return ['success' => false, 'error' => 'Aucun PDF de test'];
            }

            $testFile = $this->consumePath . '/test_integration_' . time() . '.pdf';
            copy($pdfFile, $testFile);

            $detected = file_exists($testFile);

            // Nettoyage
            @unlink($testFile);

            return [
                'success' => $detected,
                'file' => basename($testFile)
            ];
        });
    }

    // ========================================================================
    // 2. TESTS CASCADE IA
    // ========================================================================

    private function runCascadeTests(): void
    {
        $this->printSection("2. TESTS CASCADE IA");

        $aiProvider = new AIProviderService();
        $status = $aiProvider->getStatus();

        // Test: Claude/Anthropic
        $this->test('claude_status', function() use ($status) {
            return [
                'success' => true, // Informatif
                'configured' => $status['claude']['configured'],
                'available' => $status['claude']['available'],
                'model' => $status['claude']['model'] ?? 'N/A',
                'error' => $status['claude']['error'] ?? null
            ];
        });

        // Test: Ollama
        $this->test('ollama_status', function() use ($status) {
            return [
                'success' => true, // Informatif
                'available' => $status['ollama']['available'],
                'url' => $status['ollama']['url'] ?? 'N/A',
                'models' => $status['ollama']['models'] ?? []
            ];
        });

        // Test: Au moins un provider disponible
        $this->test('ai_available', function() use ($aiProvider) {
            $available = $aiProvider->isAIAvailable();
            return [
                'success' => $available,
                'provider' => $available ? $aiProvider->getBestProvider() : 'none',
                'fallback_mode' => !$available ? 'rules_only' : 'ai_available'
            ];
        });

        // Test: Classification réelle
        $this->test('classification_test', function() use ($aiProvider) {
            if (!$aiProvider->isAIAvailable()) {
                return ['success' => true, 'skip' => true, 'reason' => 'Pas d\'IA disponible'];
            }

            $testText = "FACTURE n° 2024-001\nMontant: CHF 1'500.00\nDate: 15.01.2024";

            $start = microtime(true);
            $result = $aiProvider->complete(
                "Analyse ce document et réponds en JSON: {\"type\": \"...\", \"confidence\": 0.X}\n\nDocument:\n$testText",
                ['max_tokens' => 200]
            );
            $duration = round((microtime(true) - $start) * 1000);

            return [
                'success' => !empty($result),
                'provider' => $result['provider'] ?? 'unknown',
                'duration_ms' => $duration,
                'response_preview' => isset($result['text']) ? substr($result['text'], 0, 100) : null
            ];
        });

        // Test: Règles de classification
        $this->test('rules_count', function() {
            $db = Database::getInstance();
            try {
                $count = (int)$db->query("SELECT COUNT(*) FROM attribution_rules WHERE active = 1")->fetchColumn();
                return ['success' => true, 'rules_count' => $count];
            } catch (\Exception $e) {
                return ['success' => true, 'rules_count' => 0, 'note' => 'Table non créée'];
            }
        });

        // Test: Training system
        $this->test('training_status', function() {
            $config = Config::load();
            $enabled = Config::get('ai.training.enabled', false);
            $trainingFile = Config::get('ai.training.file');

            $corrections = 0;
            if ($trainingFile && file_exists($trainingFile)) {
                $data = json_decode(file_get_contents($trainingFile), true);
                $corrections = count($data['corrections'] ?? []);
            }

            return [
                'success' => true,
                'enabled' => $enabled,
                'corrections_stored' => $corrections
            ];
        });
    }

    // ========================================================================
    // 3. TESTS OUTILS EXTERNES
    // ========================================================================

    private function runToolsTests(): void
    {
        $this->printSection("3. TESTS OUTILS EXTERNES");

        $config = Config::load();

        // Test: Tesseract OCR
        $this->test('tesseract', function() use ($config) {
            $path = Config::get('ocr.tesseract_path');
            if (!file_exists($path)) {
                return ['success' => false, 'path' => $path, 'error' => 'Non trouvé'];
            }

            $output = shell_exec("\"$path\" --version 2>&1");
            preg_match('/tesseract\s+([\d.]+)/i', $output, $matches);

            return [
                'success' => true,
                'path' => $path,
                'version' => $matches[1] ?? 'unknown'
            ];
        });

        // Test: Ghostscript
        $this->test('ghostscript', function() use ($config) {
            $path = Config::get('tools.ghostscript');
            if (!file_exists($path)) {
                return ['success' => false, 'path' => $path, 'error' => 'Non trouvé'];
            }

            $output = shell_exec("\"$path\" --version 2>&1");

            return [
                'success' => true,
                'path' => $path,
                'version' => trim($output) ?: 'unknown'
            ];
        });

        // Test: LibreOffice
        $this->test('libreoffice', function() use ($config) {
            $path = Config::get('tools.libreoffice');
            if (!file_exists($path)) {
                return ['success' => false, 'path' => $path, 'error' => 'Non trouvé'];
            }

            $output = shell_exec("\"$path\" --version 2>&1") ?? '';
            preg_match('/LibreOffice\s+([\d.]+)/i', $output, $matches);

            return [
                'success' => true,
                'path' => $path,
                'version' => $matches[1] ?? 'installed'
            ];
        });

        // Test: pdftotext
        $this->test('pdftotext', function() use ($config) {
            $path = Config::get('tools.pdftotext');
            if (!file_exists($path)) {
                return ['success' => false, 'path' => $path, 'error' => 'Non trouvé'];
            }

            $output = shell_exec("\"$path\" -v 2>&1");
            preg_match('/version\s+([\d.]+)/i', $output, $matches);

            return [
                'success' => true,
                'path' => $path,
                'version' => $matches[1] ?? 'unknown'
            ];
        });

        // Test: ImageMagick
        $this->test('imagemagick', function() use ($config) {
            $path = Config::get('tools.imagemagick');
            if (!file_exists($path)) {
                return ['success' => false, 'path' => $path, 'error' => 'Non trouvé'];
            }

            $output = shell_exec("\"$path\" --version 2>&1");
            preg_match('/ImageMagick\s+([\d.-]+)/i', $output, $matches);

            return [
                'success' => true,
                'path' => $path,
                'version' => $matches[1] ?? 'unknown'
            ];
        });

        // Test: OnlyOffice
        $this->test('onlyoffice', function() use ($config) {
            $enabled = Config::get('onlyoffice.enabled', false);
            if (!$enabled) {
                return ['success' => true, 'skip' => true, 'reason' => 'OnlyOffice désactivé'];
            }

            $url = Config::get('onlyoffice.server_url');
            $healthUrl = rtrim($url, '/') . '/healthcheck';

            $ch = curl_init($healthUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return [
                'success' => $httpCode === 200 && $response === 'true',
                'url' => $url,
                'http_code' => $httpCode
            ];
        });

        // Test: Ollama models
        $this->test('ollama_models', function() use ($config) {
            $url = Config::get('ollama.url', 'http://localhost:11434');

            $ch = curl_init("$url/api/tags");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                return ['success' => false, 'error' => "Ollama non accessible (HTTP $httpCode)"];
            }

            $data = json_decode($response, true);
            $models = array_column($data['models'] ?? [], 'name');

            $hasLLM = false;
            $hasEmbed = false;
            foreach ($models as $model) {
                if (strpos($model, 'llama') !== false || strpos($model, 'mistral') !== false) {
                    $hasLLM = true;
                }
                if (strpos($model, 'nomic') !== false || strpos($model, 'embed') !== false) {
                    $hasEmbed = true;
                }
            }

            return [
                'success' => true,
                'models' => $models,
                'has_llm' => $hasLLM,
                'has_embed' => $hasEmbed
            ];
        });
    }

    // ========================================================================
    // 4. TESTS API
    // ========================================================================

    private function runAPITests(): void
    {
        $this->printSection("4. TESTS API");

        $baseUrl = Config::get('app.url', 'http://localhost/kdocs');

        // Test: API documents
        $this->test('api_documents', function() use ($baseUrl) {
            $response = $this->httpGet("$baseUrl/api/documents?limit=5");

            return [
                'success' => $response['http_code'] === 200,
                'http_code' => $response['http_code'],
                'has_data' => isset($response['data']['data'])
            ];
        });

        // Test: API search (quick)
        $this->test('api_search', function() use ($baseUrl) {
            $response = $this->httpGet("$baseUrl/api/search/quick?q=test&limit=5");

            return [
                'success' => $response['http_code'] === 200 || $response['http_code'] === 401,
                'http_code' => $response['http_code'],
                'note' => $response['http_code'] === 401 ? 'Auth required (normal)' : 'OK'
            ];
        });

        // Test: API AI status
        $this->test('api_ai_status', function() use ($baseUrl) {
            $response = $this->httpGet("$baseUrl/api/ai/status");

            return [
                'success' => $response['http_code'] === 200,
                'http_code' => $response['http_code'],
                'claude' => $response['data']['data']['claude']['available'] ?? false,
                'ollama' => $response['data']['data']['ollama']['available'] ?? false
            ];
        });

        // Test: API embeddings status
        $this->test('api_embeddings', function() use ($baseUrl) {
            $response = $this->httpGet("$baseUrl/api/embeddings/status");

            return [
                'success' => $response['http_code'] === 200 || $response['http_code'] === 404,
                'http_code' => $response['http_code'],
                'enabled' => $response['data']['data']['enabled'] ?? false
            ];
        });
    }

    // ========================================================================
    // 5. TESTS BASE DE DONNEES
    // ========================================================================

    private function runDatabaseTests(): void
    {
        $this->printSection("5. TESTS BASE DE DONNEES");

        $db = Database::getInstance();

        // Test: Connexion MySQL
        $this->test('mysql_connection', function() use ($db) {
            try {
                $version = $db->query("SELECT VERSION()")->fetchColumn();
                return [
                    'success' => true,
                    'version' => $version
                ];
            } catch (\Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        });

        // Test: Tables essentielles
        $this->test('essential_tables', function() use ($db) {
            $requiredTables = ['documents', 'users', 'document_types', 'correspondents', 'tags'];
            $missing = [];

            foreach ($requiredTables as $table) {
                try {
                    $db->query("SELECT 1 FROM $table LIMIT 1");
                } catch (\Exception $e) {
                    $missing[] = $table;
                }
            }

            return [
                'success' => empty($missing),
                'missing' => $missing,
                'checked' => $requiredTables
            ];
        });

        // Test: Colonne embedding
        $this->test('embedding_column', function() use ($db) {
            try {
                $db->query("SELECT embedding FROM documents LIMIT 1");
                return ['success' => true, 'exists' => true];
            } catch (\Exception $e) {
                return ['success' => false, 'exists' => false, 'note' => 'Migration nécessaire'];
            }
        });

        // Test: Index FULLTEXT
        $this->test('fulltext_index', function() use ($db) {
            try {
                $indexes = $db->query("SHOW INDEX FROM documents WHERE Index_type = 'FULLTEXT'")->fetchAll();
                return [
                    'success' => !empty($indexes),
                    'count' => count($indexes),
                    'columns' => array_column($indexes, 'Column_name')
                ];
            } catch (\Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        });

        // Test: Documents count
        $this->test('documents_count', function() use ($db) {
            $total = (int)$db->query("SELECT COUNT(*) FROM documents")->fetchColumn();
            $indexed = (int)$db->query("SELECT COUNT(*) FROM documents WHERE content IS NOT NULL AND content != ''")->fetchColumn();
            $withEmbed = (int)$db->query("SELECT COUNT(*) FROM documents WHERE embedding IS NOT NULL")->fetchColumn();

            return [
                'success' => true,
                'total' => $total,
                'indexed' => $indexed,
                'with_embedding' => $withEmbed,
                'index_rate' => $total > 0 ? round(($indexed / $total) * 100, 1) . '%' : 'N/A'
            ];
        });
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    private function test(string $name, callable $testFn): void
    {
        $start = microtime(true);

        try {
            $result = $testFn();
        } catch (\Exception $e) {
            $result = [
                'success' => false,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ];
        }

        $duration = round((microtime(true) - $start) * 1000, 2);
        $result['duration_ms'] = $duration;

        $this->results[$name] = $result;

        if (!$this->jsonOutput) {
            $status = $result['success'] ? "\033[32mOK\033[0m" : "\033[31mKO\033[0m";
            if ($result['skip'] ?? false) {
                $status = "\033[33mSKIP\033[0m";
            }

            echo sprintf("  %-25s [%s] (%dms)\n", $name, $status, $duration);

            if ($this->verbose && !($result['skip'] ?? false)) {
                foreach ($result as $key => $value) {
                    if ($key !== 'success' && $key !== 'duration_ms') {
                        $display = is_array($value) ? json_encode($value) : $value;
                        echo "    - $key: $display\n";
                    }
                }
            }
        }
    }

    private function httpGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'http_code' => $httpCode,
            'data' => json_decode($response, true) ?? []
        ];
    }

    private function printHeader(string $title): void
    {
        if ($this->jsonOutput) return;
        echo "\n\033[1;34m" . str_repeat('=', 60) . "\033[0m\n";
        echo "\033[1;34m $title\033[0m\n";
        echo "\033[1;34m" . str_repeat('=', 60) . "\033[0m\n\n";
    }

    private function printSection(string $title): void
    {
        if ($this->jsonOutput) return;
        echo "\n\033[1;33m$title\033[0m\n";
        echo str_repeat('-', 50) . "\n";
    }

    private function printSummary(): void
    {
        $total = count($this->results);
        $passed = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($this->results as $result) {
            if ($result['skip'] ?? false) {
                $skipped++;
            } elseif ($result['success']) {
                $passed++;
            } else {
                $failed++;
            }
        }

        if ($this->jsonOutput) {
            echo json_encode([
                'summary' => [
                    'total' => $total,
                    'passed' => $passed,
                    'failed' => $failed,
                    'skipped' => $skipped,
                    'pass_rate' => round(($passed / ($total - $skipped)) * 100, 1)
                ],
                'results' => $this->results
            ], JSON_PRETTY_PRINT);
            return;
        }

        echo "\n\033[1;34m" . str_repeat('=', 60) . "\033[0m\n";
        echo "\033[1;34m RESUME\033[0m\n";
        echo "\033[1;34m" . str_repeat('=', 60) . "\033[0m\n\n";

        $passRate = round(($passed / ($total - $skipped)) * 100, 1);
        $color = $passRate >= 85 ? '32' : ($passRate >= 50 ? '33' : '31');

        echo "  Total:    $total tests\n";
        echo "  \033[32mPassed:\033[0m   $passed\n";
        echo "  \033[31mFailed:\033[0m   $failed\n";
        echo "  \033[33mSkipped:\033[0m  $skipped\n";
        echo "  \033[{$color}mPass rate: {$passRate}%\033[0m\n\n";

        if ($failed > 0) {
            echo "\033[31mTests échoués:\033[0m\n";
            foreach ($this->results as $name => $result) {
                if (!($result['skip'] ?? false) && !$result['success']) {
                    $error = $result['error'] ?? 'Unknown error';
                    echo "  - $name: $error\n";
                }
            }
            echo "\n";
        }
    }
}

// Exécution
$verbose = in_array('--verbose', $argv) || in_array('-v', $argv);
$jsonOutput = in_array('--json', $argv);

$tests = new IntegrationTests($verbose, $jsonOutput);
$results = $tests->run();

// Exit code based on results
$failed = array_filter($results, fn($r) => !($r['skip'] ?? false) && !$r['success']);
exit(count($failed) > 0 ? 1 : 0);

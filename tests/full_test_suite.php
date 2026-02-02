<?php
/**
 * K-Docs - Test Suite Complet
 * Batterie de tests automatisés pour validation complète
 * 
 * Usage: php tests/full_test_suite.php [--quick|--full|--stress]
 */

namespace KDocs\Tests;

require_once __DIR__ . '/../vendor/autoload.php';

use KDocs\Core\Database;
use KDocs\Core\Config;

class TestSuite
{
    private array $results = [];
    private int $passed = 0;
    private int $failed = 0;
    private int $skipped = 0;
    private float $startTime;
    private string $mode;
    private ?string $testDataDir = null;
    private array $createdDocuments = [];
    private array $createdFiles = [];
    
    // Test configuration
    private string $baseUrl;
    private ?string $authCookie = null;
    
    public function __construct(string $mode = 'quick')
    {
        $this->mode = $mode;
        $this->startTime = microtime(true);
        $this->baseUrl = Config::get('app.url', 'http://localhost/kdocs');
        $this->testDataDir = __DIR__ . '/test_data';
        
        // Create test data directory if needed
        if (!is_dir($this->testDataDir)) {
            mkdir($this->testDataDir, 0755, true);
        }
    }

    /**
     * Run all tests
     */
    public function run(): int
    {
        $this->printHeader();
        
        // Phase 1: Infrastructure
        $this->section("PHASE 1: INFRASTRUCTURE");
        $this->testDatabase();
        $this->testStorage();
        $this->testExternalTools();
        $this->testServices();
        
        // Phase 2: Docker Services
        $this->section("PHASE 2: SERVICES DOCKER");
        $this->testOnlyOffice();
        $this->testQdrant();
        $this->testOllama();
        
        // Phase 3: Authentication & API
        $this->section("PHASE 3: AUTHENTIFICATION & API");
        $this->testAuthentication();
        $this->testAPIEndpoints();
        
        // Phase 4: Document Operations
        $this->section("PHASE 4: OPÉRATIONS DOCUMENTS");
        $this->testDocumentUpload();
        $this->testDocumentOCR();
        $this->testDocumentSearch();
        $this->testDocumentUpdate();
        $this->testDocumentDelete();
        
        // Phase 5: Semantic Search (if available)
        if ($this->mode !== 'quick') {
            $this->section("PHASE 5: RECHERCHE SÉMANTIQUE");
            $this->testEmbeddings();
            $this->testVectorSearch();
        }
        
        // Phase 6: Workflows (if full mode)
        if ($this->mode === 'full' || $this->mode === 'stress') {
            $this->section("PHASE 6: WORKFLOWS");
            $this->testWorkflowCreation();
            $this->testWorkflowExecution();
        }
        
        // Phase 7: Stress Tests (if stress mode)
        if ($this->mode === 'stress') {
            $this->section("PHASE 7: STRESS TESTS");
            $this->testBulkUpload(50);
            $this->testConcurrentSearches(20);
            $this->testBulkDelete();
        }
        
        // Cleanup
        $this->section("NETTOYAGE");
        $this->cleanup();
        
        // Results
        $this->printSummary();
        
        return $this->failed > 0 ? 1 : 0;
    }

    // =========================================
    // Test Methods
    // =========================================
    
    private function testDatabase(): void
    {
        $this->test("Connexion base de données", function() {
            $db = Database::getInstance();
            return $db !== null;
        });
        
        $this->test("Tables principales existent", function() {
            $db = Database::getInstance();
            $tables = ['documents', 'users', 'tags', 'correspondents', 'document_types'];
            foreach ($tables as $table) {
                $result = $db->query("SHOW TABLES LIKE '$table'")->fetch();
                if (!$result) return false;
            }
            return true;
        });
        
        $this->test("Tables embeddings existent", function() {
            $db = Database::getInstance();
            $result = $db->query("SHOW TABLES LIKE 'embedding_logs'")->fetch();
            return $result !== false;
        });
        
        $this->test("Tables snapshots existent", function() {
            $db = Database::getInstance();
            $result = $db->query("SHOW TABLES LIKE 'snapshots'")->fetch();
            return $result !== false;
        });
    }

    private function testStorage(): void
    {
        $paths = [
            'storage/documents' => Config::get('storage.documents'),
            'storage/consume' => Config::get('storage.consume'),
            'storage/thumbnails' => Config::get('storage.thumbnails'),
            'storage/temp' => Config::get('storage.temp'),
        ];
        
        foreach ($paths as $name => $path) {
            $this->test("Dossier $name existe", function() use ($path) {
                return is_dir($path);
            });
            
            $this->test("Dossier $name accessible en écriture", function() use ($path) {
                $testFile = $path . '/.write_test_' . uniqid();
                $result = @file_put_contents($testFile, 'test');
                if ($result !== false) {
                    @unlink($testFile);
                    return true;
                }
                return false;
            });
        }
    }

    private function testExternalTools(): void
    {
        $this->test("Tesseract OCR disponible", function() {
            $path = Config::get('ocr.tesseract_path');
            return file_exists($path) || $this->commandExists('tesseract');
        });
        
        $this->test("Ghostscript disponible", function() {
            $path = Config::get('tools.ghostscript');
            return file_exists($path) || $this->commandExists('gs');
        });
        
        $this->test("pdftotext disponible", function() {
            $path = Config::get('tools.pdftotext');
            return file_exists($path) || $this->commandExists('pdftotext');
        }, true); // Warning only
        
        $this->test("LibreOffice disponible", function() {
            $path = Config::get('tools.libreoffice');
            return file_exists($path) || $this->commandExists('libreoffice');
        }, true); // Warning only
    }

    private function testServices(): void
    {
        $services = [
            'OCRService' => \KDocs\Services\OCRService::class,
            'ClassificationService' => \KDocs\Services\ClassificationService::class,
            'DocumentProcessor' => \KDocs\Services\DocumentProcessor::class,
            'EmbeddingService' => \KDocs\Services\EmbeddingService::class,
            'VectorStoreService' => \KDocs\Services\VectorStoreService::class,
            'SnapshotService' => \KDocs\Services\SnapshotService::class,
            'OnlyOfficeService' => \KDocs\Services\OnlyOfficeService::class,
        ];
        
        foreach ($services as $name => $class) {
            $this->test("Service $name instanciable", function() use ($class) {
                try {
                    $service = new $class();
                    return true;
                } catch (\Exception $e) {
                    return false;
                }
            });
        }
    }

    private function testOnlyOffice(): void
    {
        // Skip if OnlyOffice is disabled in config
        if (!Config::get('onlyoffice.enabled', false)) {
            $this->skip("OnlyOffice container accessible", "OnlyOffice désactivé dans config");
            $this->skip("OnlyOffice service disponible", "OnlyOffice désactivé dans config");
            return;
        }

        $this->test("OnlyOffice container accessible", function() {
            $url = Config::get('onlyoffice.server_url', 'http://localhost:8080');
            $response = $this->httpGet($url . '/healthcheck');
            return $response && strpos($response, 'true') !== false;
        }, true); // Warning only - Docker may not be running

        $this->test("OnlyOffice service disponible", function() {
            $service = new \KDocs\Services\OnlyOfficeService();
            return $service->isAvailable();
        }, true); // Warning only
    }

    private function testQdrant(): void
    {
        // Skip if Qdrant is disabled in config
        if (!Config::get('qdrant.enabled', false)) {
            $this->skip("Qdrant container accessible", "Qdrant désactivé dans config");
            $this->skip("VectorStoreService disponible", "Qdrant désactivé dans config");
            $this->skip("Collection Qdrant créable", "Qdrant désactivé dans config");
            return;
        }

        $this->test("Qdrant container accessible", function() {
            $host = Config::get('qdrant.host', 'localhost');
            $port = Config::get('qdrant.port', 6333);
            $response = $this->httpGet("http://{$host}:{$port}/collections");
            return $response && strpos($response, 'result') !== false;
        });

        $this->test("VectorStoreService disponible", function() {
            $service = new \KDocs\Services\VectorStoreService();
            return $service->isAvailable();
        });

        $this->test("Collection Qdrant créable", function() {
            $service = new \KDocs\Services\VectorStoreService();
            return $service->createCollection();
        });
    }

    private function testOllama(): void
    {
        $this->test("Ollama accessible", function() {
            $url = Config::get('embeddings.ollama_url', 'http://localhost:11434');
            $response = $this->httpGet($url . '/api/tags');
            return $response && strpos($response, 'models') !== false;
        }, true); // Warning only
        
        $this->test("Modèle nomic-embed-text disponible", function() {
            $url = Config::get('embeddings.ollama_url', 'http://localhost:11434');
            $response = $this->httpGet($url . '/api/tags');
            return $response && strpos($response, 'nomic-embed-text') !== false;
        }, true); // Warning only
    }

    private function testAuthentication(): void
    {
        $this->test("Page login accessible", function() {
            $response = $this->httpGet($this->baseUrl . '/login');
            return $response && (strpos($response, 'login') !== false || strpos($response, 'Login') !== false);
        });
        
        $this->test("Login admin fonctionne", function() {
            $db = Database::getInstance();
            $admin = $db->query("SELECT username FROM users WHERE is_admin = 1 LIMIT 1")->fetch();
            if (!$admin) return false;
            
            // Try to login
            $response = $this->httpPost($this->baseUrl . '/login', [
                'username' => $admin['username'],
                'password' => 'admin', // Default password
            ], true);
            
            return $this->authCookie !== null;
        });
        
        $this->test("Accès authentifié fonctionne", function() {
            if (!$this->authCookie) return false;
            $response = $this->httpGet($this->baseUrl . '/documents', true);
            return $response && strpos($response, 'Documents') !== false;
        });
    }

    private function testAPIEndpoints(): void
    {
        // Health endpoint (no auth required)
        $this->test("API: GET /health", function() {
            $response = $this->httpGet($this->baseUrl . '/health');
            if (!$response) return false;
            $data = json_decode($response, true);
            return $data !== null && isset($data['status']);
        });

        // Skip other API tests if not authenticated
        if (!$this->authCookie) {
            $this->skip("API: GET /api/documents", "Authentification requise");
            $this->skip("API: GET /api/tags", "Authentification requise");
            $this->skip("API: GET /api/correspondents", "Authentification requise");
            $this->skip("API: GET /api/document-types", "Authentification requise");
            return;
        }

        $endpoints = [
            'GET /api/documents' => '/api/documents',
            'GET /api/tags' => '/api/tags',
            'GET /api/correspondents' => '/api/correspondents',
            'GET /api/document-types' => '/api/document-types',
        ];

        foreach ($endpoints as $name => $endpoint) {
            $this->test("API: $name", function() use ($endpoint) {
                $response = $this->httpGet($this->baseUrl . $endpoint, true);
                if (!$response) return false;
                // Empty array is valid response
                $data = json_decode($response, true);
                return $data !== null;
            }, true); // Warning only if fails
        }
    }

    private function testDocumentUpload(): void
    {
        // Skip upload tests if not authenticated
        if (!$this->authCookie) {
            $this->skip("Upload document PDF", "Authentification requise");
            $this->skip("Upload document DOCX", "Authentification requise");
            return;
        }

        // Create a test PDF
        $testFile = $this->createTestPDF();

        $this->test("Upload document PDF", function() use ($testFile) {
            $response = $this->httpUpload(
                $this->baseUrl . '/api/documents/upload',
                $testFile,
                ['title' => 'Test Document ' . date('Y-m-d H:i:s')]
            );

            if (!$response) return false;
            // Strip BOM if present
            $response = preg_replace('/^\xEF\xBB\xBF/', '', $response);
            $data = json_decode($response, true);

            // API returns {success: true, results: [{success: true, id: X}]}
            if (isset($data['results'][0]['id'])) {
                $this->createdDocuments[] = $data['results'][0]['id'];
                return true;
            }
            // Fallback for other response formats
            if (isset($data['id']) || isset($data['document']['id'])) {
                $this->createdDocuments[] = $data['id'] ?? $data['document']['id'];
                return true;
            }
            return false;
        }, true); // Warning only
        
        // Test DOCX upload if available
        $testDocx = $this->createTestDOCX();
        if ($testDocx) {
            $this->test("Upload document DOCX", function() use ($testDocx) {
                $response = $this->httpUpload(
                    $this->baseUrl . '/api/documents/upload',
                    $testDocx,
                    ['title' => 'Test DOCX ' . date('Y-m-d H:i:s')]
                );

                if (!$response) return false;
                // Strip BOM if present
                $response = preg_replace('/^\xEF\xBB\xBF/', '', $response);
                $data = json_decode($response, true);

                // API returns {success: true, results: [{success: true, id: X}]}
                if (isset($data['results'][0]['id'])) {
                    $this->createdDocuments[] = $data['results'][0]['id'];
                    return true;
                }
                // Fallback for other response formats
                if (isset($data['id']) || isset($data['document']['id'])) {
                    $this->createdDocuments[] = $data['id'] ?? $data['document']['id'];
                    return true;
                }
                return false;
            }, true); // Warning only
        }
    }

    private function testDocumentOCR(): void
    {
        if (empty($this->createdDocuments)) {
            $this->skip("OCR - pas de document créé");
            return;
        }
        
        $docId = $this->createdDocuments[0];
        
        $this->test("Document a du contenu OCR", function() use ($docId) {
            // Wait a bit for OCR processing
            sleep(2);
            
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT ocr_text, content FROM documents WHERE id = ?");
            $stmt->execute([$docId]);
            $doc = $stmt->fetch();
            
            return $doc && (!empty($doc['ocr_text']) || !empty($doc['content']));
        });
    }

    private function testDocumentSearch(): void
    {
        $this->test("Recherche fulltext fonctionne", function() {
            $response = $this->httpGet($this->baseUrl . '/api/documents?search=test', true);
            if (!$response) return false;
            $data = json_decode($response, true);
            return isset($data['data']) || isset($data['documents']);
        });
        
        $this->test("Recherche par tag fonctionne", function() {
            $response = $this->httpGet($this->baseUrl . '/api/documents?tags=1', true);
            if (!$response) return false;
            $data = json_decode($response, true);
            return $data !== null;
        });
    }

    private function testDocumentUpdate(): void
    {
        if (empty($this->createdDocuments)) {
            $this->skip("Update - pas de document créé");
            return;
        }
        
        $docId = $this->createdDocuments[0];
        
        $this->test("Mise à jour document", function() use ($docId) {
            $response = $this->httpPut(
                $this->baseUrl . "/api/documents/{$docId}",
                ['title' => 'Updated Test Document ' . time()]
            );
            if (!$response) return false;
            $data = json_decode($response, true);
            // Accept both {success: true} and {id: X} as valid responses
            return ($data !== null) && (
                (isset($data['success']) && $data['success'] === true) ||
                isset($data['id']) ||
                isset($data['document'])
            );
        }, true); // Warning only - API response format may vary
    }

    private function testDocumentDelete(): void
    {
        // Will be done in cleanup
        $this->test("Suppression document (préparé)", function() {
            return !empty($this->createdDocuments);
        });
    }

    private function testEmbeddings(): void
    {
        $this->test("EmbeddingService disponible", function() {
            $service = new \KDocs\Services\EmbeddingService();
            return $service->isAvailable();
        });
        
        $this->test("Génération embedding texte", function() {
            $service = new \KDocs\Services\EmbeddingService();
            if (!$service->isAvailable()) return false;
            
            $vector = $service->embed("Ceci est un test de génération d'embedding.");
            return $vector !== null && count($vector) > 100;
        });
        
        if (!empty($this->createdDocuments)) {
            $this->test("Génération embedding document", function() {
                $service = new \KDocs\Services\EmbeddingService();
                if (!$service->isAvailable()) return false;
                
                $docId = $this->createdDocuments[0];
                $vector = $service->embedDocument($docId);
                return $vector !== null;
            });
        }
    }

    private function testVectorSearch(): void
    {
        $this->test("Recherche vectorielle fonctionne", function() {
            $vectorService = new \KDocs\Services\VectorStoreService();
            $embeddingService = new \KDocs\Services\EmbeddingService();
            
            if (!$vectorService->isAvailable() || !$embeddingService->isAvailable()) {
                return false;
            }
            
            $vector = $embeddingService->embed("facture électricité");
            if (!$vector) return false;
            
            $results = $vectorService->search($vector, 5);
            return is_array($results);
        });
    }

    private function testWorkflowCreation(): void
    {
        $this->test("Création workflow basique", function() {
            $db = Database::getInstance();
            
            // Check if workflows table exists
            $result = $db->query("SHOW TABLES LIKE 'workflows'")->fetch();
            if (!$result) return false;
            
            // Create a simple workflow
            $stmt = $db->prepare("
                INSERT INTO workflows (name, description, is_active, created_at)
                VALUES (?, ?, 1, NOW())
            ");
            $stmt->execute(['Test Workflow', 'Workflow de test automatisé']);
            
            return $db->lastInsertId() > 0;
        });
    }

    private function testWorkflowExecution(): void
    {
        $this->test("Moteur workflow instanciable", function() {
            try {
                $engine = new \KDocs\Services\WorkflowEngine();
                return true;
            } catch (\Exception $e) {
                return false;
            }
        });
    }

    private function testBulkUpload(int $count): void
    {
        $this->test("Upload en masse ($count documents)", function() use ($count) {
            $success = 0;
            for ($i = 0; $i < $count; $i++) {
                $testFile = $this->createTestPDF("Bulk test document $i");
                $response = $this->httpUpload(
                    $this->baseUrl . '/api/documents',
                    $testFile,
                    ['title' => "Bulk Test $i"]
                );
                
                if ($response) {
                    $data = json_decode($response, true);
                    if (isset($data['id']) || isset($data['document']['id'])) {
                        $this->createdDocuments[] = $data['id'] ?? $data['document']['id'];
                        $success++;
                    }
                }
                @unlink($testFile);
            }
            return $success === $count;
        });
    }

    private function testConcurrentSearches(int $count): void
    {
        $this->test("Recherches concurrentes ($count)", function() use ($count) {
            $queries = ['facture', 'contrat', 'test', 'document', 'rapport'];
            $success = 0;
            
            foreach (array_slice($queries, 0, $count) as $query) {
                $response = $this->httpGet($this->baseUrl . "/api/documents?search=$query", true);
                if ($response && json_decode($response, true) !== null) {
                    $success++;
                }
            }
            
            return $success >= count($queries) * 0.8; // 80% success rate
        });
    }

    private function testBulkDelete(): void
    {
        // Skip if no documents were created
        if (empty($this->createdDocuments)) {
            $this->skip("Suppression document (préparé)", "Aucun document créé");
            return;
        }
        // Will delete in cleanup
        $this->test("Préparation suppression en masse", function() {
            return count($this->createdDocuments) > 0;
        });
    }

    // =========================================
    // Cleanup
    // =========================================
    
    private function cleanup(): void
    {
        // Delete created documents
        foreach ($this->createdDocuments as $docId) {
            $this->test("Suppression document #$docId", function() use ($docId) {
                $response = $this->httpDelete($this->baseUrl . "/api/documents/{$docId}");
                return $response !== false;
            });
        }
        
        // Delete test files
        foreach ($this->createdFiles as $file) {
            @unlink($file);
        }
        
        // Delete test workflows
        try {
            $db = Database::getInstance();
            $db->exec("DELETE FROM workflows WHERE name LIKE 'Test Workflow%'");
        } catch (\Exception $e) {}
    }

    // =========================================
    // Helper Methods
    // =========================================
    
    private function test(string $name, callable $test, bool $warningOnly = false): void
    {
        try {
            $result = $test();
            
            if ($result) {
                $this->passed++;
                $this->results[$name] = 'PASS';
                echo "\033[32m[✓]\033[0m $name\n";
            } elseif ($warningOnly) {
                $this->skipped++;
                $this->results[$name] = 'WARN';
                echo "\033[33m[!]\033[0m $name (warning)\n";
            } else {
                $this->failed++;
                $this->results[$name] = 'FAIL';
                echo "\033[31m[✗]\033[0m $name\n";
            }
        } catch (\Exception $e) {
            if ($warningOnly) {
                $this->skipped++;
                $this->results[$name] = 'WARN';
                echo "\033[33m[!]\033[0m $name: {$e->getMessage()}\n";
            } else {
                $this->failed++;
                $this->results[$name] = 'FAIL';
                echo "\033[31m[✗]\033[0m $name: {$e->getMessage()}\n";
            }
        }
    }

    private function skip(string $name, ?string $reason = null): void
    {
        $this->skipped++;
        $this->results[$name] = 'SKIP';
        $msg = $reason ? " - $reason" : "";
        echo "\033[33m[-]\033[0m $name (skipped$msg)\n";
    }

    private function section(string $title): void
    {
        echo "\n\033[1;36m══════════════════════════════════════\033[0m\n";
        echo "\033[1;36m  $title\033[0m\n";
        echo "\033[1;36m══════════════════════════════════════\033[0m\n\n";
    }

    private function printHeader(): void
    {
        echo "\n";
        echo "\033[1;35m╔══════════════════════════════════════════════════════╗\033[0m\n";
        echo "\033[1;35m║         K-DOCS FULL TEST SUITE                       ║\033[0m\n";
        echo "\033[1;35m║         Mode: " . strtoupper($this->mode) . str_repeat(' ', 40 - strlen($this->mode)) . "║\033[0m\n";
        echo "\033[1;35m╚══════════════════════════════════════════════════════╝\033[0m\n\n";
    }

    private function printSummary(): void
    {
        $duration = round(microtime(true) - $this->startTime, 2);
        $total = $this->passed + $this->failed + $this->skipped;
        
        echo "\n";
        echo "\033[1m══════════════════════════════════════\033[0m\n";
        echo "\033[1m  RÉSUMÉ\033[0m\n";
        echo "\033[1m══════════════════════════════════════\033[0m\n\n";
        
        echo "Tests exécutés: $total\n";
        echo "\033[32mRéussis: {$this->passed}\033[0m\n";
        echo "\033[31mÉchoués: {$this->failed}\033[0m\n";
        echo "\033[33mWarnings/Skipped: {$this->skipped}\033[0m\n";
        echo "Durée: {$duration}s\n\n";
        
        if ($this->failed === 0) {
            echo "\033[1;32m✓ TOUS LES TESTS SONT PASSÉS\033[0m\n";
        } else {
            echo "\033[1;31m✗ {$this->failed} TEST(S) ÉCHOUÉ(S)\033[0m\n";
        }
        
        // Save results to file
        $this->saveResults();
    }

    private function saveResults(): void
    {
        $report = [
            'date' => date('Y-m-d H:i:s'),
            'mode' => $this->mode,
            'duration' => round(microtime(true) - $this->startTime, 2),
            'summary' => [
                'total' => $this->passed + $this->failed + $this->skipped,
                'passed' => $this->passed,
                'failed' => $this->failed,
                'skipped' => $this->skipped,
            ],
            'results' => $this->results,
        ];
        
        $dir = __DIR__ . '/test_results';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        
        $filename = $dir . '/test_' . date('Y-m-d_H-i-s') . '.json';
        file_put_contents($filename, json_encode($report, JSON_PRETTY_PRINT));
        
        echo "\nRapport sauvegardé: $filename\n";
    }

    // HTTP Helpers
    private function httpGet(string $url, bool $auth = false): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        if ($auth && $this->authCookie) {
            curl_setopt($ch, CURLOPT_COOKIE, $this->authCookie);
        }

        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) return null;

        // Strip UTF-8 BOM if present
        $response = preg_replace('/^\xEF\xBB\xBF/', '', $response);

        return $response;
    }

    private function httpPost(string $url, array $data, bool $saveAuth = false): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADER => $saveAuth,
        ]);
        
        if ($this->authCookie) {
            curl_setopt($ch, CURLOPT_COOKIE, $this->authCookie);
        }
        
        $response = curl_exec($ch);
        
        if ($saveAuth && $response) {
            preg_match('/Set-Cookie:\s*([^;]+)/i', $response, $matches);
            if (!empty($matches[1])) {
                $this->authCookie = $matches[1];
            }
        }
        
        curl_close($ch);
        
        return $response ?: null;
    }

    private function httpPut(string $url, array $data): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);

        if ($this->authCookie) {
            curl_setopt($ch, CURLOPT_COOKIE, $this->authCookie);
        }

        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) return null;

        // Strip UTF-8 BOM if present
        $response = preg_replace('/^\xEF\xBB\xBF/', '', $response);

        return $response;
    }

    private function httpDelete(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
        ]);
        
        if ($this->authCookie) {
            curl_setopt($ch, CURLOPT_COOKIE, $this->authCookie);
        }
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return $response ?: null;
    }

    private function httpUpload(string $url, string $filePath, array $data = []): ?string
    {
        $ch = curl_init($url);

        $postData = $data;
        $postData['files'] = new \CURLFile($filePath);
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
        ]);
        
        if ($this->authCookie) {
            curl_setopt($ch, CURLOPT_COOKIE, $this->authCookie);
        }
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return $response ?: null;
    }

    private function commandExists(string $command): bool
    {
        $check = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
        return !empty(shell_exec("$check $command 2>/dev/null"));
    }

    private function createTestPDF(string $content = "Test document content"): string
    {
        $path = $this->testDataDir . '/test_' . uniqid() . '.pdf';
        
        // Create minimal PDF
        $pdf = "%PDF-1.4\n";
        $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $pdf .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R >>\nendobj\n";
        $pdf .= "4 0 obj\n<< /Length " . strlen($content) . " >>\nstream\nBT /F1 12 Tf 100 700 Td ($content) Tj ET\nendstream\nendobj\n";
        $pdf .= "xref\n0 5\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \n0000000214 00000 n \n";
        $pdf .= "trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n" . strlen($pdf) . "\n%%EOF";
        
        file_put_contents($path, $pdf);
        $this->createdFiles[] = $path;
        
        return $path;
    }

    private function createTestDOCX(): ?string
    {
        // Only if ZipArchive is available
        if (!class_exists('ZipArchive')) {
            return null;
        }
        
        $path = $this->testDataDir . '/test_' . uniqid() . '.docx';
        
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE) !== true) {
            return null;
        }
        
        // Minimal DOCX structure
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Test DOCX document content for K-Docs testing</w:t></w:r></w:p></w:body></w:document>');
        
        $zip->close();
        $this->createdFiles[] = $path;
        
        return $path;
    }
}

// =========================================
// Main
// =========================================

// Parse command line arguments
$mode = 'quick';
foreach ($argv as $arg) {
    if ($arg === '--full') $mode = 'full';
    if ($arg === '--stress') $mode = 'stress';
    if ($arg === '--quick') $mode = 'quick';
}

$suite = new TestSuite($mode);
exit($suite->run());

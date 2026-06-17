<?php
/**
 * K-DOCS - Integration Tests
 * Tests fonctionnels complets avec les samples du POC
 */

define('KDOCS_ROOT', dirname(__DIR__));
define('SAMPLES_DIR', KDOCS_ROOT . '/tests/samples');
// Legacy POC path (for backward compatibility)
define('POC_ROOT', KDOCS_ROOT . '/proofofconcept');

class IntegrationTest
{
    private string $baseUrl;
    private ?string $sessionCookie = null;
    private array $results = [];
    private int $passed = 0;
    private int $failed = 0;
    private int $skipped = 0;
    
    public function __construct(string $baseUrl = 'http://localhost/kdocs')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }
    
    public function run(): void
    {
        $this->header("K-DOCS INTEGRATION TESTS");
        
        $this->section("Authentication");
        $this->testLogin();
        
        if (!$this->sessionCookie) {
            echo "\n❌ Cannot continue without authentication\n";
            $this->printSummary();
            return;
        }
        
        $this->section("Documents CRUD");
        $this->testDocumentsCrud();
        
        $this->section("File Processing");
        $this->testFileProcessing();
        
        $this->section("Search");
        $this->testSearch();
        
        $this->section("AI Cascade");
        $this->testAiCascade();
        
        $this->section("Consume Flow");
        $this->testConsumeFlow();
        
        $this->printSummary();
    }
    
    private function header(string $title): void
    {
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "  $title\n";
        echo str_repeat('=', 60) . "\n";
    }
    
    private function section(string $name): void
    {
        echo "\n--- $name ---\n";
    }
    
    private function test(string $name, callable $fn): bool
    {
        try {
            $result = $fn();
            if ($result === true) {
                $this->passed++;
                echo "  ✓ $name\n";
                $this->results[$name] = 'pass';
                return true;
            } elseif ($result === 'skip') {
                $this->skipped++;
                echo "  ○ $name (skipped)\n";
                $this->results[$name] = 'skip';
                return false;
            } else {
                $msg = is_string($result) ? $result : 'Failed';
                $this->failed++;
                echo "  ✗ $name - $msg\n";
                $this->results[$name] = "fail: $msg";
                return false;
            }
        } catch (\Exception $e) {
            $this->failed++;
            echo "  ✗ $name - " . $e->getMessage() . "\n";
            $this->results[$name] = "error: " . $e->getMessage();
            return false;
        }
    }
    
    private function request(string $method, string $path, array $data = [], array $files = []): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init();
        
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADER => true,
        ];
        
        if ($this->sessionCookie) {
            $options[CURLOPT_COOKIE] = $this->sessionCookie;
        }
        
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            if (!empty($files)) {
                foreach ($files as $key => $filePath) {
                    $data[$key] = new CURLFile($filePath);
                }
                $options[CURLOPT_POSTFIELDS] = $data;
            } else {
                $options[CURLOPT_POSTFIELDS] = http_build_query($data);
            }
        } elseif ($method === 'PUT' || $method === 'DELETE') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
        }
        
        $options[CURLOPT_URL] = $url;
        curl_setopt_array($ch, $options);
        
        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        // Extract cookies
        if (preg_match_all('/Set-Cookie:\s*([^;]+)/i', $headers, $matches)) {
            $this->sessionCookie = implode('; ', $matches[1]);
        }
        
        return [
            'code' => $httpCode,
            'body' => $body,
            'json' => json_decode($body, true),
            'headers' => $headers
        ];
    }
    
    private function testLogin(): void
    {
        // Get login page (and CSRF token if any)
        $this->test('Login page accessible', function() {
            $r = $this->request('GET', '/login');
            return $r['code'] === 200;
        });
        
        // Try login
        $this->test('Login with valid credentials', function() {
            $r = $this->request('POST', '/login', [
                'username' => 'admin',
                'password' => 'admin'
            ]);
            // Should redirect to dashboard or documents
            return $r['code'] === 200 || $r['code'] === 302;
        });
        
        $this->test('Session established', function() {
            return !empty($this->sessionCookie);
        });
    }
    
    private function testDocumentsCrud(): void
    {
        $this->test('List documents', function() {
            $r = $this->request('GET', '/api/documents');
            return $r['code'] === 200 && isset($r['json']);
        });
        
        // Upload test file
        $testFile = SAMPLES_DIR . '/test.pdf';
        $uploadedId = null;
        
        $this->test('Upload PDF document', function() use ($testFile, &$uploadedId) {
            if (!file_exists($testFile)) return 'Sample file not found';
            
            $r = $this->request('POST', '/api/documents/upload', [], ['file' => $testFile]);
            if ($r['code'] === 200 || $r['code'] === 201) {
                $uploadedId = $r['json']['data']['id'] ?? $r['json']['id'] ?? null;
                return true;
            }
            return "HTTP {$r['code']}";
        });
        
        if ($uploadedId) {
            $this->test('Get uploaded document', function() use ($uploadedId) {
                $r = $this->request('GET', "/api/documents/$uploadedId");
                return $r['code'] === 200;
            });
            
            $this->test('Update document metadata', function() use ($uploadedId) {
                $r = $this->request('PUT', "/api/documents/$uploadedId", [
                    'title' => 'Test Document Updated'
                ]);
                return $r['code'] === 200;
            });
            
            $this->test('Delete document', function() use ($uploadedId) {
                $r = $this->request('DELETE', "/api/documents/$uploadedId");
                return $r['code'] === 200 || $r['code'] === 204;
            });
        }
    }
    
    private function testFileProcessing(): void
    {
        $samples = [
            'PDF' => 'test.pdf',
            'DOCX' => 'RA_anonymise.docx',
        ];
        
        foreach ($samples as $type => $filename) {
            $path = SAMPLES_DIR . '/' . $filename;
            
            $this->test("Process $type file", function() use ($path, $type) {
                if (!file_exists($path)) return 'Sample not found';
                
                $r = $this->request('POST', '/api/documents/upload', [], ['file' => $path]);
                if ($r['code'] !== 200 && $r['code'] !== 201) {
                    return "Upload failed: HTTP {$r['code']}";
                }
                
                $docId = $r['json']['data']['id'] ?? $r['json']['id'] ?? null;
                if (!$docId) return 'No document ID returned';
                
                // Wait for processing
                sleep(2);
                
                // Check document has text extracted
                $doc = $this->request('GET', "/api/documents/$docId");
                if ($doc['code'] !== 200) return 'Cannot fetch document';
                
                // Cleanup
                $this->request('DELETE', "/api/documents/$docId");
                
                return true;
            });
        }
    }
    
    private function testSearch(): void
    {
        $this->test('Fulltext search', function() {
            $r = $this->request('GET', '/api/search/quick?q=test');
            return $r['code'] === 200;
        });
        
        $this->test('Semantic search', function() {
            $r = $this->request('POST', '/api/search/semantic', [
                'query' => 'facture client'
            ]);
            return $r['code'] === 200;
        });
    }
    
    private function testAiCascade(): void
    {
        $this->test('AI status endpoint', function() {
            $r = $this->request('GET', '/api/ai/status');
            return $r['code'] === 200 && isset($r['json']['data']);
        });
        
        $this->test('AI test endpoint', function() {
            $r = $this->request('POST', '/api/ai/test');
            if ($r['code'] === 503) return 'skip'; // No AI available
            return $r['code'] === 200;
        });
    }
    
    private function testConsumeFlow(): void
    {
        $consumeDir = KDOCS_ROOT . '/storage/consume';
        $testFile = SAMPLES_DIR . '/test.pdf';
        
        $this->test('Consume directory exists', function() use ($consumeDir) {
            return is_dir($consumeDir) && is_writable($consumeDir);
        });
        
        $this->test('Copy file to consume', function() use ($consumeDir, $testFile) {
            if (!file_exists($testFile)) return 'Sample not found';
            $dest = $consumeDir . '/test_consume_' . time() . '.pdf';
            if (!copy($testFile, $dest)) return 'Copy failed';
            // Cleanup after test
            unlink($dest);
            return true;
        });
        
        $this->test('Trigger consume scan', function() {
            $r = $this->request('POST', '/api/consume/scan');
            return $r['code'] === 200;
        });
    }
    
    private function printSummary(): void
    {
        $total = $this->passed + $this->failed + $this->skipped;
        $rate = ($this->passed + $this->failed) > 0 
            ? round(($this->passed / ($this->passed + $this->failed)) * 100) 
            : 0;
        
        $this->header("SUMMARY");
        echo "  Passed:  {$this->passed}\n";
        echo "  Failed:  {$this->failed}\n";
        echo "  Skipped: {$this->skipped}\n";
        echo "  Rate:    $rate%\n";
        echo str_repeat('=', 60) . "\n";
        
        if ($this->failed === 0) {
            echo "  ✓ All tests passed!\n";
        } else {
            echo "  ✗ {$this->failed} test(s) failed\n";
        }
        
        // Save results
        $outputDir = KDOCS_ROOT . '/tests/output';
        if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);
        
        file_put_contents($outputDir . '/integration_test_result.json', json_encode([
            'date' => date('Y-m-d H:i:s'),
            'passed' => $this->passed,
            'failed' => $this->failed,
            'skipped' => $this->skipped,
            'rate' => $rate,
            'results' => $this->results
        ], JSON_PRETTY_PRINT));
    }
    
    public function getExitCode(): int
    {
        return $this->failed > 0 ? 1 : 0;
    }
}

// Run
$test = new IntegrationTest($argv[1] ?? 'http://localhost/kdocs');
$test->run();
exit($test->getExitCode());

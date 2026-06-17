<?php
/**
 * K-DOCS - API Tests
 * Tests de tous les endpoints REST
 */

define('KDOCS_ROOT', dirname(__DIR__));

class ApiTest
{
    private string $baseUrl;
    private ?string $authCookie = null;
    private array $results = [];
    private int $passed = 0;
    private int $failed = 0;
    
    public function __construct(string $baseUrl = 'http://localhost/kdocs')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }
    
    public function run(): void
    {
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "  K-DOCS API TESTS\n";
        echo str_repeat('=', 60) . "\n";
        
        // Login first
        $this->authenticate();
        
        if (!$this->authCookie) {
            echo "\n❌ Authentication failed, cannot continue\n";
            return;
        }
        
        $this->testDocumentsApi();
        $this->testTagsApi();
        $this->testCorrespondentsApi();
        $this->testDocumentTypesApi();
        $this->testSearchApi();
        $this->testAiApi();
        $this->testValidationApi();
        $this->testEmbeddingsApi();
        
        $this->printSummary();
    }
    
    private function authenticate(): void
    {
        echo "\n--- Authentication ---\n";
        $r = $this->request('POST', '/login', [
            'username' => 'admin',
            'password' => 'admin'
        ]);
        
        if ($this->authCookie) {
            echo "  ✓ Authenticated\n";
        } else {
            echo "  ✗ Authentication failed\n";
        }
    }
    
    private function request(string $method, string $path, array $data = []): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        
        if ($this->authCookie) {
            $opts[CURLOPT_COOKIE] = $this->authCookie;
        }
        
        $headers = ['Accept: application/json'];
        
        switch ($method) {
            case 'POST':
                $opts[CURLOPT_POST] = true;
                if (!empty($data)) {
                    $opts[CURLOPT_POSTFIELDS] = json_encode($data);
                    $headers[] = 'Content-Type: application/json';
                }
                break;
            case 'PUT':
            case 'PATCH':
            case 'DELETE':
                $opts[CURLOPT_CUSTOMREQUEST] = $method;
                if (!empty($data)) {
                    $opts[CURLOPT_POSTFIELDS] = json_encode($data);
                    $headers[] = 'Content-Type: application/json';
                }
                break;
        }
        
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        
        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $headerStr = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        // Extract session cookie
        if (preg_match_all('/Set-Cookie:\s*([^;]+)/i', $headerStr, $m)) {
            $this->authCookie = implode('; ', $m[1]);
        }
        
        return [
            'code' => $httpCode,
            'body' => $body,
            'json' => json_decode($body, true)
        ];
    }
    
    private function test(string $name, string $method, string $path, array $data = [], array $expected = [200]): void
    {
        $r = $this->request($method, $path, $data);
        
        if (in_array($r['code'], $expected)) {
            $this->passed++;
            echo "  ✓ $method $path [{$r['code']}]\n";
            $this->results[$name] = 'pass';
        } else {
            $this->failed++;
            $exp = implode('/', $expected);
            echo "  ✗ $method $path [{$r['code']}] expected [$exp]\n";
            $this->results[$name] = "fail: HTTP {$r['code']}";
        }
    }
    
    private function testDocumentsApi(): void
    {
        echo "\n--- Documents API ---\n";
        $this->test('List documents', 'GET', '/api/documents');
        $this->test('Get document (may 404)', 'GET', '/api/documents/1', [], [200, 404]);
    }
    
    private function testTagsApi(): void
    {
        echo "\n--- Tags API ---\n";
        $this->test('List tags', 'GET', '/api/tags');
    }
    
    private function testCorrespondentsApi(): void
    {
        echo "\n--- Correspondents API ---\n";
        $this->test('List correspondents', 'GET', '/api/correspondents');
    }
    
    private function testDocumentTypesApi(): void
    {
        echo "\n--- Document Types API ---\n";
        $this->test('List document types', 'GET', '/api/document-types');
    }
    
    private function testSearchApi(): void
    {
        echo "\n--- Search API ---\n";
        $this->test('Quick search', 'GET', '/api/search/quick?q=test');
        $this->test('Semantic search', 'POST', '/api/search/semantic', ['query' => 'facture']);
        $this->test('Hybrid search', 'POST', '/api/search/hybrid', ['query' => 'contrat']);
    }
    
    private function testAiApi(): void
    {
        echo "\n--- AI API ---\n";
        $this->test('AI status', 'GET', '/api/ai/status');
        $this->test('AI test', 'POST', '/api/ai/test', [], [200, 503]);
        $this->test('AI refresh', 'POST', '/api/ai/refresh');
    }
    
    private function testValidationApi(): void
    {
        echo "\n--- Validation API ---\n";
        $this->test('Pending validations', 'GET', '/api/validation/pending');
        $this->test('Validation statistics', 'GET', '/api/validation/statistics');
    }
    
    private function testEmbeddingsApi(): void
    {
        echo "\n--- Embeddings API ---\n";
        $this->test('Embeddings status', 'GET', '/api/embeddings/status');
    }
    
    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $rate = $total > 0 ? round(($this->passed / $total) * 100) : 0;
        
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "  SUMMARY: {$this->passed}/$total ($rate%)\n";
        echo str_repeat('=', 60) . "\n";
        
        // Save
        $outputDir = KDOCS_ROOT . '/tests/output';
        if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);
        
        file_put_contents($outputDir . '/api_test_result.json', json_encode([
            'date' => date('Y-m-d H:i:s'),
            'passed' => $this->passed,
            'failed' => $this->failed,
            'rate' => $rate,
            'results' => $this->results
        ], JSON_PRETTY_PRINT));
    }
    
    public function getExitCode(): int
    {
        return $this->failed > 0 ? 1 : 0;
    }
}

$test = new ApiTest($argv[1] ?? 'http://localhost/kdocs');
$test->run();
exit($test->getExitCode());

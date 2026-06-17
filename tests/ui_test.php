<?php
/**
 * K-DOCS - UI Tests
 * Tests des pages avec détection d'erreurs PHP et capture d'écran
 */

define('KDOCS_ROOT', dirname(__DIR__));
define('OUTPUT_DIR', __DIR__ . '/output/screenshots');

class UiTest
{
    private string $baseUrl;
    private ?string $authCookie = null;
    private array $results = [];
    private int $passed = 0;
    private int $failed = 0;
    private bool $takeScreenshots;
    
    private array $pages = [
        '/login' => ['auth' => false, 'title' => 'Login'],
        '/' => ['auth' => true, 'title' => 'Dashboard'],
        '/dashboard' => ['auth' => true, 'title' => 'Dashboard'],
        '/documents' => ['auth' => true, 'title' => 'Documents'],
        '/mes-taches' => ['auth' => true, 'title' => 'Mes Tâches'],
        '/chat' => ['auth' => true, 'title' => 'Chat'],
        '/admin/settings' => ['auth' => true, 'title' => 'Paramètres'],
        '/admin/users' => ['auth' => true, 'title' => 'Utilisateurs'],
        '/admin/tags' => ['auth' => true, 'title' => 'Étiquettes'],
        '/admin/document-types' => ['auth' => true, 'title' => 'Types'],
        '/admin/correspondents' => ['auth' => true, 'title' => 'Correspondants'],
        '/admin/workflows' => ['auth' => true, 'title' => 'Workflows'],
        '/admin/consume' => ['auth' => true, 'title' => 'Validation'],
        '/admin/storage-paths' => ['auth' => true, 'title' => 'Chemins'],
        '/admin/custom-fields' => ['auth' => true, 'title' => 'Champs'],
        '/admin/webhooks' => ['auth' => true, 'title' => 'Webhooks'],
        '/admin/audit-logs' => ['auth' => true, 'title' => 'Journaux'],
        '/admin/export-import' => ['auth' => true, 'title' => 'Export'],
    ];
    
    // Patterns d'erreurs PHP à détecter
    private array $errorPatterns = [
        '/Fatal error/i',
        '/Parse error/i',
        '/Warning:/i',
        '/Notice:/i',
        '/Deprecated:/i',
        '/Exception:/i',
        '/Stack trace:/i',
        '/PDOException/i',
        '/TypeError/i',
        '/Error:/i',
        '/Undefined variable/i',
        '/Undefined index/i',
        '/Call to undefined/i',
    ];
    
    public function __construct(string $baseUrl = 'http://localhost/kdocs', bool $takeScreenshots = false)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->takeScreenshots = $takeScreenshots;
        
        if ($takeScreenshots && !is_dir(OUTPUT_DIR)) {
            mkdir(OUTPUT_DIR, 0755, true);
        }
    }
    
    public function run(): void
    {
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "  K-DOCS UI TESTS\n";
        echo str_repeat('=', 60) . "\n";
        
        // Login first
        $this->authenticate();
        
        if (!$this->authCookie) {
            echo "\n❌ Authentication failed\n";
        }
        
        echo "\n--- Testing Pages ---\n";
        
        foreach ($this->pages as $path => $config) {
            if ($config['auth'] && !$this->authCookie) {
                $this->skip($path, 'Requires auth');
                continue;
            }
            
            $this->testPage($path, $config);
        }
        
        $this->printSummary();
    }
    
    private function authenticate(): void
    {
        echo "\n--- Authentication ---\n";
        
        // GET login page
        $this->request('GET', '/login');
        
        // POST login
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
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
        ];
        
        if ($this->authCookie) {
            $opts[CURLOPT_COOKIE] = $this->authCookie;
        }
        
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
        }
        
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        // Extract cookies
        if (preg_match_all('/Set-Cookie:\s*([^;]+)/i', $headers, $m)) {
            $this->authCookie = implode('; ', $m[1]);
        }
        
        return [
            'code' => $httpCode,
            'body' => $body,
            'url' => $finalUrl
        ];
    }
    
    private function testPage(string $path, array $config): void
    {
        $r = $this->request('GET', $path);
        $issues = [];
        
        // Check HTTP code
        if ($r['code'] !== 200) {
            $issues[] = "HTTP {$r['code']}";
        }
        
        // Check for PHP errors
        foreach ($this->errorPatterns as $pattern) {
            if (preg_match($pattern, $r['body'])) {
                $issues[] = "PHP error detected";
                break;
            }
        }
        
        // Check for empty body
        if (strlen(trim($r['body'])) < 100) {
            $issues[] = "Empty or minimal content";
        }
        
        // Check for title
        if (!preg_match('/<title>/i', $r['body'])) {
            $issues[] = "No title tag";
        }
        
        // Save screenshot (HTML snapshot)
        if ($this->takeScreenshots) {
            $filename = str_replace(['/', '\\'], '_', trim($path, '/')) ?: 'home';
            file_put_contents(OUTPUT_DIR . "/$filename.html", $r['body']);
        }
        
        // Result
        if (empty($issues)) {
            $this->passed++;
            echo "  ✓ $path\n";
            $this->results[$path] = 'pass';
        } else {
            $this->failed++;
            echo "  ✗ $path - " . implode(', ', $issues) . "\n";
            $this->results[$path] = 'fail: ' . implode(', ', $issues);
        }
    }
    
    private function skip(string $path, string $reason): void
    {
        echo "  ○ $path (skipped: $reason)\n";
        $this->results[$path] = 'skip';
    }
    
    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $rate = $total > 0 ? round(($this->passed / $total) * 100) : 0;
        
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "  SUMMARY: {$this->passed}/$total ($rate%)\n";
        echo str_repeat('=', 60) . "\n";
        
        if ($this->takeScreenshots) {
            echo "  Screenshots saved in: tests/output/screenshots/\n";
        }
        
        // Save results
        $outputDir = dirname(OUTPUT_DIR);
        file_put_contents($outputDir . '/ui_test_result.json', json_encode([
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

// Run
$baseUrl = $argv[1] ?? 'http://localhost/kdocs';
$screenshots = in_array('--screenshots', $argv);

$test = new UiTest($baseUrl, $screenshots);
$test->run();
exit($test->getExitCode());

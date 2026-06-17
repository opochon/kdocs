<?php
/**
 * K-DOCS - Smoke Tests
 * Tests rapides de santé de l'application (< 30 secondes)
 */

define('KDOCS_ROOT', dirname(__DIR__));
define('GREEN', "\033[32m");
define('RED', "\033[31m");
define('YELLOW', "\033[33m");
define('RESET', "\033[0m");

class SmokeTest
{
    private array $results = [];
    private int $passed = 0;
    private int $failed = 0;
    private string $baseUrl;
    
    public function __construct(string $baseUrl = 'http://localhost/kdocs')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }
    
    public function run(): void
    {
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "  K-DOCS SMOKE TESTS\n";
        echo str_repeat('=', 60) . "\n\n";
        
        $this->testPhpVersion();
        $this->testExtensions();
        $this->testDatabase();
        $this->testStorage();
        $this->testConfig();
        $this->testRoutes();
        $this->testTools();
        $this->testServices();
        
        $this->printSummary();
    }
    
    private function test(string $name, callable $fn): void
    {
        try {
            $result = $fn();
            if ($result === true) {
                $this->passed++;
                echo GREEN . "  ✓ " . RESET . "$name\n";
                $this->results[$name] = ['status' => 'pass'];
            } else {
                $this->failed++;
                $msg = is_string($result) ? $result : 'Failed';
                echo RED . "  ✗ " . RESET . "$name - $msg\n";
                $this->results[$name] = ['status' => 'fail', 'message' => $msg];
            }
        } catch (\Exception $e) {
            $this->failed++;
            echo RED . "  ✗ " . RESET . "$name - " . $e->getMessage() . "\n";
            $this->results[$name] = ['status' => 'fail', 'message' => $e->getMessage()];
        }
    }
    
    private function testPhpVersion(): void
    {
        echo "PHP Environment\n";
        $this->test('PHP version >= 8.1', fn() => version_compare(PHP_VERSION, '8.1.0', '>='));
    }
    
    private function testExtensions(): void
    {
        echo "\nExtensions\n";
        $required = ['curl', 'mbstring', 'pdo_mysql', 'gd', 'zip', 'json', 'fileinfo'];
        foreach ($required as $ext) {
            $this->test("Extension $ext", fn() => extension_loaded($ext));
        }
    }
    
    private function testDatabase(): void
    {
        echo "\nDatabase\n";
        
        // Charger config
        $configPath = KDOCS_ROOT . '/config/config.php';
        if (!file_exists($configPath)) {
            $this->test('Config file exists', fn() => 'config/config.php not found');
            return;
        }
        
        $config = require $configPath;
        $db = $config['database'] ?? [];
        
        $this->test('Database connection', function() use ($db) {
            $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $db['user'], $db['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5
            ]);
            return true;
        });
        
        $this->test('Tables exist', function() use ($db) {
            $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $db['user'], $db['password']);
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $required = ['documents', 'users', 'tags', 'correspondents', 'document_types'];
            foreach ($required as $table) {
                if (!in_array($table, $tables)) {
                    return "Table '$table' missing";
                }
            }
            return true;
        });
    }
    
    private function testStorage(): void
    {
        echo "\nStorage\n";
        $dirs = [
            'storage' => KDOCS_ROOT . '/storage',
            'storage/documents' => KDOCS_ROOT . '/storage/documents',
            'storage/thumbnails' => KDOCS_ROOT . '/storage/thumbnails',
            'storage/consume' => KDOCS_ROOT . '/storage/consume',
        ];
        
        foreach ($dirs as $name => $path) {
            $this->test("$name writable", function() use ($path) {
                if (!is_dir($path)) return "Directory not found";
                if (!is_writable($path)) return "Not writable";
                return true;
            });
        }
    }
    
    private function testConfig(): void
    {
        echo "\nConfiguration\n";
        
        $this->test('Config loads without error', function() {
            $config = require KDOCS_ROOT . '/config/config.php';
            return is_array($config) && isset($config['app']);
        });
        
        $this->test('App key configured', function() {
            $config = require KDOCS_ROOT . '/config/config.php';
            return !empty($config['app']['key'] ?? '');
        });
    }
    
    private function testRoutes(): void
    {
        echo "\nHTTP Routes\n";
        
        $routes = [
            '/health' => [200],
            '/login' => [200],
            '/' => [200, 302],
            '/documents' => [200, 302],
        ];
        
        foreach ($routes as $path => $expectedCodes) {
            $this->test("GET $path", function() use ($path, $expectedCodes) {
                $ch = curl_init($this->baseUrl . $path);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_NOBODY => false,
                ]);
                curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if (in_array($code, $expectedCodes)) {
                    return true;
                }
                return "HTTP $code (expected " . implode('/', $expectedCodes) . ")";
            });
        }
    }
    
    private function testTools(): void
    {
        echo "\nExternal Tools\n";
        $config = require KDOCS_ROOT . '/config/config.php';
        
        $tools = [
            'Tesseract' => $config['ocr']['tesseract_path'] ?? '',
            'Ghostscript' => $config['tools']['ghostscript'] ?? '',
            'pdftotext' => $config['tools']['pdftotext'] ?? '',
        ];
        
        foreach ($tools as $name => $path) {
            $this->test($name, function() use ($path) {
                if (empty($path)) return 'Not configured';
                if (!file_exists($path)) return 'File not found';
                return true;
            });
        }
    }
    
    private function testServices(): void
    {
        echo "\nServices\n";
        $config = require KDOCS_ROOT . '/config/config.php';
        
        // Ollama
        $ollamaUrl = $config['api']['ollama_url'] ?? 'http://localhost:11434';
        $this->test('Ollama', function() use ($ollamaUrl) {
            $ch = curl_init($ollamaUrl . '/api/tags');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
            ]);
            $response = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($code === 200) return true;
            return 'Not accessible';
        });
    }
    
    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $rate = $total > 0 ? round(($this->passed / $total) * 100) : 0;
        
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "  SUMMARY: {$this->passed}/$total passed ($rate%)\n";
        echo str_repeat('=', 60) . "\n";
        
        if ($this->failed === 0) {
            echo GREEN . "  ✓ All smoke tests passed!\n" . RESET;
        } else {
            echo RED . "  ✗ {$this->failed} test(s) failed\n" . RESET;
        }
        
        // Sauvegarder résultat JSON
        $outputDir = KDOCS_ROOT . '/tests/output';
        if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);
        
        file_put_contents($outputDir . '/smoke_test_result.json', json_encode([
            'date' => date('Y-m-d H:i:s'),
            'passed' => $this->passed,
            'failed' => $this->failed,
            'total' => $total,
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
$test = new SmokeTest($argv[1] ?? 'http://localhost/kdocs');
$test->run();
exit($test->getExitCode());

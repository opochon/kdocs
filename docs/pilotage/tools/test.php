#!/usr/bin/env php
<?php
/**
 * K-DOCS — Tests de Non-Régression
 * 
 * Vérifie que les fonctionnalités de base fonctionnent toujours
 * 
 * Usage : php test.php [base_url]
 * Exemple : php test.php http://localhost/kdocs
 */

define('GREEN', "\033[32m");
define('RED', "\033[31m");
define('YELLOW', "\033[33m");
define('RESET', "\033[0m");

class KDocsTests
{
    private string $baseUrl;
    private int $passed = 0;
    private int $failed = 0;
    
    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }
    
    public function run(): bool
    {
        echo "\n";
        echo "══════════════════════════════════════════════════════════════\n";
        echo "  K-DOCS — Tests de Non-Régression\n";
        echo "══════════════════════════════════════════════════════════════\n\n";
        echo "Base URL : {$this->baseUrl}\n\n";
        
        $this->testGroup("Pages Principales", [
            ['GET', '/', 200, 'Page d\'accueil'],
            ['GET', '/documents', [200, 302], 'Liste documents'],
            ['GET', '/login', 200, 'Page login'],
        ]);
        
        $this->testGroup("API REST", [
            ['GET', '/api/documents', [200, 401], 'API Documents'],
            ['GET', '/api/tags', [200, 401], 'API Tags'],
        ]);
        
        $this->testGroup("Administration", [
            ['GET', '/admin/consume', [200, 302], 'Page Consume'],
            ['GET', '/admin/tags', [200, 302], 'Gestion Tags'],
        ]);
        
        $this->printSummary();
        
        return $this->failed === 0;
    }
    
    private function testGroup(string $name, array $tests): void
    {
        echo "▶ $name\n";
        
        foreach ($tests as $test) {
            [$method, $path, $expectedStatus, $description] = $test;
            $this->testEndpoint($method, $path, $expectedStatus, $description);
        }
        
        echo "\n";
    }
    
    private function testEndpoint(string $method, string $path, $expectedStatus, string $description): void
    {
        $url = $this->baseUrl . $path;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HEADER => true,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $expectedCodes = is_array($expectedStatus) ? $expectedStatus : [$expectedStatus];
        $success = in_array($httpCode, $expectedCodes);
        
        if ($error) {
            $this->fail($description, "Erreur curl: $error");
        } elseif ($success) {
            $this->pass($description, $httpCode);
        } else {
            $this->fail($description, "HTTP $httpCode (attendu: " . implode(' ou ', $expectedCodes) . ")");
        }
    }
    
    private function pass(string $description, int $httpCode): void
    {
        $this->passed++;
        echo GREEN . "  ✓ " . RESET . "$description " . GREEN . "[$httpCode]" . RESET . "\n";
    }
    
    private function fail(string $description, string $reason): void
    {
        $this->failed++;
        echo RED . "  ✗ " . RESET . "$description — " . RED . $reason . RESET . "\n";
    }
    
    private function printSummary(): void
    {
        echo "──────────────────────────────────────────────────────────────\n";
        
        $total = $this->passed + $this->failed;
        
        if ($this->failed === 0) {
            echo GREEN . "✓ Tous les tests passent ($this->passed/$total)\n" . RESET;
        } else {
            echo RED . "✗ $this->failed test(s) en échec sur $total\n" . RESET;
        }
        
        echo "\n";
    }
}

if (!function_exists('curl_init')) {
    echo RED . "Erreur : Extension curl requise\n" . RESET;
    exit(1);
}

$baseUrl = $argv[1] ?? 'http://localhost/kdocs';

$tests = new KDocsTests($baseUrl);
$success = $tests->run();
exit($success ? 0 : 1);

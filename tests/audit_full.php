<?php
/**
 * K-Docs - Audit Complet
 * Script d'audit automatisé avec tests curl, analyse de code et rapport
 *
 * Usage: php tests/audit_full.php [--base-url=http://localhost/kdocs] [--output=audit_results]
 */

// Autoload
require_once __DIR__ . '/../vendor/autoload.php';

// Configuration
$baseUrl = 'http://localhost/kdocs';
$outputDir = __DIR__ . '/audit_results';
$username = 'admin';
$password = 'admin';

// Parse arguments
foreach ($argv as $arg) {
    if (strpos($arg, '--base-url=') === 0) {
        $baseUrl = substr($arg, 11);
    }
    if (strpos($arg, '--output=') === 0) {
        $outputDir = substr($arg, 9);
    }
}

// Create output directory
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$timestamp = date('Ymd_His');
$reportFile = "$outputDir/audit_report_$timestamp.md";
$jsonFile = "$outputDir/audit_results_$timestamp.json";

class KDocsAudit
{
    private string $baseUrl;
    private string $outputDir;
    private ?string $sessionCookie = null;
    private array $results = [];
    private array $summary = ['total' => 0, 'passed' => 0, 'failed' => 0, 'warnings' => 0];
    private array $codeAnalysis = [];

    public function __construct(string $baseUrl, string $outputDir)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->outputDir = $outputDir;
    }

    public function run(string $username, string $password): void
    {
        echo "\n========================================\n";
        echo "   K-Docs - Audit Complet\n";
        echo "   " . date('Y-m-d H:i:s') . "\n";
        echo "========================================\n\n";

        // 1. Test public endpoints
        $this->testPublicEndpoints();

        // 2. Login
        $this->login($username, $password);

        if ($this->sessionCookie) {
            // 3. Test authenticated pages
            $this->testAllPages();

            // 4. Test all APIs
            $this->testAllApis();

            // 5. Test CRUD operations
            $this->testCrudOperations();

            // 6. Test search operators
            $this->testSearchOperators();
        }

        // 7. Code analysis
        $this->analyzeCode();

        // 8. Security checks
        $this->securityChecks();

        // 9. Generate report
        $this->generateReport();
    }

    private function request(string $method, string $endpoint, array $data = [], array $headers = []): array
    {
        $url = $this->baseUrl . $endpoint;
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($this->sessionCookie) {
            curl_setopt($ch, CURLOPT_COOKIE, $this->sessionCookie);
        }

        $requestHeaders = array_merge(['Accept: application/json'], $headers);

        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if (!empty($data)) {
                    if (isset($headers['Content-Type']) && strpos($headers['Content-Type'], 'json') !== false) {
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                        $requestHeaders[] = 'Content-Type: application/json';
                    } else {
                        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                    }
                }
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                $requestHeaders[] = 'Content-Type: application/json';
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
            case 'PATCH':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                $requestHeaders[] = 'Content-Type: application/json';
                break;
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);

        $startTime = microtime(true);
        $response = curl_exec($ch);
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        // Extract cookies
        if (preg_match('/Set-Cookie:\s*([^;]+)/i', $headers, $matches)) {
            $this->sessionCookie = $matches[1];
        }

        return [
            'status_code' => $statusCode,
            'headers' => $headers,
            'body' => $body,
            'duration' => $duration,
            'error' => $error
        ];
    }

    private function addResult(string $category, string $name, string $method, string $endpoint, int $statusCode, string $status, string $details = '', float $duration = 0): void
    {
        $this->results[] = [
            'category' => $category,
            'name' => $name,
            'method' => $method,
            'endpoint' => $endpoint,
            'status_code' => $statusCode,
            'status' => $status,
            'details' => $details,
            'duration_ms' => $duration
        ];

        $this->summary['total']++;
        switch ($status) {
            case 'PASS': $this->summary['passed']++; $icon = "\033[32m[PASS]\033[0m"; break;
            case 'FAIL': $this->summary['failed']++; $icon = "\033[31m[FAIL]\033[0m"; break;
            case 'WARN': $this->summary['warnings']++; $icon = "\033[33m[WARN]\033[0m"; break;
            default: $icon = "[????]";
        }

        echo "  $icon $name" . ($details ? " - $details" : "") . "\n";
    }

    private function testEndpoint(string $category, string $name, string $method, string $endpoint, array $data = [], array $expectedCodes = [200]): array
    {
        $response = $this->request($method, $endpoint, $data);

        $status = in_array($response['status_code'], $expectedCodes) ? 'PASS' : 'FAIL';
        if ($response['status_code'] === 0) {
            $status = 'FAIL';
        }

        $this->addResult($category, $name, $method, $endpoint, $response['status_code'], $status, $response['error'], $response['duration']);

        return $response;
    }

    private function testPublicEndpoints(): void
    {
        echo "\n=== Test Endpoints Publics ===\n";

        $this->testEndpoint('Public', 'Health Check', 'GET', '/health');
        $this->testEndpoint('Public', 'Login Page', 'GET', '/login');
    }

    private function login(string $username, string $password): void
    {
        echo "\n=== Authentification ===\n";

        $response = $this->request('POST', '/login', [
            'username' => $username,
            'password' => $password
        ]);

        if ($response['status_code'] === 302 || $response['status_code'] === 200) {
            $this->addResult('Auth', 'Login', 'POST', '/login', $response['status_code'], 'PASS', 'Session etablie', $response['duration']);
        } else {
            $this->addResult('Auth', 'Login', 'POST', '/login', $response['status_code'], 'FAIL', 'Echec authentification', $response['duration']);
        }
    }

    private function testAllPages(): void
    {
        echo "\n=== Test Pages Web ===\n";

        $pages = [
            // Dashboard
            ['Dashboard', 'Accueil', '/'],
            ['Dashboard', 'Dashboard', '/dashboard'],
            ['Dashboard', 'Mes taches', '/mes-taches'],
            ['Dashboard', 'Chat IA', '/chat'],

            // Documents
            ['Documents', 'Liste', '/documents'],
            ['Documents', 'Upload', '/documents/upload'],

            // Admin
            ['Admin', 'Index', '/admin'],
            ['Admin', 'Parametres', '/admin/settings'],
            ['Admin', 'Utilisateurs', '/admin/users'],
            ['Admin', 'Roles', '/admin/roles'],
            ['Admin', 'Groupes utilisateurs', '/admin/user-groups'],
            ['Admin', 'Correspondants', '/admin/correspondents'],
            ['Admin', 'Tags', '/admin/tags'],
            ['Admin', 'Types documents', '/admin/document-types'],
            ['Admin', 'Champs personnalises', '/admin/custom-fields'],
            ['Admin', 'Chemins stockage', '/admin/storage-paths'],
            ['Admin', 'Workflows', '/admin/workflows'],
            ['Admin', 'Webhooks', '/admin/webhooks'],
            ['Admin', 'Logs audit', '/admin/audit-logs'],
            ['Admin', 'Export/Import', '/admin/export-import'],
            ['Admin', 'Comptes email', '/admin/mail-accounts'],
            ['Admin', 'Taches planifiees', '/admin/scheduled-tasks'],
            ['Admin', 'Consume folder', '/admin/consume'],
            ['Admin', 'Champs classification', '/admin/classification-fields'],
            ['Admin', 'Indexation', '/admin/indexing'],
            ['Admin', 'Usage API', '/admin/api-usage'],
        ];

        foreach ($pages as $page) {
            $this->testEndpoint($page[0], $page[1], 'GET', $page[2], [], [200, 302]);
        }
    }

    private function testAllApis(): void
    {
        echo "\n=== Test APIs ===\n";

        $apis = [
            // Documents API
            ['API Documents', 'Liste', 'GET', '/api/documents'],

            // Tags API
            ['API Tags', 'Liste', 'GET', '/api/tags'],

            // Correspondents API
            ['API Correspondents', 'Liste', 'GET', '/api/correspondents'],
            ['API Correspondents', 'Search', 'GET', '/api/correspondents/search?q=test'],

            // Folders API
            ['API Folders', 'Tree', 'GET', '/api/folders/tree'],
            ['API Folders', 'Tree HTML', 'GET', '/api/folders/tree-html'],
            ['API Folders', 'Children', 'GET', '/api/folders/children'],
            ['API Folders', 'Documents', 'GET', '/api/folders/documents'],
            ['API Folders', 'Crawl status', 'GET', '/api/folders/crawl-status'],
            ['API Folders', 'Indexing status', 'GET', '/api/folders/indexing-status'],

            // Search API
            ['API Search', 'Quick search', 'GET', '/api/search/quick?q=test'],

            // Workflow API
            ['API Workflow', 'Liste', 'GET', '/api/workflows'],
            ['API Workflow', 'Node catalog', 'GET', '/api/workflow/node-catalog'],
            ['API Workflow', 'Options', 'GET', '/api/workflow/options'],

            // Validation API
            ['API Validation', 'Pending', 'GET', '/api/validation/pending'],
            ['API Validation', 'Statistics', 'GET', '/api/validation/statistics'],
            ['API Validation', 'Roles', 'GET', '/api/roles'],

            // Notifications API
            ['API Notifications', 'Liste', 'GET', '/api/notifications'],
            ['API Notifications', 'Unread', 'GET', '/api/notifications/unread'],
            ['API Notifications', 'Count', 'GET', '/api/notifications/count'],

            // Chat API
            ['API Chat', 'Conversations', 'GET', '/api/chat/conversations'],

            // Tasks API
            ['API Tasks', 'Liste', 'GET', '/api/tasks'],
            ['API Tasks', 'Counts', 'GET', '/api/tasks/counts'],
            ['API Tasks', 'Summary', 'GET', '/api/tasks/summary'],

            // Email Ingestion API
            ['API Email', 'Logs', 'GET', '/api/email-ingestion/logs'],

            // Other APIs
            ['API Other', 'Document types', 'GET', '/api/document-types'],
            ['API Other', 'Classification fields', 'GET', '/api/classification-fields'],
            ['API Other', 'User groups', 'GET', '/api/user-groups'],
            ['API Other', 'Notes', 'GET', '/api/notes'],
            ['API Other', 'OnlyOffice status', 'GET', '/api/onlyoffice/status'],
        ];

        foreach ($apis as $api) {
            $this->testEndpoint($api[0], $api[1], $api[2], $api[3]);
        }
    }

    private function testCrudOperations(): void
    {
        echo "\n=== Test Operations CRUD ===\n";

        // Create tag
        $response = $this->request('POST', '/api/tags', ['name' => 'Audit Test Tag ' . time(), 'color' => '#ff0000'], ['Content-Type' => 'application/json']);
        $tagCreated = $response['status_code'] === 200 || $response['status_code'] === 201;
        $this->addResult('CRUD', 'Create Tag', 'POST', '/api/tags', $response['status_code'], $tagCreated ? 'PASS' : 'FAIL', '', $response['duration']);

        if ($tagCreated) {
            $data = json_decode($response['body'], true);
            $tagId = $data['id'] ?? $data['tag']['id'] ?? null;

            if ($tagId) {
                // Update tag
                $response = $this->request('PUT', "/api/tags/$tagId", ['name' => 'Updated Audit Tag', 'color' => '#00ff00'], ['Content-Type' => 'application/json']);
                $this->addResult('CRUD', 'Update Tag', 'PUT', "/api/tags/$tagId", $response['status_code'], $response['status_code'] === 200 ? 'PASS' : 'WARN', '', $response['duration']);

                // Delete tag
                $response = $this->request('DELETE', "/api/tags/$tagId");
                $this->addResult('CRUD', 'Delete Tag', 'DELETE', "/api/tags/$tagId", $response['status_code'], $response['status_code'] === 200 ? 'PASS' : 'WARN', '', $response['duration']);
            }
        }

        // Create conversation
        $response = $this->request('POST', '/api/chat/conversations', [], ['Content-Type' => 'application/json']);
        $this->addResult('CRUD', 'Create Conversation', 'POST', '/api/chat/conversations', $response['status_code'], $response['status_code'] === 200 ? 'PASS' : 'FAIL', '', $response['duration']);
    }

    private function testSearchOperators(): void
    {
        echo "\n=== Test Operateurs Recherche ===\n";

        $searches = [
            ['AND operator', ['question' => 'document AND test', 'scope' => 'all']],
            ['OR operator', ['question' => 'facture OR devis', 'scope' => 'all']],
            ['Phrase exacte', ['question' => '"contrat de bail"', 'scope' => 'content']],
            ['Wildcard *', ['question' => 'fact*', 'scope' => 'name']],
            ['Wildcard ?', ['question' => 't?st', 'scope' => 'all']],
            ['NOT operator', ['question' => 'document NOT brouillon', 'scope' => 'all']],
            ['Date range', ['question' => 'facture', 'date_from' => '2024-01-01', 'date_to' => '2024-12-31']],
            ['Name scope', ['question' => 'test', 'scope' => 'name']],
            ['Content scope', ['question' => 'test', 'scope' => 'content']],
        ];

        foreach ($searches as $search) {
            $response = $this->request('POST', '/api/search/ask', $search[1], ['Content-Type' => 'application/json']);
            $this->addResult('Search Operators', $search[0], 'POST', '/api/search/ask', $response['status_code'], $response['status_code'] === 200 ? 'PASS' : 'FAIL', '', $response['duration']);
        }
    }

    private function analyzeCode(): void
    {
        echo "\n=== Analyse Code ===\n";

        $basePath = dirname(__DIR__);

        // Count files
        $phpFiles = $this->countFiles($basePath, 'php');
        $jsFiles = $this->countFiles($basePath . '/public/js', 'js');
        $phpTemplates = $this->countFiles($basePath . '/templates', 'php');

        $this->codeAnalysis['files'] = [
            'php_total' => $phpFiles,
            'js_files' => $jsFiles,
            'templates' => $phpTemplates
        ];

        // Count lines
        $totalLines = $this->countLines($basePath . '/app');
        $this->codeAnalysis['lines'] = $totalLines;

        // List controllers
        $controllers = glob($basePath . '/app/Controllers/*.php');
        $apiControllers = glob($basePath . '/app/Controllers/Api/*.php');
        $this->codeAnalysis['controllers'] = count($controllers) + count($apiControllers);

        // List services
        $services = glob($basePath . '/app/Services/*.php');
        $this->codeAnalysis['services'] = count($services);

        // List models
        $models = glob($basePath . '/app/Models/*.php');
        $this->codeAnalysis['models'] = count($models);

        // Check for potential issues
        $this->codeAnalysis['issues'] = [];

        // Check for SQL injection patterns (basic)
        $this->checkCodePattern($basePath . '/app', '/\$_(?:GET|POST|REQUEST)\[.*\].*(?:query|exec)/i', 'Potential SQL injection');

        // Check for missing CSRF in forms
        $this->checkCodePattern($basePath . '/templates', '/<form[^>]*method=["\']post["\'][^>]*>(?!.*csrf)/i', 'Form without CSRF token');

        echo "  Fichiers PHP: {$this->codeAnalysis['files']['php_total']}\n";
        echo "  Fichiers JS: {$this->codeAnalysis['files']['js_files']}\n";
        echo "  Templates: {$this->codeAnalysis['files']['templates']}\n";
        echo "  Lignes de code (app/): {$this->codeAnalysis['lines']}\n";
        echo "  Controllers: {$this->codeAnalysis['controllers']}\n";
        echo "  Services: {$this->codeAnalysis['services']}\n";
        echo "  Models: {$this->codeAnalysis['models']}\n";
    }

    private function countFiles(string $dir, string $ext): int
    {
        if (!is_dir($dir)) return 0;
        $count = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === $ext) {
                $count++;
            }
        }
        return $count;
    }

    private function countLines(string $dir): int
    {
        if (!is_dir($dir)) return 0;
        $lines = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $lines += count(file($file->getPathname()));
            }
        }
        return $lines;
    }

    private function checkCodePattern(string $dir, string $pattern, string $issue): void
    {
        if (!is_dir($dir)) return;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getPathname());
                if (preg_match($pattern, $content)) {
                    $this->codeAnalysis['issues'][] = [
                        'file' => str_replace(dirname(__DIR__) . '/', '', $file->getPathname()),
                        'issue' => $issue
                    ];
                }
            }
        }
    }

    private function securityChecks(): void
    {
        echo "\n=== Verification Securite ===\n";

        // Check for exposed files
        $sensitiveFiles = [
            ['Config expose', '/.env', 404],
            ['Composer.json expose', '/composer.json', 404],
            ['Storage accessible', '/storage/', [403, 404]],
        ];

        foreach ($sensitiveFiles as $check) {
            $response = $this->request('GET', $check[1]);
            $expected = is_array($check[2]) ? $check[2] : [$check[2]];
            $status = in_array($response['status_code'], $expected) ? 'PASS' : 'WARN';
            $this->addResult('Security', $check[0], 'GET', $check[1], $response['status_code'], $status, '', $response['duration']);
        }

        // Check security headers
        $response = $this->request('GET', '/');
        $hasXContentType = stripos($response['headers'], 'X-Content-Type-Options') !== false;
        $hasXFrame = stripos($response['headers'], 'X-Frame-Options') !== false;
        $hasXXSS = stripos($response['headers'], 'X-XSS-Protection') !== false;

        $this->addResult('Security', 'X-Content-Type-Options header', 'GET', '/', 0, $hasXContentType ? 'PASS' : 'WARN');
        $this->addResult('Security', 'X-Frame-Options header', 'GET', '/', 0, $hasXFrame ? 'PASS' : 'WARN');
        $this->addResult('Security', 'X-XSS-Protection header', 'GET', '/', 0, $hasXXSS ? 'PASS' : 'WARN');
    }

    private function generateReport(): void
    {
        global $reportFile, $jsonFile;

        echo "\n=== Generation Rapport ===\n";

        $passRate = $this->summary['total'] > 0
            ? round(($this->summary['passed'] / $this->summary['total']) * 100, 1)
            : 0;

        $report = "# Rapport d'Audit K-Docs\n\n";
        $report .= "**Date:** " . date('Y-m-d H:i:s') . "\n";
        $report .= "**URL:** {$this->baseUrl}\n\n";
        $report .= "---\n\n";

        // Summary
        $report .= "## Resume\n\n";
        $report .= "| Metrique | Valeur |\n";
        $report .= "|----------|--------|\n";
        $report .= "| Total tests | {$this->summary['total']} |\n";
        $report .= "| Reussis | {$this->summary['passed']} |\n";
        $report .= "| Echecs | {$this->summary['failed']} |\n";
        $report .= "| Avertissements | {$this->summary['warnings']} |\n";
        $report .= "| Taux de reussite | {$passRate}% |\n\n";

        // Code analysis
        $report .= "## Analyse du Code\n\n";
        $report .= "| Metrique | Valeur |\n";
        $report .= "|----------|--------|\n";
        $report .= "| Fichiers PHP | {$this->codeAnalysis['files']['php_total']} |\n";
        $report .= "| Fichiers JS | {$this->codeAnalysis['files']['js_files']} |\n";
        $report .= "| Templates | {$this->codeAnalysis['files']['templates']} |\n";
        $report .= "| Lignes de code | {$this->codeAnalysis['lines']} |\n";
        $report .= "| Controllers | {$this->codeAnalysis['controllers']} |\n";
        $report .= "| Services | {$this->codeAnalysis['services']} |\n";
        $report .= "| Models | {$this->codeAnalysis['models']} |\n\n";

        // Group results by category
        $report .= "## Details par Categorie\n\n";

        $categories = [];
        foreach ($this->results as $result) {
            $categories[$result['category']][] = $result;
        }

        foreach ($categories as $cat => $tests) {
            $report .= "### $cat\n\n";
            $report .= "| Test | Methode | Endpoint | Code | Statut | Duree |\n";
            $report .= "|------|---------|----------|------|--------|-------|\n";

            foreach ($tests as $test) {
                $statusIcon = match($test['status']) {
                    'PASS' => 'OK',
                    'FAIL' => 'ECHEC',
                    'WARN' => 'WARN',
                    default => '?'
                };
                $report .= "| {$test['name']} | {$test['method']} | {$test['endpoint']} | {$test['status_code']} | $statusIcon | {$test['duration_ms']}ms |\n";
            }
            $report .= "\n";
        }

        // List failures
        $failures = array_filter($this->results, fn($r) => $r['status'] === 'FAIL');
        if (!empty($failures)) {
            $report .= "## Echecs\n\n";
            foreach ($failures as $fail) {
                $report .= "- **{$fail['name']}** ({$fail['endpoint']}): {$fail['details']}\n";
            }
            $report .= "\n";
        }

        // Code issues
        if (!empty($this->codeAnalysis['issues'])) {
            $report .= "## Problemes de Code Detectes\n\n";
            foreach ($this->codeAnalysis['issues'] as $issue) {
                $report .= "- **{$issue['file']}**: {$issue['issue']}\n";
            }
            $report .= "\n";
        }

        // Save files
        file_put_contents($reportFile, $report);
        file_put_contents($jsonFile, json_encode([
            'timestamp' => date('c'),
            'base_url' => $this->baseUrl,
            'summary' => $this->summary,
            'code_analysis' => $this->codeAnalysis,
            'tests' => $this->results
        ], JSON_PRETTY_PRINT));

        echo "  Rapport: $reportFile\n";
        echo "  JSON: $jsonFile\n";

        echo "\n========================================\n";
        echo "   Resume Final\n";
        echo "========================================\n";
        echo "  Total: {$this->summary['total']} tests\n";
        echo "  \033[32mReussis: {$this->summary['passed']}\033[0m\n";
        echo "  \033[31mEchecs: {$this->summary['failed']}\033[0m\n";
        echo "  \033[33mAvertissements: {$this->summary['warnings']}\033[0m\n";
        echo "  Taux: {$passRate}%\n\n";
    }
}

// Run audit
$audit = new KDocsAudit($baseUrl, $outputDir);
$audit->run($username, $password);

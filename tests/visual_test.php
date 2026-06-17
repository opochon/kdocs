<?php
/**
 * K-DOCS - Visual Regression Testing
 * Capture et compare les screenshots des pages
 */

define('KDOCS_ROOT', dirname(__DIR__));
define('SCREENSHOTS_DIR', KDOCS_ROOT . '/tests/screenshots');
define('REFERENCE_DIR', SCREENSHOTS_DIR . '/reference');
define('CURRENT_DIR', SCREENSHOTS_DIR . '/current');
define('DIFF_DIR', SCREENSHOTS_DIR . '/diff');

class VisualTest
{
    private string $baseUrl;
    private ?string $authCookie = null;
    private array $results = [];
    private int $passed = 0;
    private int $warnings = 0;
    private int $failed = 0;
    
    private array $pages = [
        'login' => ['path' => '/login', 'auth' => false],
        'dashboard' => ['path' => '/dashboard', 'auth' => true],
        'documents' => ['path' => '/documents', 'auth' => true],
        'upload' => ['path' => '/documents/upload', 'auth' => true],
        'settings' => ['path' => '/admin/settings', 'auth' => true],
        'tags' => ['path' => '/admin/tags', 'auth' => true],
        'types' => ['path' => '/admin/document-types', 'auth' => true],
        'correspondents' => ['path' => '/admin/correspondents', 'auth' => true],
        'consume' => ['path' => '/admin/consume', 'auth' => true],
        'users' => ['path' => '/admin/users', 'auth' => true],
    ];
    
    public function __construct(string $baseUrl = 'http://localhost/kdocs')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->ensureDirs();
    }
    
    private function ensureDirs(): void
    {
        foreach ([SCREENSHOTS_DIR, REFERENCE_DIR, CURRENT_DIR, DIFF_DIR] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
    
    /**
     * Capture les screenshots actuels
     */
    public function capture(): void
    {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════╗\n";
        echo "║            K-DOCS VISUAL CAPTURE                             ║\n";
        echo "╚══════════════════════════════════════════════════════════════╝\n\n";
        
        $this->authenticate();
        
        foreach ($this->pages as $name => $config) {
            if ($config['auth'] && !$this->authCookie) {
                echo "  ⊘ $name (skip - no auth)\n";
                continue;
            }
            
            $html = $this->fetchPage($config['path']);
            if ($html) {
                $filepath = CURRENT_DIR . "/$name.html";
                file_put_contents($filepath, $html);
                
                // Extraire aussi les éléments clés pour comparaison
                $elements = $this->extractKeyElements($html);
                file_put_contents(CURRENT_DIR . "/$name.json", json_encode($elements, JSON_PRETTY_PRINT));
                
                echo "  ✓ $name\n";
            } else {
                echo "  ✗ $name (failed to fetch)\n";
            }
        }
        
        echo "\nCaptures saved in: tests/screenshots/current/\n";
    }
    
    /**
     * Définit les captures actuelles comme référence
     */
    public function setReference(): void
    {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════╗\n";
        echo "║            K-DOCS SET REFERENCE                              ║\n";
        echo "╚══════════════════════════════════════════════════════════════╝\n\n";
        
        $files = glob(CURRENT_DIR . '/*.*');
        
        if (empty($files)) {
            echo "No current captures found. Run 'capture' first.\n";
            return;
        }
        
        foreach ($files as $file) {
            $dest = REFERENCE_DIR . '/' . basename($file);
            copy($file, $dest);
            echo "  ✓ " . basename($file) . "\n";
        }
        
        // Sauvegarder metadata
        file_put_contents(REFERENCE_DIR . '/metadata.json', json_encode([
            'created_at' => date('Y-m-d H:i:s'),
            'pages' => array_keys($this->pages),
        ], JSON_PRETTY_PRINT));
        
        echo "\nReference set. Future comparisons will use these captures.\n";
    }
    
    /**
     * Compare current vs reference
     */
    public function compare(): array
    {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════╗\n";
        echo "║            K-DOCS VISUAL COMPARISON                          ║\n";
        echo "╚══════════════════════════════════════════════════════════════╝\n\n";
        
        $this->results = [];
        $this->passed = 0;
        $this->warnings = 0;
        $this->failed = 0;
        
        // Vérifier que les références existent
        if (!file_exists(REFERENCE_DIR . '/metadata.json')) {
            echo "No reference found. Run 'set-reference' first.\n";
            return ['status' => 'no_reference'];
        }
        
        // Capturer l'état actuel
        $this->capture();
        echo "\n--- Comparing ---\n\n";
        
        foreach ($this->pages as $name => $config) {
            $refHtml = REFERENCE_DIR . "/$name.html";
            $refJson = REFERENCE_DIR . "/$name.json";
            $curHtml = CURRENT_DIR . "/$name.html";
            $curJson = CURRENT_DIR . "/$name.json";
            
            if (!file_exists($refHtml) || !file_exists($curHtml)) {
                echo "  ⊘ $name (missing files)\n";
                $this->results[$name] = ['status' => 'skip', 'reason' => 'missing files'];
                continue;
            }
            
            $diff = $this->comparePages($name, $refJson, $curJson, $refHtml, $curHtml);
            $this->results[$name] = $diff;
            
            if ($diff['status'] === 'identical') {
                $this->passed++;
                echo "  ✓ $name (identical)\n";
            } elseif ($diff['status'] === 'minor') {
                $this->warnings++;
                echo "  ⚠ $name (minor changes: {$diff['summary']})\n";
            } else {
                $this->failed++;
                echo "  ✗ $name (CHANGED: {$diff['summary']})\n";
            }
        }
        
        $this->printSummary();
        $this->saveReport();
        
        return [
            'status' => $this->failed > 0 ? 'changes_detected' : 'ok',
            'passed' => $this->passed,
            'warnings' => $this->warnings,
            'failed' => $this->failed,
            'results' => $this->results,
        ];
    }
    
    private function comparePages(string $name, string $refJson, string $curJson, string $refHtml, string $curHtml): array
    {
        $ref = file_exists($refJson) ? json_decode(file_get_contents($refJson), true) : [];
        $cur = file_exists($curJson) ? json_decode(file_get_contents($curJson), true) : [];
        
        $changes = [];
        
        // Comparer les éléments clés
        
        // 1. Titre de page
        if (($ref['title'] ?? '') !== ($cur['title'] ?? '')) {
            $changes[] = 'title changed';
        }
        
        // 2. Nombre de boutons
        $refButtons = count($ref['buttons'] ?? []);
        $curButtons = count($cur['buttons'] ?? []);
        if ($refButtons !== $curButtons) {
            $changes[] = "buttons: $refButtons → $curButtons";
        }
        
        // 3. Nombre de liens
        $refLinks = count($ref['links'] ?? []);
        $curLinks = count($cur['links'] ?? []);
        if (abs($refLinks - $curLinks) > 5) {
            $changes[] = "links: $refLinks → $curLinks";
        }
        
        // 4. Nombre de formulaires
        $refForms = count($ref['forms'] ?? []);
        $curForms = count($cur['forms'] ?? []);
        if ($refForms !== $curForms) {
            $changes[] = "forms: $refForms → $curForms";
        }
        
        // 5. Erreurs PHP détectées
        if (!empty($cur['php_errors'])) {
            $changes[] = "PHP ERRORS: " . count($cur['php_errors']);
        }
        
        // 6. Éléments manquants critiques
        $refCritical = $ref['critical_elements'] ?? [];
        $curCritical = $cur['critical_elements'] ?? [];
        $missing = array_diff($refCritical, $curCritical);
        if (!empty($missing)) {
            $changes[] = "missing elements: " . implode(', ', $missing);
        }
        
        // 7. Taille du contenu (changement majeur si > 20%)
        $refSize = strlen(file_get_contents($refHtml));
        $curSize = strlen(file_get_contents($curHtml));
        $sizeDiff = abs($refSize - $curSize) / max($refSize, 1) * 100;
        
        if ($sizeDiff > 50) {
            $changes[] = sprintf("size: %.0f%% different", $sizeDiff);
        }
        
        // Classifier le résultat
        if (empty($changes)) {
            return ['status' => 'identical', 'summary' => 'no changes'];
        }
        
        $hasCritical = false;
        foreach ($changes as $change) {
            if (strpos($change, 'PHP ERROR') !== false || 
                strpos($change, 'missing elements') !== false) {
                $hasCritical = true;
                break;
            }
        }
        
        return [
            'status' => $hasCritical ? 'major' : 'minor',
            'summary' => implode(', ', $changes),
            'changes' => $changes,
            'size_diff_percent' => round($sizeDiff, 1),
        ];
    }
    
    private function extractKeyElements(string $html): array
    {
        $elements = [
            'title' => '',
            'buttons' => [],
            'links' => [],
            'forms' => [],
            'inputs' => [],
            'php_errors' => [],
            'critical_elements' => [],
        ];
        
        // Titre
        if (preg_match('/<title>([^<]+)<\/title>/i', $html, $m)) {
            $elements['title'] = trim($m[1]);
        }
        
        // Boutons
        preg_match_all('/<button[^>]*>([^<]*)<\/button>/i', $html, $matches);
        $elements['buttons'] = array_map('trim', $matches[1]);
        
        // Liens avec texte
        preg_match_all('/<a[^>]*>([^<]{2,50})<\/a>/i', $html, $matches);
        $elements['links'] = array_unique(array_map('trim', $matches[1]));
        
        // Formulaires
        preg_match_all('/<form[^>]*action=["\']([^"\']*)["\'][^>]*>/i', $html, $matches);
        $elements['forms'] = $matches[1];
        
        // Inputs
        preg_match_all('/<input[^>]*name=["\']([^"\']+)["\'][^>]*>/i', $html, $matches);
        $elements['inputs'] = array_unique($matches[1]);
        
        // Erreurs PHP
        $errorPatterns = [
            '/Fatal error/i',
            '/Parse error/i',
            '/Warning:/i',
            '/Notice:/i',
            '/Exception/i',
            '/Stack trace/i',
        ];
        foreach ($errorPatterns as $pattern) {
            if (preg_match($pattern, $html)) {
                $elements['php_errors'][] = $pattern;
            }
        }
        
        // Éléments critiques (navigation, header, footer)
        $criticalPatterns = [
            'nav' => '/<nav/i',
            'header' => '/<header/i',
            'footer' => '/<footer/i',
            'main' => '/<main/i',
            'sidebar' => '/sidebar|aside/i',
        ];
        foreach ($criticalPatterns as $name => $pattern) {
            if (preg_match($pattern, $html)) {
                $elements['critical_elements'][] = $name;
            }
        }
        
        return $elements;
    }
    
    private function authenticate(): void
    {
        // Load credentials from .env
        $envFile = KDOCS_ROOT . '/.env';
        $user = 'root';
        $pass = 'admin';

        if (file_exists($envFile)) {
            $env = parse_ini_file($envFile);
            $user = $env['TEST_USER'] ?? $user;
            $pass = $env['TEST_PASSWORD'] ?? $pass;
        }

        // GET login
        $this->request('GET', '/login');

        // POST login
        $this->request('POST', '/login', [
            'username' => $user,
            'password' => $pass
        ]);
    }
    
    private function fetchPage(string $path): ?string
    {
        $r = $this->request('GET', $path);
        return $r['code'] === 200 ? $r['body'] : null;
    }
    
    private function request(string $method, string $path, array $data = []): array
    {
        $ch = curl_init($this->baseUrl . $path);
        
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => true,
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
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        if (preg_match_all('/Set-Cookie:\s*([^;]+)/i', $headers, $m)) {
            $this->authCookie = implode('; ', $m[1]);
        }
        
        return ['code' => $code, 'body' => $body];
    }
    
    private function printSummary(): void
    {
        $total = $this->passed + $this->warnings + $this->failed;
        
        echo "\n";
        echo "══════════════════════════════════════════════════════════════\n";
        echo "  VISUAL COMPARISON SUMMARY\n";
        echo "══════════════════════════════════════════════════════════════\n";
        echo "  Identical: {$this->passed}\n";
        echo "  Warnings:  {$this->warnings}\n";
        echo "  Changed:   {$this->failed}\n";
        echo "══════════════════════════════════════════════════════════════\n";
        
        if ($this->failed > 0) {
            echo "  ⚠ VISUAL CHANGES DETECTED - Review before commit\n";
        } else {
            echo "  ✓ No significant visual changes\n";
        }
    }
    
    private function saveReport(): void
    {
        $report = [
            'date' => date('Y-m-d H:i:s'),
            'passed' => $this->passed,
            'warnings' => $this->warnings,
            'failed' => $this->failed,
            'results' => $this->results,
        ];
        
        file_put_contents(
            SCREENSHOTS_DIR . '/visual_report.json',
            json_encode($report, JSON_PRETTY_PRINT)
        );
        
        // HTML report
        $this->generateHtmlReport($report);
    }
    
    private function generateHtmlReport(array $report): void
    {
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
        $html .= '<title>K-Docs Visual Report</title>';
        $html .= '<style>
            body { font-family: system-ui; margin: 20px; background: #f5f5f5; }
            .container { max-width: 1000px; margin: 0 auto; }
            .header { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
            .page { background: white; padding: 15px; border-radius: 8px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
            .identical { border-left: 4px solid #22c55e; }
            .minor { border-left: 4px solid #f59e0b; }
            .major { border-left: 4px solid #ef4444; }
            .status { padding: 4px 12px; border-radius: 20px; font-size: 12px; }
            .status.ok { background: #dcfce7; color: #166534; }
            .status.warn { background: #fef3c7; color: #92400e; }
            .status.fail { background: #fee2e2; color: #991b1b; }
        </style></head><body>';
        
        $html .= '<div class="container">';
        $html .= '<div class="header">';
        $html .= '<h1>K-Docs Visual Regression Report</h1>';
        $html .= "<p>Generated: {$report['date']}</p>";
        $html .= "<p>Identical: {$report['passed']} | Warnings: {$report['warnings']} | Changed: {$report['failed']}</p>";
        $html .= '</div>';
        
        foreach ($report['results'] as $name => $result) {
            $status = $result['status'] ?? 'skip';
            $class = match($status) {
                'identical' => 'identical',
                'minor' => 'minor',
                'major' => 'major',
                default => ''
            };
            $statusClass = match($status) {
                'identical' => 'ok',
                'minor' => 'warn',
                default => 'fail'
            };
            $summary = $result['summary'] ?? $result['reason'] ?? '-';
            
            $html .= "<div class=\"page $class\">";
            $html .= "<div><strong>$name</strong><br><small>$summary</small></div>";
            $html .= "<span class=\"status $statusClass\">$status</span>";
            $html .= '</div>';
        }
        
        $html .= '</div></body></html>';
        
        file_put_contents(SCREENSHOTS_DIR . '/visual_report.html', $html);
    }
}

// CLI
$command = $argv[1] ?? 'compare';
$baseUrl = $argv[2] ?? 'http://localhost/kdocs';

$test = new VisualTest($baseUrl);

switch ($command) {
    case 'capture':
        $test->capture();
        break;
    case 'set-reference':
        $test->capture();
        $test->setReference();
        break;
    case 'compare':
        $result = $test->compare();
        exit($result['failed'] > 0 ? 1 : 0);
        break;
    default:
        echo "Usage: php visual_test.php [capture|set-reference|compare] [base_url]\n";
}

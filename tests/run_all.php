<?php
/**
 * K-DOCS - Test Runner
 * Lance toutes les suites de tests et génère un rapport
 */

define('KDOCS_ROOT', dirname(__DIR__));
define('TESTS_DIR', __DIR__);
define('OUTPUT_DIR', TESTS_DIR . '/output');

// Créer dossier output
if (!is_dir(OUTPUT_DIR)) {
    mkdir(OUTPUT_DIR, 0755, true);
}

$baseUrl = $argv[1] ?? 'http://localhost/kdocs';
$generateHtml = in_array('--html', $argv);

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║                    K-DOCS TEST SUITE                         ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\nBase URL: $baseUrl\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n";

$startTime = microtime(true);
$allResults = [];
$totalPassed = 0;
$totalFailed = 0;

// ============================================
// 1. SMOKE TESTS
// ============================================
echo "\n\n▶ Running Smoke Tests...\n";
echo str_repeat('-', 60) . "\n";

$smokeOutput = shell_exec("php \"" . TESTS_DIR . "/smoke_test.php\" \"$baseUrl\" 2>&1");
echo $smokeOutput;

if (file_exists(OUTPUT_DIR . '/smoke_test_result.json')) {
    $smokeResult = json_decode(file_get_contents(OUTPUT_DIR . '/smoke_test_result.json'), true);
    $allResults['smoke'] = $smokeResult;
    $totalPassed += $smokeResult['passed'] ?? 0;
    $totalFailed += $smokeResult['failed'] ?? 0;
}

// ============================================
// 2. API TESTS
// ============================================
echo "\n\n▶ Running API Tests...\n";
echo str_repeat('-', 60) . "\n";

$apiOutput = shell_exec("php \"" . TESTS_DIR . "/api_test.php\" \"$baseUrl\" 2>&1");
echo $apiOutput;

if (file_exists(OUTPUT_DIR . '/api_test_result.json')) {
    $apiResult = json_decode(file_get_contents(OUTPUT_DIR . '/api_test_result.json'), true);
    $allResults['api'] = $apiResult;
    $totalPassed += $apiResult['passed'] ?? 0;
    $totalFailed += $apiResult['failed'] ?? 0;
}

// ============================================
// 3. INTEGRATION TESTS
// ============================================
echo "\n\n▶ Running Integration Tests...\n";
echo str_repeat('-', 60) . "\n";

$integrationOutput = shell_exec("php \"" . TESTS_DIR . "/integration_test.php\" \"$baseUrl\" 2>&1");
echo $integrationOutput;

if (file_exists(OUTPUT_DIR . '/integration_test_result.json')) {
    $integrationResult = json_decode(file_get_contents(OUTPUT_DIR . '/integration_test_result.json'), true);
    $allResults['integration'] = $integrationResult;
    $totalPassed += $integrationResult['passed'] ?? 0;
    $totalFailed += $integrationResult['failed'] ?? 0;
}

// ============================================
// 4. UI TESTS
// ============================================
echo "\n\n▶ Running UI Tests...\n";
echo str_repeat('-', 60) . "\n";

$uiOutput = shell_exec("php \"" . TESTS_DIR . "/ui_test.php\" \"$baseUrl\" 2>&1");
echo $uiOutput;

if (file_exists(OUTPUT_DIR . '/ui_test_result.json')) {
    $uiResult = json_decode(file_get_contents(OUTPUT_DIR . '/ui_test_result.json'), true);
    $allResults['ui'] = $uiResult;
    $totalPassed += $uiResult['passed'] ?? 0;
    $totalFailed += $uiResult['failed'] ?? 0;
}

// ============================================
// SUMMARY
// ============================================
$endTime = microtime(true);
$duration = round($endTime - $startTime, 2);
$totalTests = $totalPassed + $totalFailed;
$overallRate = $totalTests > 0 ? round(($totalPassed / $totalTests) * 100) : 0;

echo "\n\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║                      FINAL SUMMARY                           ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

echo "  Duration:    {$duration}s\n";
echo "  Total Tests: $totalTests\n";
echo "  Passed:      $totalPassed\n";
echo "  Failed:      $totalFailed\n";
echo "  Rate:        $overallRate%\n\n";

// Per-suite breakdown
echo "  Per Suite:\n";
foreach ($allResults as $suite => $result) {
    $p = $result['passed'] ?? 0;
    $f = $result['failed'] ?? 0;
    $r = $result['rate'] ?? (($p + $f) > 0 ? round(($p / ($p + $f)) * 100) : 0);
    $status = $f === 0 ? '✓' : '✗';
    echo "    $status $suite: $p passed, $f failed ($r%)\n";
}

echo "\n";
if ($totalFailed === 0) {
    echo "  ✅ ALL TESTS PASSED!\n";
} elseif ($overallRate >= 85) {
    echo "  ⚠️  TESTS PASSED WITH WARNINGS ($totalFailed failures)\n";
} else {
    echo "  ❌ TESTS FAILED ($totalFailed failures)\n";
}

// Save consolidated report
$consolidatedReport = [
    'date' => date('Y-m-d H:i:s'),
    'duration_seconds' => $duration,
    'base_url' => $baseUrl,
    'total_passed' => $totalPassed,
    'total_failed' => $totalFailed,
    'overall_rate' => $overallRate,
    'suites' => $allResults,
    'status' => $totalFailed === 0 ? 'PASS' : ($overallRate >= 85 ? 'WARNING' : 'FAIL')
];

file_put_contents(OUTPUT_DIR . '/consolidated_report.json', json_encode($consolidatedReport, JSON_PRETTY_PRINT));
echo "\n  Report saved: tests/output/consolidated_report.json\n";

// Generate HTML report if requested
if ($generateHtml) {
    $html = generateHtmlReport($consolidatedReport, $allResults);
    file_put_contents(OUTPUT_DIR . '/report.html', $html);
    echo "  HTML report: tests/output/report.html\n";
}

echo "\n";
exit($totalFailed > 0 ? 1 : 0);

// ============================================
// HTML Report Generator
// ============================================
function generateHtmlReport(array $report, array $suites): string
{
    $status = $report['status'];
    $statusColor = match($status) {
        'PASS' => '#22c55e',
        'WARNING' => '#f59e0b',
        default => '#ef4444'
    };
    
    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>K-Docs Test Report</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 20px; background: #f3f4f6; }
        .container { max-width: 900px; margin: 0 auto; }
        .header { background: white; padding: 30px; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .header h1 { margin: 0 0 10px 0; }
        .status { display: inline-block; padding: 8px 16px; border-radius: 20px; color: white; font-weight: bold; background: $statusColor; }
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-top: 20px; }
        .stat { text-align: center; }
        .stat-value { font-size: 2em; font-weight: bold; }
        .stat-label { color: #6b7280; font-size: 0.9em; }
        .suite { background: white; padding: 20px; border-radius: 12px; margin-bottom: 15px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .suite h3 { margin: 0 0 15px 0; display: flex; align-items: center; gap: 10px; }
        .suite-pass { color: #22c55e; }
        .suite-fail { color: #ef4444; }
        .progress { height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden; }
        .progress-bar { height: 100%; background: #22c55e; }
        .meta { color: #6b7280; font-size: 0.85em; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>K-Docs Test Report</h1>
            <span class="status">$status</span>
            
            <div class="stats">
                <div class="stat">
                    <div class="stat-value">{$report['total_passed']}</div>
                    <div class="stat-label">Passed</div>
                </div>
                <div class="stat">
                    <div class="stat-value">{$report['total_failed']}</div>
                    <div class="stat-label">Failed</div>
                </div>
                <div class="stat">
                    <div class="stat-value">{$report['overall_rate']}%</div>
                    <div class="stat-label">Success Rate</div>
                </div>
                <div class="stat">
                    <div class="stat-value">{$report['duration_seconds']}s</div>
                    <div class="stat-label">Duration</div>
                </div>
            </div>
        </div>
HTML;

    foreach ($suites as $name => $suite) {
        $p = $suite['passed'] ?? 0;
        $f = $suite['failed'] ?? 0;
        $total = $p + $f;
        $rate = $total > 0 ? round(($p / $total) * 100) : 0;
        $icon = $f === 0 ? '✓' : '✗';
        $class = $f === 0 ? 'suite-pass' : 'suite-fail';
        
        $html .= <<<HTML
        <div class="suite">
            <h3><span class="$class">$icon</span> $name</h3>
            <div class="progress"><div class="progress-bar" style="width: $rate%"></div></div>
            <p>$p passed, $f failed ($rate%)</p>
        </div>
HTML;
    }

    $html .= <<<HTML
        <div class="meta">
            <p>Generated: {$report['date']}</p>
            <p>Base URL: {$report['base_url']}</p>
        </div>
    </div>
</body>
</html>
HTML;

    return $html;
}

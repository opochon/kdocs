<?php
/**
 * GEDv1 — Smoke exhaustif pages + API GET + assets
 * Usage: php tests/full_pages_smoke_test.php [baseUrl]
 */
declare(strict_types=1);

$baseUrl = rtrim($argv[1] ?? 'http://127.0.0.1:8765/kdocs', '/');
$sessionId = getenv('GEDV1_DEBUG_SESSION') ?: 'full-smoke';
$logFile = __DIR__ . '/../storage/logs/debug-' . $sessionId . '.log';
$reportFile = dirname(__DIR__) . '/docs/SMOKE-FULL-REPORT.md';
$runId = 'full-smoke-' . date('Ymd-His');
$passed = 0;
$failed = 0;
$results = [];

function fullLog(string $location, string $message, array $data = [], ?string $hypothesisId = null): void
{
    global $logFile, $sessionId, $runId;
    file_put_contents($logFile, json_encode([
        'sessionId' => $sessionId,
        'runId' => $runId,
        'hypothesisId' => $hypothesisId,
        'location' => $location,
        'message' => $message,
        'data' => $data,
        'timestamp' => (int) round(microtime(true) * 1000),
    ], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

function httpFetch(string $url, array $opts = []): array
{
    $method = ($opts['post'] ?? false) ? 'POST' : 'GET';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPGET => $method === 'GET',
        CURLOPT_POSTFIELDS => ($opts['post'] ?? false) ? ($opts['postFields'] ?? null) : null,
        CURLOPT_COOKIEJAR => $opts['cookieJar'] ?? '',
        CURLOPT_COOKIEFILE => $opts['cookieFile'] ?? '',
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [
        'code' => $code,
        'headers' => substr($raw, 0, $hs),
        'body' => substr($raw, $hs),
    ];
}

function checkRoute(string $label, string $path, array $opts, array $expectCodes = [200, 302], bool $optional = false): array
{
    global $passed, $failed, $results, $baseUrl;

    $r = httpFetch($baseUrl . $path, $opts);
    $phpErr = str_contains($r['body'], 'Fatal error')
        || str_contains($r['body'], 'Uncaught')
        || str_contains($r['body'], 'Parse error');
    $ok = in_array($r['code'], $expectCodes, true) && !$phpErr;

    $entry = [
        'label' => $label,
        'path' => $path,
        'http' => $r['code'],
        'ok' => $ok,
        'optional' => $optional,
        'phpError' => $phpErr,
        'size' => strlen($r['body']),
    ];
    $results[] = $entry;

    if ($ok) {
        $passed++;
        $tag = $optional ? ' (opt)' : '';
        echo "\033[32m  OK\033[0m $label ($path) HTTP {$r['code']}$tag\n";
    } elseif ($optional && $r['code'] === 404) {
        $passed++;
        echo "\033[33m  SKIP\033[0m $label ($path) HTTP 404 (module desactive ou vide)\n";
        $entry['ok'] = true;
        $entry['skipped'] = true;
    } else {
        $failed++;
        echo "\033[31m  KO\033[0m $label ($path) HTTP {$r['code']}" . ($phpErr ? ' PHP_ERROR' : '') . "\n";
    }

    fullLog('full_smoke:route', $label, $entry, $ok ? null : 'FULL');
    return $entry;
}

echo "\n=== GEDv1 FULL PAGES SMOKE ===\nBase: $baseUrl\nRun: $runId\n\n";
fullLog('full_smoke:start', 'Demarrage', ['baseUrl' => $baseUrl], 'FULL');

// Reset rate-limit cache (smoke en rafale)
$rateLimitDir = dirname(__DIR__) . '/storage/cache/ratelimit';
if (is_dir($rateLimitDir)) {
    foreach (glob($rateLimitDir . '/*.json') ?: [] as $f) {
        @unlink($f);
    }
}

// Redirect base path sans slash
$baseNoSlash = preg_replace('#/+$#', '', $baseUrl);
$redirect = httpFetch($baseNoSlash);
$redirectOk = $redirect['code'] === 302
    && (str_contains($redirect['headers'], $baseNoSlash . '/') || str_contains($redirect['headers'], 'Location: /kdocs/'));
if ($redirectOk) {
    $passed++;
    echo "\033[32m  OK\033[0m Redirect /kdocs -> /kdocs/ HTTP {$redirect['code']}\n";
} else {
    $failed++;
    echo "\033[31m  KO\033[0m Redirect /kdocs HTTP {$redirect['code']}\n";
}
fullLog('full_smoke:redirect', 'base path redirect', [
    'http' => $redirect['code'],
    'ok' => $redirectOk,
], 'FULL');

$cookie = sys_get_temp_dir() . '/gedv1_full_smoke.txt';
@unlink($cookie);

// Pages publiques
$public = [
    'Login' => '/login',
    'Health JSON' => '/health',
    'Asset tailwind.css' => '/public/css/tailwind.css',
    'Asset app.js' => '/public/js/app.js',
];
foreach ($public as $label => $path) {
    $expect = $path === '/public/css/tailwind.css' || $path === '/public/js/app.js' ? [200] : [200];
    checkRoute($label, $path, [], $expect);
}

// Auth
$auth = httpFetch($baseUrl . '/login', [
    'post' => true,
    'postFields' => http_build_query(['username' => 'root', 'password' => '']),
    'cookieJar' => $cookie,
    'cookieFile' => $cookie,
]);
$authOk = $auth['code'] === 302 && str_contains($auth['headers'], 'kdocs_session');
if ($authOk) {
    $passed++;
    echo "\033[32m  OK\033[0m POST /login root\n";
} else {
    $failed++;
    echo "\033[31m  KO\033[0m POST /login root HTTP {$auth['code']}\n";
}
fullLog('full_smoke:auth', 'POST login', ['http' => $auth['code'], 'ok' => $authOk], 'FULL');

$authOpts = ['cookieFile' => $cookie];

// Pages HTML authentifiées
$htmlPages = [
    'Dashboard' => '/',
    'Dashboard alias' => '/dashboard',
    'Documents' => '/documents',
    'Upload' => '/documents/upload',
    'Mes tâches' => '/mes-taches',
    'Chat IA' => '/chat',
    'Tasks' => '/tasks',
    'Tasks create' => '/tasks/create',
    'Admin hub' => '/admin',
    'Admin diagnostic' => '/admin/diagnostic',
    'Admin users' => '/admin/users',
    'Admin users create' => '/admin/users/create',
    'Admin settings' => '/admin/settings',
    'Admin workflows' => '/admin/workflows',
    'Admin workflows create' => '/admin/workflows/create',
    'Admin indexing' => '/admin/indexing',
    'Admin tags' => '/admin/tags',
    'Admin tags create' => '/admin/tags/create',
    'Admin correspondents' => '/admin/correspondents',
    'Admin correspondents create' => '/admin/correspondents/create',
    'Admin consume' => '/admin/consume',
    'Admin document-types' => '/admin/document-types',
    'Admin custom-fields' => '/admin/custom-fields',
    'Admin storage-paths' => '/admin/storage-paths',
    'Admin webhooks' => '/admin/webhooks',
    'Admin audit-logs' => '/admin/audit-logs',
    'Admin export-import' => '/admin/export-import',
    'Admin mail-accounts' => '/admin/mail-accounts',
    'Admin scheduled-tasks' => '/admin/scheduled-tasks',
    'Admin classification-fields' => '/admin/classification-fields',
    'Admin attribution-rules' => '/admin/attribution-rules',
    'Admin snapshots' => '/admin/snapshots',
    'Admin roles' => '/admin/roles',
    'Admin user-groups' => '/admin/user-groups',
    'Admin api-usage' => '/admin/api-usage',
    'K-Time dashboard' => '/time',
    'K-Time entries' => '/time/entries',
    'Invoices inbox' => '/invoices',
    'Invoices inbox alt' => '/invoices/inbox',
];

foreach ($htmlPages as $label => $path) {
    $optional = str_starts_with($path, '/invoices');
    checkRoute($label, $path, $authOpts, [200, 302], $optional);
}

// API GET authentifiées
$apiPages = [
    'API workflows' => '/api/workflows',
    'API AI status' => '/api/ai/status',
    'API tags' => '/api/tags',
    'API correspondents' => '/api/correspondents',
    'API document-types' => '/api/document-types',
    'API classification-fields' => '/api/classification-fields',
    'API tasks counts' => '/api/tasks/counts',
    'API notifications count' => '/api/notifications/count',
    'API folders tree' => '/api/folders/tree',
    'API validation stats' => '/api/validation/statistics',
    'API embeddings status' => '/api/embeddings/status',
    'API semantic-search status' => '/api/semantic-search/status',
    'API onlyoffice status' => '/api/onlyoffice/status',
    'API workflow node-catalog' => '/api/workflow/node-catalog',
    'API roles' => '/api/roles',
    'API notes recipients' => '/api/notes/recipients',
    'API snapshots latest' => '/api/snapshots/latest',
    'API invoices pending' => '/invoices/api/pending',
    'API invoices stats' => '/invoices/api/stats',
];

foreach ($apiPages as $label => $path) {
    usleep(700_000); // RateLimitMiddleware : 100 req/min
    $optional = str_contains($path, '/invoices/') || $path === '/api/snapshots/latest';
    checkRoute($label, $path, $authOpts, [200], $optional);
}

// Rapport markdown
$md = "# GEDv1 — Rapport smoke complet\n\n";
$md .= "Date : " . date('Y-m-d H:i:s') . "\n";
$md .= "Base URL : `$baseUrl`\n";
$md .= "Run ID : `$runId`\n\n";
$md .= "## Résumé\n\n";
$md .= "| Métrique | Valeur |\n";
$md .= "|----------|--------|\n";
$md .= "| Total testé | " . count($results) . " |\n";
$md .= "| OK | $passed |\n";
$md .= "| KO | $failed |\n\n";

$koList = array_filter($results, fn($r) => !$r['ok']);
if ($koList) {
    $md .= "## Échecs\n\n";
    $md .= "| Page | Path | HTTP | PHP error |\n";
    $md .= "|------|------|------|-----------|\n";
    foreach ($koList as $r) {
        $md .= "| {$r['label']} | `{$r['path']}` | {$r['http']} | " . ($r['phpError'] ? 'oui' : 'non') . " |\n";
    }
    $md .= "\n";
}

$md .= "## Tous les résultats\n\n";
$md .= "| Status | Page | Path | HTTP |\n";
$md .= "|--------|------|------|------|\n";
foreach ($results as $r) {
    $md .= "| " . ($r['ok'] ? 'OK' : 'KO') . " | {$r['label']} | `{$r['path']}` | {$r['http']} |\n";
}

file_put_contents($reportFile, $md);

fullLog('full_smoke:summary', 'Fin', [
    'passed' => $passed,
    'failed' => $failed,
    'total' => count($results),
    'report' => $reportFile,
], 'FULL');

echo "\nResultat: $passed OK, $failed KO\n";
echo "Rapport: $reportFile\n";
echo "Logs: $logFile\n";
exit($failed > 0 ? 1 : 0);

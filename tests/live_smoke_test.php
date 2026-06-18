<?php
/**
 * GEDv1 — Live smoke tests (HTTP + login + pages + assets)
 * Usage: php tests/live_smoke_test.php [baseUrl]
 */
declare(strict_types=1);

$baseUrl = rtrim($argv[1] ?? 'http://127.0.0.1:8765/kdocs', '/');
$logFile = 'F:/DATA/DEVELOPPEMENT/htmleditor_v3/htmleditor/debug-4af063.log';
$sessionId = '4af063';
$runId = 'live-smoke-' . date('Ymd-His');
$passed = 0;
$failed = 0;

function liveLog(string $location, string $message, array $data = [], ?string $hypothesisId = null): void
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

function http(string $url, array $opts = []): array
{
    $method = ($opts['post'] ?? false) ? 'POST' : 'GET';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
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
    return ['code' => $code, 'headers' => substr($raw, 0, $hs), 'body' => substr($raw, $hs)];
}

function check(string $name, bool $ok, array $data = []): void
{
    global $passed, $failed, $runId;
    if ($ok) {
        $passed++;
        echo "\033[32m  OK\033[0m $name\n";
    } else {
        $failed++;
        echo "\033[31m  KO\033[0m $name\n";
    }
    liveLog('live_smoke:check', $name, array_merge($data, ['ok' => $ok]), $ok ? null : 'LIVE');
}

echo "\n=== GEDv1 LIVE SMOKE ===\nBase: $baseUrl\n\n";
liveLog('live_smoke:start', 'Demarrage', ['baseUrl' => $baseUrl], 'LIVE');

// Serveur joignable
$r = http($baseUrl . '/health');
check('GET /health', $r['code'] === 200, ['http' => $r['code']]);

// Asset CSS
$css = http($baseUrl . '/public/css/tailwind.css');
check('GET tailwind.css', $css['code'] === 200 && strlen($css['body']) > 10000, [
    'http' => $css['code'], 'size' => strlen($css['body']),
]);

// Login page stylable
$login = http($baseUrl . '/login');
$hasCssLink = str_contains($login['body'], '/public/css/tailwind.css');
check('GET /login + lien CSS', $login['code'] === 200 && $hasCssLink, ['http' => $login['code']]);

// Auth root / vide
$cookie = sys_get_temp_dir() . '/gedv1_live_smoke.txt';
@unlink($cookie);
$auth = http($baseUrl . '/login', [
    'post' => true,
    'postFields' => http_build_query(['username' => 'root', 'password' => '']),
    'cookieJar' => $cookie,
    'cookieFile' => $cookie,
]);
$authOk = $auth['code'] === 302 && str_contains($auth['headers'], 'kdocs_session');
check('POST /login root', $authOk, ['http' => $auth['code']]);

$pages = ['/' => 'dashboard', '/documents' => 'documents', '/admin' => 'admin', '/admin/settings' => 'settings', '/chat' => 'chat'];
foreach ($pages as $path => $label) {
    $p = http($baseUrl . $path, ['cookieFile' => $cookie]);
    $err = str_contains($p['body'], 'Fatal error') || str_contains($p['body'], 'Uncaught');
    $ok = $p['code'] >= 200 && $p['code'] < 400 && !$err;
    check("GET $path ($label)", $ok, ['http' => $p['code'], 'err' => $err]);
}

liveLog('live_smoke:summary', 'Fin', ['passed' => $passed, 'failed' => $failed], 'LIVE');
echo "\nResultat: $passed OK, $failed KO\n";
exit($failed > 0 ? 1 : 0);

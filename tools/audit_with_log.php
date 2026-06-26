<?php
/**
 * Audit GEDv1 avec logs NDJSON pour debug session Cursor.
 * Usage: php tools/audit_with_log.php
 */
declare(strict_types=1);

$logFile = __DIR__ . '/../storage/logs/debug-audit.log';
$baseUrl = getenv('AUDIT_BASE_URL') ?: 'http://127.0.0.1:8766/kdocs';
$host = parse_url($baseUrl, PHP_URL_HOST) ?: '127.0.0.1';
$port = (int) (parse_url($baseUrl, PHP_URL_PORT) ?: 8766);
$sessionId = getenv('GEDV1_DEBUG_SESSION') ?: 'audit';
$runId = 'audit-' . date('Ymd-His');

$GLOBALS['_audit_ctx'] = [
    'logFile' => $logFile,
    'sessionId' => $sessionId,
    'runId' => $runId,
];

function auditLog(string $location, string $message, array $data = [], ?string $hypothesisId = null): void
{
    $ctx = $GLOBALS['_audit_ctx'];
    $line = json_encode([
        'sessionId' => $ctx['sessionId'],
        'runId' => $ctx['runId'],
        'hypothesisId' => $hypothesisId,
        'location' => $location,
        'message' => $message,
        'data' => $data,
        'timestamp' => (int) round(microtime(true) * 1000),
    ], JSON_UNESCAPED_UNICODE);
    file_put_contents($ctx['logFile'], $line . "\n", FILE_APPEND | LOCK_EX);
}

function httpFetch(string $url, array $opts = []): array
{
    $ch = curl_init($url);
    $method = ($opts['post'] ?? false) ? 'POST' : 'GET';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_COOKIEJAR => $opts['cookieJar'] ?? '',
        CURLOPT_COOKIEFILE => $opts['cookieFile'] ?? '',
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => ($opts['post'] ?? false) ? ($opts['postFields'] ?? null) : null,
        CURLOPT_HTTPGET => $method === 'GET',
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [
        'code' => $code,
        'error' => $err,
        'headers' => substr((string) $raw, 0, $headerSize),
        'body' => substr((string) $raw, $headerSize),
    ];
}

function portOpen(string $host, int $port): bool
{
    $ch = curl_init("http://{$host}:{$port}/kdocs/login");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code > 0;
}

echo "=== GEDv1 audit_with_log (session $sessionId) ===\n";

auditLog('audit:start', 'Audit demarre', [
    'baseUrl' => $baseUrl,
    'php' => PHP_VERSION,
    'cwd' => getcwd(),
], 'E');

// Hypothèse E : serveur absent ou mauvaise commande de lancement
$serverUp = portOpen($host, $port);
auditLog('audit:server', 'Port check', ['open' => $serverUp, 'host' => $host, 'port' => $port], 'E');

if (!$serverUp) {
    auditLog('audit:blocker', "Serveur arrete — lancer: php -S 127.0.0.1:{$port} router.php", [], 'E');
    echo "BLOCKER: serveur non demarre. Voir storage/logs/debug-audit.log\n";
    exit(2);
}

// Hypothèse A : assets CSS 404 (router.php vs -t .)
$cssUrl = $baseUrl . '/public/css/tailwind.css';
$css = httpFetch($cssUrl);
auditLog('audit:asset', 'tailwind.css', [
    'url' => $cssUrl,
    'http' => $css['code'],
    'bodyLen' => strlen($css['body']),
    'contentType' => preg_match('/Content-Type:\s*([^\r\n]+)/i', $css['headers'], $m) ? trim($m[1]) : null,
], 'A');

// Hypothèse B : page login + liens asset dans HTML
$login = httpFetch($baseUrl . '/login');
$cssHrefs = [];
if (preg_match_all('/href="([^"]+\.css[^"]*)"/', $login['body'], $m)) {
    $cssHrefs = $m[1];
}
auditLog('audit:login', 'GET /login', [
    'http' => $login['code'],
    'cssHrefs' => $cssHrefs,
    'hasTailwindClasses' => str_contains($login['body'], 'bg-gray-50'),
    'bodySnippet' => substr(strip_tags($login['body']), 0, 200),
], 'B');

foreach ($cssHrefs as $href) {
    $assetUrl = str_starts_with($href, 'http') ? $href : 'http://' . $host . ':' . $port . (str_starts_with($href, '/') ? '' : '/') . $href;
    $r = httpFetch($assetUrl);
    auditLog('audit:asset', 'CSS from login page', [
        'href' => $href,
        'resolved' => $assetUrl,
        'http' => $r['code'],
        'size' => strlen($r['body']),
    ], 'A');
}

// Login (hypothèse C : auth)
$cookieFile = sys_get_temp_dir() . '/gedv1_audit_cookies.txt';
@unlink($cookieFile);
$post = httpFetch($baseUrl . '/login', [
    'post' => true,
    'postFields' => http_build_query(['username' => 'root', 'password' => '']),
    'cookieJar' => $cookieFile,
    'cookieFile' => $cookieFile,
]);
$loginOk = $post['code'] === 302 && str_contains($post['headers'], 'kdocs_session');
auditLog('audit:auth', 'POST /login root', [
    'http' => $post['code'],
    'location' => preg_match('/Location:\s*([^\r\n]+)/i', $post['headers'], $lm) ? trim($lm[1]) : null,
    'sessionCookie' => $loginOk,
], 'C');

$pages = [
    '/' => 'dashboard',
    '/documents' => 'documents',
    '/admin' => 'admin',
    '/admin/settings' => 'admin-settings',
    '/admin/users' => 'admin-users',
    '/health' => 'health',
    '/chat' => 'chat',
    '/tasks' => 'tasks',
];

$ok = 0;
$ko = 0;

foreach ($pages as $path => $label) {
    $opts = ['cookieFile' => $cookieFile];
    if ($path === '/health') {
        $opts = [];
    }
    $r = httpFetch($baseUrl . $path, $opts);
    $isError = $r['code'] >= 500 || str_contains($r['body'], 'Fatal error') || str_contains($r['body'], 'Uncaught');
    if ($r['code'] >= 200 && $r['code'] < 400 && !$isError) {
        $ok++;
    } else {
        $ko++;
    }
    auditLog('audit:page', $label, [
        'path' => $path,
        'http' => $r['code'],
        'phpError' => $isError,
        'bodyLen' => strlen($r['body']),
        'snippet' => substr(strip_tags($r['body']), 0, 120),
    ], 'D');
}

// Hypothèse D : MySQL
try {
    putenv('GEDV1_DEBUG_SESSION=' . $sessionId);
    require_once dirname(__DIR__) . '/app/helpers.php';
    $cfg = require dirname(__DIR__) . '/config/config.php';
    $db = $cfg['database'];
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']);
    $pdo = new PDO($dsn, $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    auditLog('audit:mysql', 'Connexion BDD', ['port' => $db['port'], 'users' => $count], 'D');
} catch (Throwable $e) {
    auditLog('audit:mysql', 'Connexion BDD echouee', ['error' => $e->getMessage()], 'D');
}

auditLog('audit:summary', 'Fin audit', [
    'pagesOk' => $ok,
    'pagesKo' => $ko,
    'cssOk' => $css['code'] === 200,
    'loginOk' => $loginOk,
], null);

echo "Pages OK: $ok | KO: $ko | CSS: {$css['code']} | Login: " . ($loginOk ? 'OK' : 'KO') . "\n";
echo "Logs: $logFile\n";
exit($ko > 0 || $css['code'] !== 200 || !$loginOk ? 1 : 0);

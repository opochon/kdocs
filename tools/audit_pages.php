<?php
/**
 * Audit HTTP rapide des pages K-Docs (session root / mot de passe vide).
 * Usage : php tools/audit_pages.php
 */
$base = 'http://localhost:8765';
$cookieFile = sys_get_temp_dir() . '/kdocs_audit_cookies.txt';
@unlink($cookieFile);

function http(string $url, string $method = 'GET', array $opts = []): array
{
    global $cookieFile;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
    ] + $opts);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = '';
    if (is_string($raw) && str_contains($raw, "\r\n\r\n")) {
        [, $body] = explode("\r\n\r\n", $raw, 2);
    }
    return ['code' => $code, 'body' => $body];
}

http($base . '/kdocs/login', 'POST', [CURLOPT_POSTFIELDS => 'username=root&password=']);

$pages = [
    '/kdocs/login' => 'Login',
    '/kdocs/' => 'Dashboard',
    '/kdocs/documents' => 'Documents',
    '/kdocs/documents/upload' => 'Upload',
    '/kdocs/admin' => 'Admin hub',
    '/kdocs/admin/users' => 'Users',
    '/kdocs/admin/settings' => 'Settings',
    '/kdocs/admin/workflows' => 'Workflows',
    '/kdocs/admin/indexing' => 'Indexing',
    '/kdocs/admin/diagnostic' => 'Diagnostic',
    '/kdocs/admin/tags' => 'Tags',
    '/kdocs/admin/correspondents' => 'Correspondents',
    '/kdocs/admin/consume' => 'Consume',
    '/kdocs/tasks' => 'Tasks',
    '/kdocs/mes-taches' => 'Mes tâches',
    '/kdocs/chat' => 'Chat IA',
    '/kdocs/time' => 'K-Time',
    '/kdocs/invoices' => 'Invoices',
    '/kdocs/health' => 'Health JSON',
    '/kdocs/api/workflows' => 'API workflows',
    '/kdocs/api/ai/status' => 'API AI status',
    '/kdocs/public/css/tailwind.css' => 'Asset tailwind',
    '/kdocs/public/js/app.js' => 'Asset app.js',
    '/public/css/tailwind.css' => 'Asset direct /public',
];

echo "AUDIT K-Docs — $base\n";
echo str_repeat('-', 72) . "\n";
printf("%-6s %-35s %s\n", 'HTTP', 'URL', 'Notes');
echo str_repeat('-', 72) . "\n";

foreach ($pages as $path => $label) {
    $r = http($base . $path);
    $notes = [];
    if ($r['code'] === 302) {
        $notes[] = 'redirect';
    }
    if (str_contains($r['body'], 'Fatal error') || str_contains($r['body'], 'Parse error')) {
        $notes[] = 'PHP_ERROR';
    }
    if (str_contains($r['body'], 'Installation requise')) {
        $notes[] = 'NO_COMPOSER';
    }
    if ($path === '/kdocs/public/css/tailwind.css' && $r['code'] !== 200) {
        $notes[] = 'CSS_BROKEN';
    }
    printf("%-6d %-35s %s\n", $r['code'], $path, $label . ($notes ? ' [' . implode(',', $notes) . ']' : ''));
}

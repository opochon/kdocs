<?php
declare(strict_types=1);

$baseUrl = rtrim($argv[1] ?? 'http://127.0.0.1:8765/kdocs', '/');
$path = $argv[2] ?? '2024/lot-controle';
$cookie = sys_get_temp_dir() . '/gedv1_api_test.txt';
@unlink($cookie);

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

$auth = http($baseUrl . '/login', [
    'post' => true,
    'postFields' => http_build_query(['username' => 'root', 'password' => '']),
    'cookieJar' => $cookie,
    'cookieFile' => $cookie,
]);
echo "Login HTTP {$auth['code']}\n";

$api = http($baseUrl . '/api/folders/documents?path=' . rawurlencode($path), ['cookieFile' => $cookie]);
echo "API HTTP {$api['code']}\n";
if ($api['code'] === 200) {
    $j = json_decode($api['body'], true);
    echo 'success=' . ($j['success'] ?? '?') . ' total=' . ($j['pagination']['total'] ?? '?') . "\n";
} else {
    echo substr($api['body'], 0, 500) . "\n";
}

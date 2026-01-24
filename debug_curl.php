<?php
/**
 * Mesure le temps de chargement RÉEL de /documents via cURL
 */

$url = 'http://localhost/kdocs/documents';
$cookieFile = __DIR__ . '/test_cookies.txt';

// D'abord se connecter
$loginUrl = 'http://localhost/kdocs/login';
$ch = curl_init($loginUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'username' => 'root',
        'password' => ''
    ]),
    CURLOPT_FOLLOWLOCATION => true,
]);
curl_exec($ch);
curl_close($ch);

// Maintenant mesurer /documents
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_HEADER => true,
]);

$start = microtime(true);
$result = curl_exec($ch);
$time = round((microtime(true) - $start) * 1000, 2);

$info = curl_getinfo($ch);
curl_close($ch);

header('Content-Type: application/json');
echo json_encode([
    'url' => $url,
    'http_code' => $info['http_code'],
    'total_time_ms' => $time,
    'curl_total_time_ms' => round($info['total_time'] * 1000, 2),
    'curl_namelookup_ms' => round($info['namelookup_time'] * 1000, 2),
    'curl_connect_ms' => round($info['connect_time'] * 1000, 2),
    'curl_starttransfer_ms' => round($info['starttransfer_time'] * 1000, 2),
    'size_download' => $info['size_download'],
    'speed_download' => $info['speed_download'],
    'breakdown' => [
        'dns_lookup' => round($info['namelookup_time'] * 1000, 2) . 'ms',
        'tcp_connect' => round(($info['connect_time'] - $info['namelookup_time']) * 1000, 2) . 'ms',
        'server_processing' => round(($info['starttransfer_time'] - $info['connect_time']) * 1000, 2) . 'ms',
        'content_transfer' => round(($info['total_time'] - $info['starttransfer_time']) * 1000, 2) . 'ms',
    ]
], JSON_PRETTY_PRINT);

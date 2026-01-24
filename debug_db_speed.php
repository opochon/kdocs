<?php
/**
 * Test de connexion DB - localhost vs 127.0.0.1
 */

$configs = [
    'localhost' => ['host' => 'localhost', 'port' => 3307],
    '127.0.0.1' => ['host' => '127.0.0.1', 'port' => 3307],
];

$results = [];

foreach ($configs as $name => $cfg) {
    $start = microtime(true);
    
    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=kdocs;charset=utf8mb4', $cfg['host'], $cfg['port']);
        $pdo = new PDO($dsn, 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo = null; // Fermer
        
        $time = (microtime(true) - $start) * 1000;
        $results[$name] = round($time, 2) . ' ms';
    } catch (Exception $e) {
        $results[$name] = 'ERROR: ' . $e->getMessage();
    }
}

header('Content-Type: application/json');
echo json_encode($results, JSON_PRETTY_PRINT);

<?php
/**
 * Test de connexion DB - Via Database::getInstance() vs direct
 */

require_once __DIR__ . '/app/autoload.php';

use KDocs\Core\Database;

$results = [];

// Test 1: Direct PDO
$start = microtime(true);
$dsn = 'mysql:host=localhost;port=3307;dbname=kdocs;charset=utf8mb4';
$pdo1 = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo1 = null;
$results['direct_pdo'] = round((microtime(true) - $start) * 1000, 2) . ' ms';

// Test 2: Via Database::getInstance() (première fois)
$start = microtime(true);
$pdo2 = Database::getInstance();
$results['database_singleton_first'] = round((microtime(true) - $start) * 1000, 2) . ' ms';

// Test 3: Via Database::getInstance() (seconde fois - devrait être instantané)
$start = microtime(true);
$pdo3 = Database::getInstance();
$results['database_singleton_second'] = round((microtime(true) - $start) * 1000, 2) . ' ms';

header('Content-Type: application/json');
echo json_encode($results, JSON_PRETTY_PRINT);

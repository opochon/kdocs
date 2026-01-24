<?php
// Debug rapide pour voir les documents en DB
require __DIR__ . '/vendor/autoload.php';

$config = include __DIR__ . '/config/config.php';

try {
    $dsn = "mysql:host={$config['database']['host']};port={$config['database']['port']};dbname={$config['database']['name']};charset={$config['database']['charset']}";
    $pdo = new PDO($dsn, $config['database']['user'], $config['database']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    header('Content-Type: application/json');
    
    // Récupérer quelques documents
    $stmt = $pdo->query("SELECT id, original_filename, relative_path, LEFT(file_path, 100) as file_path_short, status FROM documents WHERE deleted_at IS NULL LIMIT 20");
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Stats par relative_path
    $stmt2 = $pdo->query("SELECT relative_path, COUNT(*) as cnt FROM documents WHERE deleted_at IS NULL GROUP BY relative_path ORDER BY cnt DESC LIMIT 20");
    $stats = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'database' => $config['database']['name'],
        'port' => $config['database']['port'],
        'documents_sample' => $docs,
        'relative_path_stats' => $stats
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}

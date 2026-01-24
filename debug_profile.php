<?php
/**
 * Script de profiling pour identifier la lenteur
 */

$times = [];
$times['start'] = microtime(true);

require_once __DIR__ . '/app/autoload.php';
$times['autoload'] = microtime(true);

use KDocs\Core\Database;
use KDocs\Services\CrawlerAutoTrigger;

// Simulation de ce que fait le controller au début
$times['before_autotrigger'] = microtime(true);

try {
    $autoTrigger = new CrawlerAutoTrigger();
    $times['autotrigger_created'] = microtime(true);
    
    if ($autoTrigger->shouldRun() && $autoTrigger->hasQueues()) {
        $times['before_trigger'] = microtime(true);
        $autoTrigger->trigger();
        $times['after_trigger'] = microtime(true);
    } else {
        $times['autotrigger_skipped'] = microtime(true);
    }
} catch (\Exception $e) {
    $times['autotrigger_error'] = microtime(true);
}

$times['after_autotrigger'] = microtime(true);

// Connexion DB
$db = Database::getInstance();
$times['db_connected'] = microtime(true);

// Requête SQL typique pour le dossier toclassify
$normalizedPath = 'toclassify';
$pathPrefix = $normalizedPath . '/';
$directPattern = $pathPrefix . '%';
$excludeSubfolders = $pathPrefix . '%/%';

$times['before_sql'] = microtime(true);

$sql = "
    SELECT d.*, dt.label as document_type_label, c.name as correspondent_name
    FROM documents d
    LEFT JOIN document_types dt ON d.document_type_id = dt.id
    LEFT JOIN correspondents c ON d.correspondent_id = c.id
    WHERE d.deleted_at IS NULL
    AND d.relative_path IS NOT NULL
    AND d.relative_path != ''
    AND d.relative_path LIKE ?
    AND d.relative_path NOT LIKE ?
    ORDER BY d.created_at DESC
    LIMIT 50 OFFSET 0
";
$stmt = $db->prepare($sql);
$stmt->bindValue(1, $directPattern);
$stmt->bindValue(2, $excludeSubfolders);
$stmt->execute();
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

$times['after_sql'] = microtime(true);
$times['end'] = microtime(true);

// Calculer les deltas
$results = [];
$prevKey = null;
foreach ($times as $key => $time) {
    if ($prevKey !== null) {
        $delta = ($time - $times[$prevKey]) * 1000;
        $results[] = [
            'step' => "$prevKey → $key",
            'time_ms' => round($delta, 2)
        ];
    }
    $prevKey = $key;
}

$total = ($times['end'] - $times['start']) * 1000;

header('Content-Type: application/json');
echo json_encode([
    'total_ms' => round($total, 2),
    'documents_found' => count($documents),
    'breakdown' => $results
], JSON_PRETTY_PRINT);

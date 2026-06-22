<?php
/**
 * Bench ingestion upload → OCR → search (B1.10)
 *
 * Usage offline (structure) : php tools/bench-ingest.php
 * Usage live (BDD requise)   : php tools/bench-ingest.php --live
 */
declare(strict_types=1);

define('KDOCS_ROOT', dirname(__DIR__));

require KDOCS_ROOT . '/vendor/autoload.php';
require_once KDOCS_ROOT . '/app/helpers.php';

$live = in_array('--live', $argv ?? [], true);

echo "K-Docs — bench ingestion\n";
echo str_repeat('-', 40) . "\n";

$checks = [
    'IngestEngineRouter' => is_file(KDOCS_ROOT . '/app/Services/Ingest/IngestEngineRouter.php'),
    'ClassifyDocumentJob' => is_file(KDOCS_ROOT . '/app/Jobs/ClassifyDocumentJob.php'),
    'SearchService' => is_file(KDOCS_ROOT . '/app/Services/SearchService.php'),
    'queue_worker' => is_file(KDOCS_ROOT . '/app/workers/queue_worker.php'),
];

foreach ($checks as $name => $ok) {
    echo ($ok ? '[OK]' : '[--]') . " {$name}\n";
}

if (!$live) {
    echo "\nMode structure uniquement. Ajoutez --live pour sonder la BDD.\n";
    exit(0);
}

try {
    $router = new \KDocs\Services\Ingest\IngestEngineRouter();
    $status = $router->getStatus();
    echo "\nMoteur ingest : " . ($status['engine'] ?? 'unknown') . "\n";

    $db = \KDocs\Core\Database::getInstance();
    $pending = (int) $db->query("SELECT COUNT(*) FROM documents WHERE status = 'pending'")->fetchColumn();
    echo "Documents pending : {$pending}\n";

    $search = new \KDocs\Services\SearchService();
    $result = $search->search('', 1);
    echo "SearchService OK — total=" . ($result->total ?? 0) . "\n";
} catch (\Throwable $e) {
    echo "Live bench error : " . $e->getMessage() . "\n";
    exit(1);
}

echo "Bench terminé.\n";

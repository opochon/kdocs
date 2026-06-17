<?php
/**
 * Synchronisation des embeddings pour tous les documents
 *
 * Usage: php scripts/sync_embeddings.php [--limit=N]
 */

define('BASE_PATH', dirname(__DIR__));

// Fix UTF-8 Windows
if (PHP_OS_FAMILY === 'Windows') {
    @exec('chcp 65001 > nul 2>&1');
    if (function_exists('sapi_windows_cp_set')) {
        sapi_windows_cp_set(65001);
    }
}

// Charger autoloader
$autoloadPath = BASE_PATH . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
} else {
    require_once BASE_PATH . '/app/autoload.php';
}
require_once BASE_PATH . '/app/helpers.php';

use KDocs\Core\Config;
use KDocs\Core\Database;
use KDocs\Services\EmbeddingService;

// Parse arguments
$limit = 100;
foreach ($argv as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int)substr($arg, 8);
    }
}

echo "\n";
echo "===========================================\n";
echo " SYNCHRONISATION EMBEDDINGS K-DOCS\n";
echo "===========================================\n\n";

// Vérifier configuration
$enabled = Config::get('embeddings.enabled', false);
if (!$enabled) {
    echo "ERREUR: Embeddings désactivés dans la configuration\n";
    echo "Activez: config.php -> embeddings.enabled = true\n";
    exit(1);
}

// Vérifier service
$embeddingService = new EmbeddingService();
if (!$embeddingService->isAvailable()) {
    echo "ERREUR: Service embedding non disponible\n";
    echo "Vérifiez que Ollama est en cours d'exécution\n";
    exit(1);
}

echo "Provider: " . Config::get('embeddings.provider', 'ollama') . "\n";
echo "Modèle: " . Config::get('embeddings.ollama_model', 'nomic-embed-text') . "\n";
echo "Dimensions: " . Config::get('embeddings.dimensions', 768) . "\n";
echo "Limite: $limit documents\n\n";

// Récupérer documents sans embedding
$db = Database::getInstance();

$pending = $db->query("
    SELECT id, title, original_filename,
           LENGTH(content) as content_length
    FROM documents
    WHERE deleted_at IS NULL
    AND content IS NOT NULL AND content != ''
    AND embedding IS NULL
    ORDER BY id
    LIMIT $limit
")->fetchAll(\PDO::FETCH_ASSOC);

$total = count($pending);
echo "Documents à traiter: $total\n";
echo "-------------------------------------------\n\n";

if ($total === 0) {
    echo "Aucun document en attente d'embedding.\n\n";
    exit(0);
}

$synced = 0;
$failed = 0;
$startTime = microtime(true);

foreach ($pending as $i => $doc) {
    $progress = $i + 1;
    $percent = round(($progress / $total) * 100);

    echo "[$progress/$total] {$doc['original_filename']}... ";

    try {
        $embedding = $embeddingService->embedDocument($doc['id']);

        if ($embedding) {
            $synced++;
            echo "\033[32mOK\033[0m (" . count($embedding) . " dims)\n";
        } else {
            $failed++;
            echo "\033[31mÉCHEC\033[0m\n";
        }
    } catch (\Exception $e) {
        $failed++;
        echo "\033[31mERREUR: " . $e->getMessage() . "\033[0m\n";
    }

    // Petite pause pour ne pas surcharger Ollama
    usleep(100000); // 100ms
}

$duration = round(microtime(true) - $startTime, 1);

echo "\n-------------------------------------------\n";
echo "RÉSUMÉ:\n";
echo "  - Traités: $total\n";
echo "  - Réussis: \033[32m$synced\033[0m\n";
echo "  - Échoués: " . ($failed > 0 ? "\033[31m$failed\033[0m" : "0") . "\n";
echo "  - Durée: {$duration}s\n";
echo "  - Moyenne: " . ($synced > 0 ? round($duration / $synced, 2) : 0) . "s/doc\n";
echo "-------------------------------------------\n\n";

// Afficher état final
$stats = $embeddingService->getStatistics();
echo "État final:\n";
echo "  - Total documents: " . ($stats['total_documents'] ?? 0) . "\n";
echo "  - Avec embedding: " . ($stats['completed'] ?? 0) . "\n";
echo "  - En attente: " . ($stats['pending'] ?? 0) . "\n";
echo "  - Échecs: " . ($stats['failed'] ?? 0) . "\n";
echo "\n";

exit($failed > 0 ? 1 : 0);

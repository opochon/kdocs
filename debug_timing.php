<?php
/**
 * Diagnostic de performance PRÉCIS - mesure chaque étape du rendu
 */
$GLOBALS['_timing'] = [];
$GLOBALS['_timing']['start'] = microtime(true);

function timing($label) {
    $GLOBALS['_timing'][$label] = round((microtime(true) - $GLOBALS['_timing']['start']) * 1000, 2);
}

error_reporting(E_ALL);
ini_set('display_errors', 0);

require __DIR__ . '/vendor/autoload.php';
timing('autoload');

$config = include __DIR__ . '/config/config.php';
timing('config');

// Connexion DB
$dsn = "mysql:host={$config['database']['host']};port={$config['database']['port']};dbname={$config['database']['name']};charset={$config['database']['charset']}";
$pdo = new PDO($dsn, $config['database']['user'], $config['database']['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
timing('db_connect');

// Simuler les requêtes du DocumentsController

// 1. Compter tous les documents (pour sidebar)
$stmt = $pdo->query("SELECT COUNT(*) FROM documents WHERE deleted_at IS NULL");
$totalDocs = $stmt->fetchColumn();
timing('count_all_docs');

// 2. Types de documents (pour sidebar) - SIMPLIFIÉ
$stmt = $pdo->query("SELECT id, label FROM document_types ORDER BY label");
$types = $stmt->fetchAll(PDO::FETCH_ASSOC);
timing('query_types');

// 3. Dossiers logiques
$stmt = $pdo->query("SELECT id, name FROM logical_folders ORDER BY name");
$logicalFolders = $stmt->fetchAll(PDO::FETCH_ASSOC);
timing('query_logical_folders');

// 4. Correspondants
$stmt = $pdo->query("SELECT id, name FROM correspondents ORDER BY name");
$correspondents = $stmt->fetchAll(PDO::FETCH_ASSOC);
timing('query_correspondents');

// 5. Requête documents (la plus lourde potentiellement)
$stmt = $pdo->query("
    SELECT d.id, d.original_filename, d.created_at, d.status
    FROM documents d
    WHERE d.deleted_at IS NULL
    ORDER BY d.created_at DESC
    LIMIT 20
");
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
timing('query_documents');

// 6. FolderTreeHelper - pré-chargement DB
$stmt = $pdo->query("
    SELECT relative_path
    FROM documents 
    WHERE deleted_at IS NULL 
    AND relative_path IS NOT NULL
    AND relative_path != ''
");
$paths = $stmt->fetchAll(PDO::FETCH_ASSOC);
timing('query_relative_paths');

// 7. FolderTreeHelper - scan filesystem récursif
$basePath = __DIR__ . '/storage/documents';
$folderCount = 0;
$fileCount = 0;
$allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'tiff', 'tif', 'doc', 'docx'];

function scanTree($path, $depth, &$folders, &$files, $exts) {
    if ($depth > 10) return;
    $items = @scandir($path);
    if (!$items) return;
    
    foreach ($items as $item) {
        if ($item[0] === '.') continue;
        $full = $path . DIRECTORY_SEPARATOR . $item;
        
        if (is_dir($full)) {
            $folders++;
            scanTree($full, $depth + 1, $folders, $files, $exts);
        } elseif (is_file($full)) {
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (in_array($ext, $exts)) $files++;
        }
    }
}

if (is_dir($basePath)) {
    scanTree($basePath, 0, $folderCount, $fileCount, $allowedExt);
}
timing('scan_filesystem');

// 8. Compter les queues
$queueDir = __DIR__ . '/storage/crawl_queue';
$queueCount = is_dir($queueDir) ? count(glob($queueDir . '/crawl_*.json')) : 0;
timing('count_queues');

timing('total');

// Trouver le bottleneck
$timings = $GLOBALS['_timing'];
$bottleneck = '';
$maxTime = 0;
$prev = 0;
foreach ($timings as $label => $time) {
    if ($label === 'start' || $label === 'total') continue;
    $delta = $time - $prev;
    if ($delta > $maxTime) {
        $maxTime = $delta;
        $bottleneck = $label . ' (+' . round($delta, 2) . 'ms)';
    }
    $prev = $time;
}

// Résultat
header('Content-Type: application/json');
echo json_encode([
    'timing_ms' => $timings,
    'stats' => [
        'total_docs' => $totalDocs,
        'types_count' => count($types),
        'logical_folders' => count($logicalFolders),
        'correspondents' => count($correspondents),
        'documents_page' => count($documents),
        'paths_in_db' => count($paths),
        'folders_scanned' => $folderCount,
        'files_scanned' => $fileCount,
        'queues' => $queueCount,
    ],
    'bottleneck' => $bottleneck
], JSON_PRETTY_PRINT);

<?php
// Diagnostic de performance V2
error_reporting(E_ALL);
ini_set('display_errors', 0);

require __DIR__ . '/vendor/autoload.php';

$config = include __DIR__ . '/config/config.php';

header('Content-Type: application/json');

$timings = [];
$start = microtime(true);

// Trouver le bon chemin storage
$basePath = null;
if (isset($config['storage']['base_path'])) {
    $basePath = $config['storage']['base_path'];
} elseif (isset($config['storage']['documents'])) {
    $basePath = $config['storage']['documents'];
}

// Résoudre le chemin relatif si nécessaire
if ($basePath && !is_dir($basePath)) {
    // Essayer avec realpath
    $resolved = realpath(__DIR__ . '/storage/documents');
    if ($resolved && is_dir($resolved)) {
        $basePath = $resolved;
    }
}

$timings['base_path_config'] = $basePath;
$timings['base_path_exists'] = $basePath ? is_dir($basePath) : false;

if (!$basePath || !is_dir($basePath)) {
    // Fallback
    $basePath = __DIR__ . '/storage/documents';
    $timings['base_path_fallback'] = $basePath;
}

// 1. Connexion DB
$t1 = microtime(true);
try {
    $dsn = "mysql:host={$config['database']['host']};port={$config['database']['port']};dbname={$config['database']['name']};charset={$config['database']['charset']}";
    $pdo = new PDO($dsn, $config['database']['user'], $config['database']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $timings['db_connect'] = round((microtime(true) - $t1) * 1000, 2) . 'ms';
} catch (Exception $e) {
    echo json_encode(['error' => 'DB: ' . $e->getMessage()]);
    exit;
}

// 2. Requête comptage documents
$t2 = microtime(true);
$stmt = $pdo->query("SELECT relative_path FROM documents WHERE deleted_at IS NULL AND relative_path IS NOT NULL AND relative_path != ''");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$timings['db_query_documents'] = round((microtime(true) - $t2) * 1000, 2) . 'ms';
$timings['document_count'] = count($rows);

// 3. Scan filesystem racine
$t3 = microtime(true);
$items = @scandir($basePath);
$timings['scandir_root'] = round((microtime(true) - $t3) * 1000, 2) . 'ms';
$timings['root_items'] = $items ? count($items) : 0;

// 4. Scan récursif complet (comme FolderTreeHelper)
$t4 = microtime(true);
$totalFolders = 0;
$totalFiles = 0;
$allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'tiff', 'tif', 'doc', 'docx'];

function scanRecursive($path, $depth, $maxDepth, &$folders, &$files, $allowedExt) {
    if ($depth > $maxDepth) return;
    
    $items = @scandir($path);
    if (!$items) return;
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item[0] === '.') continue;
        
        $fullPath = $path . DIRECTORY_SEPARATOR . $item;
        
        if (is_dir($fullPath)) {
            $folders++;
            scanRecursive($fullPath, $depth + 1, $maxDepth, $folders, $files, $allowedExt);
        } elseif (is_file($fullPath)) {
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExt)) {
                $files++;
            }
        }
    }
}

if (is_dir($basePath)) {
    scanRecursive($basePath, 0, 10, $totalFolders, $totalFiles, $allowedExt);
}
$timings['scan_recursive'] = round((microtime(true) - $t4) * 1000, 2) . 'ms';
$timings['total_folders'] = $totalFolders;
$timings['total_files'] = $totalFiles;

// 5. Vérifier si .indexing existe quelque part
$t5 = microtime(true);
$indexingFiles = [];
function findIndexing($path, $depth, &$found) {
    if ($depth > 5) return;
    $items = @scandir($path);
    if (!$items) return;
    foreach ($items as $item) {
        if ($item === '.indexing') {
            $found[] = $path;
        }
        if ($item !== '.' && $item !== '..' && is_dir($path . DIRECTORY_SEPARATOR . $item)) {
            findIndexing($path . DIRECTORY_SEPARATOR . $item, $depth + 1, $found);
        }
    }
}
if (is_dir($basePath)) {
    findIndexing($basePath, 0, $indexingFiles);
}
$timings['find_indexing'] = round((microtime(true) - $t5) * 1000, 2) . 'ms';
$timings['indexing_files'] = $indexingFiles;

// 6. Vérifier la queue d'indexation
$queueDir = __DIR__ . '/storage/crawl_queue';
$queues = [];
if (is_dir($queueDir)) {
    $queueFiles = glob($queueDir . '/crawl_*.json');
    foreach ($queueFiles as $qf) {
        $queues[] = [
            'file' => basename($qf),
            'content' => json_decode(file_get_contents($qf), true)
        ];
    }
}
$timings['queue_count'] = count($queues);
$timings['queues'] = $queues;

// 7. Liste des dossiers de premier niveau
$t7 = microtime(true);
$rootFolders = [];
if (is_dir($basePath)) {
    $items = scandir($basePath);
    foreach ($items as $item) {
        if ($item !== '.' && $item !== '..' && $item[0] !== '.' && is_dir($basePath . DIRECTORY_SEPARATOR . $item)) {
            $rootFolders[] = $item;
        }
    }
}
$timings['list_root_folders'] = round((microtime(true) - $t7) * 1000, 2) . 'ms';
$timings['root_folders'] = $rootFolders;

$timings['total_time'] = round((microtime(true) - $start) * 1000, 2) . 'ms';
$timings['base_path_final'] = $basePath;

echo json_encode($timings, JSON_PRETTY_PRINT);

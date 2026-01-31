<?php
/**
 * Worker d'indexation simplifié - Exécution directe
 * Traite un lot limité de fichiers pour éviter les timeouts HTTP
 */

// Éviter le timeout
set_time_limit(120); // 2 minutes max
ini_set('memory_limit', '256M');

header('Content-Type: application/json');

// Charger l'application
require_once __DIR__ . '/app/autoload.php';

use KDocs\Core\Database;
use KDocs\Core\Config;
use KDocs\Services\ConsumeFolderService;
use KDocs\Services\DocumentProcessor;
use KDocs\Services\ClassificationService;

$config = Config::load();
$db = Database::getInstance();

$results = [
    'success' => true,
    'consume' => ['scanned' => 0, 'imported' => 0, 'skipped' => 0, 'errors' => []],
    'folders' => [],
    'duration' => 0
];

$startTime = time();

try {
    // 1. Scanner le dossier consume
    $consumeService = new ConsumeFolderService();
    $consumeResult = $consumeService->scan();
    $results['consume'] = $consumeResult;
    
    // 2. Scanner les dossiers documents pour créer les fichiers .index
    $storagePath = $config['storage']['documents'] ?? dirname(__FILE__) . '/storage/documents';
    $folders = scanFoldersRecursive($storagePath);
    
    foreach ($folders as $folder) {
        $relativePath = str_replace($storagePath . DIRECTORY_SEPARATOR, '', $folder);
        if ($relativePath === $storagePath) $relativePath = '';
        
        $indexResult = indexFolder($folder, $db);
        $results['folders'][] = [
            'path' => $relativePath ?: '/',
            'files' => $indexResult['total'],
            'new' => $indexResult['new'],
            'skipped' => $indexResult['skipped']
        ];
    }
    
} catch (Exception $e) {
    $results['success'] = false;
    $results['error'] = $e->getMessage();
}

$results['duration'] = time() - $startTime;

echo json_encode($results, JSON_PRETTY_PRINT);

/**
 * Scanner récursivement les dossiers
 */
function scanFoldersRecursive(string $path): array {
    $folders = [];
    if (!is_dir($path)) return $folders;
    
    $hasFiles = false;
    $items = @scandir($path);
    if (!$items) return $folders;
    
    foreach ($items as $item) {
        if ($item[0] === '.') continue;
        $itemPath = $path . DIRECTORY_SEPARATOR . $item;
        
        if (is_dir($itemPath)) {
            $subFolders = scanFoldersRecursive($itemPath);
            $folders = array_merge($folders, $subFolders);
        } else {
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'tiff', 'tif'])) {
                $hasFiles = true;
            }
        }
    }
    
    if ($hasFiles) {
        $folders[] = $path;
    }
    
    return $folders;
}

/**
 * Indexer un dossier
 */
function indexFolder(string $fullPath, $db): array {
    $stats = ['total' => 0, 'new' => 0, 'skipped' => 0, 'errors' => 0];
    $allowed = ['pdf', 'png', 'jpg', 'jpeg', 'tiff', 'tif', 'gif', 'webp'];
    
    // Créer le fichier .indexing
    $indexingFile = $fullPath . DIRECTORY_SEPARATOR . '.indexing';
    file_put_contents($indexingFile, json_encode([
        'started_at' => time(),
        'status' => 'running'
    ]));
    
    try {
        $files = @scandir($fullPath);
        if (!$files) return $stats;
        
        foreach ($files as $file) {
            if ($file[0] === '.') continue;
            $filePath = $fullPath . DIRECTORY_SEPARATOR . $file;
            if (!is_file($filePath)) continue;
            
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) continue;
            
            $stats['total']++;
            
            // Vérifier si déjà en DB
            $checksum = @md5_file($filePath);
            if (!$checksum) {
                $stats['errors']++;
                continue;
            }
            
            $stmt = $db->prepare("SELECT id FROM documents WHERE checksum = ?");
            $stmt->execute([$checksum]);
            if ($stmt->fetch()) {
                $stats['skipped']++;
                continue;
            }
            
            // Créer l'entrée en DB
            try {
                $unique = date('Ymd_His') . '_' . uniqid() . '.' . $ext;
                
                $stmt = $db->prepare("
                    INSERT INTO documents (title, filename, original_filename, file_path, file_size, mime_type, checksum, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
                ");
                $stmt->execute([
                    pathinfo($file, PATHINFO_FILENAME),
                    $unique,
                    $file,
                    $filePath,
                    filesize($filePath),
                    mime_content_type($filePath) ?: 'application/octet-stream',
                    $checksum
                ]);
                
                $docId = $db->lastInsertId();
                
                // OCR et classification
                $processor = new DocumentProcessor();
                $processor->process($docId);
                
                $classifier = new ClassificationService();
                $classification = $classifier->classify($docId);
                
                $db->prepare("UPDATE documents SET classification_suggestions = ? WHERE id = ?")
                    ->execute([json_encode($classification), $docId]);
                
                $stats['new']++;
                
            } catch (Exception $e) {
                $stats['errors']++;
                error_log("Erreur indexation $file: " . $e->getMessage());
            }
        }
        
        // Créer/mettre à jour le fichier .index
        $indexFile = $fullPath . DIRECTORY_SEPARATOR . '.index';
        file_put_contents($indexFile, json_encode([
            'version' => 2,
            'last_scan' => time(),
            'file_count' => $stats['total'],
            'stats' => $stats
        ]));
        
    } finally {
        // Supprimer .indexing
        if (file_exists($indexingFile)) {
            @unlink($indexingFile);
        }
    }
    
    return $stats;
}

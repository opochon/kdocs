<?php
/**
 * 01_detect_changes.php
 * 
 * OBJECTIF: Détecter les fichiers nouveaux, modifiés, supprimés
 * 
 * ORIGINE: Logique inspirée de FilesystemIndexer.php
 * CIBLE: Services/IndexingService.php (après validation POC)
 * 
 * ENTRÉE: 
 *   - Chemin dossier à scanner
 *   - État précédent (.index JSON)
 * 
 * SORTIE:
 *   - Liste {action: new|modified|deleted, file, hash, mtime}
 * 
 * DÉPENDANCES GED:
 *   - documents.content_hash (MD5 contenu)
 *   - documents.file_path
 *   - documents.relative_path
 *   - documents.updated_at
 * 
 * SIDE EFFECTS POTENTIELS:
 *   - Aucun (lecture seule)
 */

require_once __DIR__ . '/helpers.php';

// ============================================
// CONFIGURATION
// ============================================

$SCAN_PATH = poc_config()['paths']['documents'];
$INDEX_FILE = poc_config()['poc']['output_dir'] . '/state.index.json';
$EXTENSIONS = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'tiff', 'tif', 'txt', 'odt', 'xls', 'xlsx'];

// ============================================
// FONCTIONS
// ============================================

/**
 * Charge l'état précédent depuis .index
 */
function load_previous_state(string $indexFile): array {
    if (!file_exists($indexFile)) {
        return [];
    }
    $content = file_get_contents($indexFile);
    return json_decode($content, true) ?: [];
}

/**
 * Sauvegarde l'état actuel dans .index
 */
function save_current_state(string $indexFile, array $state): void {
    $dir = dirname($indexFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($indexFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * Scanne un dossier récursivement
 */
function scan_directory(string $path, array $extensions): array {
    $files = [];
    
    if (!is_dir($path)) {
        poc_log("Dossier introuvable: $path", 'ERROR');
        return $files;
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $ext = strtolower($file->getExtension());
            if (in_array($ext, $extensions)) {
                $fullPath = $file->getPathname();
                $relativePath = str_replace($path . DIRECTORY_SEPARATOR, '', $fullPath);
                $relativePath = str_replace('\\', '/', $relativePath);
                
                $files[$relativePath] = [
                    'path' => $fullPath,
                    'relative' => $relativePath,
                    'filename' => $file->getFilename(),
                    'extension' => $ext,
                    'size' => $file->getSize(),
                    'mtime' => $file->getMTime(),
                    'hash' => md5_file($fullPath),
                ];
            }
        }
    }
    
    return $files;
}

/**
 * Compare état actuel vs précédent
 */
function detect_changes(array $current, array $previous): array {
    $changes = [
        'new' => [],
        'modified' => [],
        'deleted' => [],
        'unchanged' => [],
    ];
    
    // Fichiers nouveaux ou modifiés
    foreach ($current as $relativePath => $fileInfo) {
        if (!isset($previous[$relativePath])) {
            // Nouveau fichier
            $changes['new'][] = array_merge($fileInfo, ['action' => 'new']);
        } elseif ($previous[$relativePath]['hash'] !== $fileInfo['hash']) {
            // Fichier modifié (hash différent)
            $changes['modified'][] = array_merge($fileInfo, [
                'action' => 'modified',
                'previous_hash' => $previous[$relativePath]['hash'],
            ]);
        } else {
            // Inchangé
            $changes['unchanged'][] = $relativePath;
        }
    }
    
    // Fichiers supprimés
    foreach ($previous as $relativePath => $fileInfo) {
        if (!isset($current[$relativePath])) {
            $changes['deleted'][] = array_merge($fileInfo, ['action' => 'deleted']);
        }
    }
    
    return $changes;
}

/**
 * Compare avec la base de données GED
 */
function compare_with_db(array $currentFiles): array {
    $db = poc_db();
    
    // Récupérer tous les documents indexés
    $stmt = $db->query("
        SELECT id, file_path, relative_path, content_hash, updated_at 
        FROM documents 
        WHERE deleted_at IS NULL
    ");
    $dbDocs = [];
    while ($row = $stmt->fetch()) {
        $key = $row['relative_path'] ?: basename($row['file_path']);
        $dbDocs[$key] = $row;
    }
    
    $comparison = [
        'in_fs_not_db' => [],      // Dans filesystem mais pas en DB
        'in_db_not_fs' => [],      // En DB mais plus dans filesystem
        'hash_mismatch' => [],     // Hash différent entre FS et DB
        'synced' => [],            // Synchronisés
    ];
    
    foreach ($currentFiles as $relativePath => $fileInfo) {
        if (!isset($dbDocs[$relativePath])) {
            $comparison['in_fs_not_db'][] = $fileInfo;
        } elseif ($dbDocs[$relativePath]['content_hash'] !== $fileInfo['hash']) {
            $comparison['hash_mismatch'][] = [
                'file' => $fileInfo,
                'db' => $dbDocs[$relativePath],
            ];
        } else {
            $comparison['synced'][] = $relativePath;
        }
    }
    
    foreach ($dbDocs as $relativePath => $dbDoc) {
        if (!isset($currentFiles[$relativePath])) {
            $comparison['in_db_not_fs'][] = $dbDoc;
        }
    }
    
    return $comparison;
}

// ============================================
// EXÉCUTION
// ============================================

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  POC 01 - DÉTECTION CHANGEMENTS                              ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

poc_log("Scan: $SCAN_PATH");

// 1. Charger état précédent
$previousState = load_previous_state($INDEX_FILE);
poc_log("État précédent: " . count($previousState) . " fichiers");

// 2. Scanner le dossier
poc_log("Scan en cours...");
$currentFiles = scan_directory($SCAN_PATH, $EXTENSIONS);
poc_log("Fichiers trouvés: " . count($currentFiles));

// 3. Détecter les changements (vs état précédent)
$changes = detect_changes($currentFiles, $previousState);

echo "\n--- CHANGEMENTS (vs dernier scan) ---\n";
poc_result("Nouveaux", true, count($changes['new']) . " fichiers");
poc_result("Modifiés", true, count($changes['modified']) . " fichiers");
poc_result("Supprimés", true, count($changes['deleted']) . " fichiers");
poc_result("Inchangés", true, count($changes['unchanged']) . " fichiers");

// 4. Comparer avec la DB
$dbComparison = compare_with_db($currentFiles);

echo "\n--- COMPARAISON DB ---\n";
poc_result("Dans FS, pas en DB", count($dbComparison['in_fs_not_db']) > 0, count($dbComparison['in_fs_not_db']) . " fichiers à indexer");
poc_result("En DB, pas dans FS", count($dbComparison['in_db_not_fs']) > 0, count($dbComparison['in_db_not_fs']) . " fichiers orphelins");
poc_result("Hash différent", count($dbComparison['hash_mismatch']) > 0, count($dbComparison['hash_mismatch']) . " fichiers à re-indexer");
poc_result("Synchronisés", true, count($dbComparison['synced']) . " fichiers OK");

// 5. Sauvegarder nouvel état
save_current_state($INDEX_FILE, $currentFiles);
poc_log("État sauvegardé: $INDEX_FILE");

// 6. Écrire rapport détaillé
$report = [
    'timestamp' => date('Y-m-d H:i:s'),
    'scan_path' => $SCAN_PATH,
    'total_files' => count($currentFiles),
    'changes' => [
        'new' => count($changes['new']),
        'modified' => count($changes['modified']),
        'deleted' => count($changes['deleted']),
    ],
    'db_comparison' => [
        'to_index' => count($dbComparison['in_fs_not_db']),
        'orphans' => count($dbComparison['in_db_not_fs']),
        'to_reindex' => count($dbComparison['hash_mismatch']),
        'synced' => count($dbComparison['synced']),
    ],
    'files_to_process' => array_merge(
        $dbComparison['in_fs_not_db'],
        array_column($dbComparison['hash_mismatch'], 'file')
    ),
];

$reportFile = poc_config()['poc']['output_dir'] . '/01_changes_report.json';
file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n--- RAPPORT ---\n";
echo "Fichier: $reportFile\n";

echo "\n✓ POC 01 terminé\n\n";

// Retourner pour chaînage
return $report;

#!/usr/bin/env php
<?php
/**
 * K-Docs - Import Batch CLI
 * Importe des fichiers (PDF, MSG, images) en masse dans K-Docs
 * 
 * Usage:
 *   php import_batch.php /chemin/vers/dossier
 *   php import_batch.php /chemin/vers/dossier --type=msg --limit=100
 *   php import_batch.php /chemin/vers/dossier --recursive --process
 */

// Charger l'autoloader
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../app/autoload.php',
];

$loaded = false;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    die("Erreur: autoload.php introuvable\n");
}

use KDocs\Core\Database;
use KDocs\Core\Config;
use KDocs\Services\DocumentProcessor;
use KDocs\Services\MSGImportService;
use KDocs\Models\Document;

// Couleurs console (Windows compatible)
function supportsColor(): bool {
    if (DIRECTORY_SEPARATOR === '\\') {
        return getenv('ANSICON') !== false || getenv('ConEmuANSI') === 'ON';
    }
    return true;
}

$useColor = supportsColor();
define('GREEN', $useColor ? "\033[32m" : '');
define('RED', $useColor ? "\033[31m" : '');
define('YELLOW', $useColor ? "\033[33m" : '');
define('CYAN', $useColor ? "\033[36m" : '');
define('RESET', $useColor ? "\033[0m" : '');

function println(string $msg, string $color = ''): void {
    echo $color . $msg . ($color ? RESET : '') . PHP_EOL;
}

function parseArgs(array $argv): array {
    $args = [
        'source' => null,
        'type' => 'all',  // pdf, msg, image, all
        'limit' => null,
        'recursive' => false,
        'process' => false,
        'user_id' => 1,
        'dry_run' => false,
        'help' => false,
    ];
    
    foreach ($argv as $i => $arg) {
        if ($i === 0) continue;
        
        if ($arg === '-h' || $arg === '--help') {
            $args['help'] = true;
        } elseif (strpos($arg, '--') === 0) {
            $parts = explode('=', substr($arg, 2), 2);
            $key = $parts[0];
            $value = $parts[1] ?? true;
            
            switch ($key) {
                case 'type': $args['type'] = $value; break;
                case 'limit': $args['limit'] = (int)$value; break;
                case 'user': 
                case 'user-id': $args['user_id'] = (int)$value; break;
                case 'recursive': $args['recursive'] = true; break;
                case 'process': $args['process'] = true; break;
                case 'dry-run': $args['dry_run'] = true; break;
            }
        } elseif ($arg === '-r') {
            $args['recursive'] = true;
        } elseif ($arg === '-p') {
            $args['process'] = true;
        } elseif ($arg === '-n') {
            $args['dry_run'] = true;
        } elseif (!$args['source'] && !str_starts_with($arg, '-')) {
            $args['source'] = $arg;
        }
    }
    
    return $args;
}

function showHelp(): void {
    println("");
    println("╔════════════════════════════════════════╗", CYAN);
    println("║     K-DOCS BATCH IMPORT                ║", CYAN);
    println("╚════════════════════════════════════════╝", CYAN);
    println("");
    println("Usage: php import_batch.php <dossier> [options]");
    println("");
    println("Options:");
    println("  --type=<type>      Type de fichiers à importer");
    println("                     pdf   : Fichiers PDF uniquement");
    println("                     msg   : Fichiers Outlook MSG (avec PJ)");
    println("                     image : Images (jpg, png, tiff...)");
    println("                     all   : Tous les types (défaut)");
    println("");
    println("  --limit=N          Limiter à N fichiers");
    println("  --recursive, -r    Parcourir les sous-dossiers");
    println("  --process, -p      Traiter (OCR/IA) après import");
    println("  --user-id=N        ID utilisateur créateur (défaut: 1)");
    println("  --dry-run, -n      Simuler sans importer");
    println("  -h, --help         Afficher cette aide");
    println("");
    println("Exemples:", YELLOW);
    println("  php import_batch.php C:\\Documents\\Factures");
    println("  php import_batch.php C:\\Mails --type=msg");
    println("  php import_batch.php C:\\Archive -r --limit=100 -p");
    println("");
    println("Import MSG:", CYAN);
    println("  Les fichiers .msg sont importés avec leurs pièces jointes.");
    println("  Chaque PJ devient un document lié au mail parent.");
    println("  Les mails sont groupés par thread (conversation).");
    println("");
}

function getFiles(string $dir, string $type, bool $recursive): array {
    $extensionMap = [
        'pdf' => ['pdf'],
        'msg' => ['msg'],
        'image' => ['jpg', 'jpeg', 'png', 'tiff', 'tif', 'gif', 'webp', 'bmp'],
        'office' => ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods'],
        'all' => ['pdf', 'msg', 'jpg', 'jpeg', 'png', 'tiff', 'tif', 'gif', 'webp', 
                  'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods'],
    ];
    
    $extensions = $extensionMap[$type] ?? $extensionMap['all'];
    $files = [];
    
    if ($recursive) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $ext = strtolower($file->getExtension());
                if (in_array($ext, $extensions)) {
                    $files[] = $file->getPathname();
                }
            }
        }
    } else {
        foreach ($extensions as $ext) {
            $files = array_merge($files, glob($dir . DIRECTORY_SEPARATOR . '*.' . $ext, GLOB_NOSORT));
            $files = array_merge($files, glob($dir . DIRECTORY_SEPARATOR . '*.' . strtoupper($ext), GLOB_NOSORT));
        }
    }
    
    return array_unique($files);
}

function importFile(string $filepath, int $userId, bool $process): array {
    $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
    $result = [
        'success' => false,
        'document_ids' => [],
        'type' => $ext,
        'error' => null,
    ];
    
    try {
        if ($ext === 'msg') {
            // Import MSG avec pièces jointes
            $msgService = new MSGImportService();
            $importResult = $msgService->importWithAttachments($filepath, $userId);
            
            if ($importResult['success']) {
                $result['success'] = true;
                $result['document_ids'][] = $importResult['mail_id'];
                $result['document_ids'] = array_merge($result['document_ids'], $importResult['attachment_ids']);
                $result['attachments'] = count($importResult['attachment_ids']);
                $result['thread_id'] = $importResult['thread_id'];
            } else {
                $result['error'] = $importResult['error'];
            }
        } else {
            // Import standard
            $docId = Document::createFromFile($filepath, [
                'created_by' => $userId,
                'original_filename' => basename($filepath),
            ]);
            
            if ($docId) {
                $result['success'] = true;
                $result['document_ids'][] = $docId;
            }
        }
        
        // Traitement OCR/IA si demandé
        if ($result['success'] && $process && !empty($result['document_ids'])) {
            $processor = new DocumentProcessor();
            foreach ($result['document_ids'] as $docId) {
                try {
                    $processor->process($docId);
                } catch (\Exception $e) {
                    // Log mais ne pas échouer l'import
                    error_log("Process failed for doc $docId: " . $e->getMessage());
                }
            }
        }
        
    } catch (\Exception $e) {
        $result['error'] = $e->getMessage();
    }
    
    return $result;
}

function isAlreadyImported(string $filepath): bool {
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT id FROM documents WHERE original_filename = ? LIMIT 1");
    $stmt->execute([basename($filepath)]);
    return $stmt->fetch() !== false;
}

// =====================================
// MAIN
// =====================================

$args = parseArgs($argv);

if ($args['help']) {
    showHelp();
    exit(0);
}

println("");
println("╔════════════════════════════════════════╗", CYAN);
println("║     K-DOCS BATCH IMPORT                ║", CYAN);
println("╚════════════════════════════════════════╝", CYAN);
println("");

if (!$args['source'] || !is_dir($args['source'])) {
    println("Erreur: Dossier source requis ou introuvable.", RED);
    println("Utilisez --help pour voir l'aide.", YELLOW);
    exit(1);
}

$sourceDir = realpath($args['source']);

println("Source:      " . $sourceDir);
println("Type:        " . $args['type']);
println("Récursif:    " . ($args['recursive'] ? 'oui' : 'non'));
println("Traitement:  " . ($args['process'] ? 'oui (OCR/IA)' : 'non'));
println("User ID:     " . $args['user_id']);

if ($args['dry_run']) {
    println("Mode:        DRY RUN (simulation)", YELLOW);
}
println("");

// Vérifier dépendances pour MSG
if ($args['type'] === 'msg' || $args['type'] === 'all') {
    $msgService = new MSGImportService();
    if (!$msgService->isAvailable()) {
        println("⚠ Import MSG non disponible (Python extract-msg requis)", YELLOW);
        println("  Installez avec: pip install extract-msg", YELLOW);
        if ($args['type'] === 'msg') {
            exit(1);
        }
    }
}

// Récupérer les fichiers
println("Recherche des fichiers...");
$files = getFiles($sourceDir, $args['type'], $args['recursive']);
$total = count($files);

if ($args['limit'] && $args['limit'] < $total) {
    $files = array_slice($files, 0, $args['limit']);
}

println("Fichiers trouvés: " . $total);
println("À importer:       " . count($files));
println("");

if (count($files) === 0) {
    println("Aucun fichier à importer.", YELLOW);
    exit(0);
}

// Stats
$stats = [
    'imported' => 0,
    'skipped' => 0,
    'errors' => 0,
    'documents' => 0,
    'attachments' => 0,
    'by_type' => [],
];

// Import
foreach ($files as $i => $filepath) {
    $num = $i + 1;
    $filename = basename($filepath);
    $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
    $shortName = mb_strlen($filename) > 50 ? mb_substr($filename, 0, 47) . '...' : $filename;
    
    printf("[%d/%d] %-55s ", $num, count($files), $shortName);
    
    if ($args['dry_run']) {
        println("(dry run)", YELLOW);
        $stats['imported']++;
        $stats['by_type'][$ext] = ($stats['by_type'][$ext] ?? 0) + 1;
        continue;
    }
    
    // Vérifier doublon
    if (isAlreadyImported($filepath)) {
        println("SKIP (existe)", YELLOW);
        $stats['skipped']++;
        continue;
    }
    
    // Importer
    $result = importFile($filepath, $args['user_id'], $args['process']);
    
    if ($result['success']) {
        $stats['imported']++;
        $stats['documents'] += count($result['document_ids']);
        $stats['by_type'][$ext] = ($stats['by_type'][$ext] ?? 0) + 1;
        
        if ($ext === 'msg' && isset($result['attachments']) && $result['attachments'] > 0) {
            $stats['attachments'] += $result['attachments'];
            println("OK +" . $result['attachments'] . " PJ", GREEN);
        } else {
            println("OK", GREEN);
        }
    } else {
        $stats['errors']++;
        println("ERREUR", RED);
        if ($result['error']) {
            println("         " . $result['error'], RED);
        }
    }
}

// Résumé
println("");
println("════════════════════════════════════════", CYAN);
println("RÉSUMÉ", CYAN);
println("════════════════════════════════════════", CYAN);
println("");
println("Fichiers importés:  " . $stats['imported'], $stats['imported'] > 0 ? GREEN : RESET);
println("Fichiers ignorés:   " . $stats['skipped'], $stats['skipped'] > 0 ? YELLOW : RESET);
println("Erreurs:            " . $stats['errors'], $stats['errors'] > 0 ? RED : RESET);
println("");
println("Documents créés:    " . $stats['documents']);
if ($stats['attachments'] > 0) {
    println("Pièces jointes:     " . $stats['attachments']);
}
println("");

if (!empty($stats['by_type'])) {
    println("Par type:");
    foreach ($stats['by_type'] as $type => $count) {
        println("  - " . strtoupper($type) . ": " . $count);
    }
    println("");
}

if (!$args['process'] && $stats['imported'] > 0 && !$args['dry_run']) {
    println("Conseil: Lancez l'indexation avec -p ou depuis K-Docs.", YELLOW);
}

exit($stats['errors'] > 0 ? 1 : 0);

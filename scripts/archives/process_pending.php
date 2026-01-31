<?php
/**
 * K-Docs - Script de traitement des documents en attente
 *
 * Usage: php scripts/process_pending.php [--limit=50] [--thumbnails-only] [--ocr-only] [--diagnose]
 *
 * Options:
 *   --limit=N         Nombre max de documents à traiter (défaut: 50)
 *   --thumbnails-only Ne générer que les miniatures
 *   --ocr-only        Ne faire que l'extraction OCR
 *   --diagnose        Afficher l'état sans traiter
 *   --force           Régénérer même si déjà existant
 */

// Initialisation
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(0);
ini_set('memory_limit', '512M');

// Chargement de l'autoloader
require_once __DIR__ . '/../vendor/autoload.php';

use KDocs\Core\Database;
use KDocs\Core\Config;
use KDocs\Services\ThumbnailGenerator;
use KDocs\Services\DocumentProcessor;
use KDocs\Services\OCRService;
use KDocs\Helpers\SystemHelper;

// Couleurs pour la console
function colorize($text, $color) {
    if (PHP_OS_FAMILY === 'Windows') {
        return $text; // Windows cmd ne supporte pas les couleurs ANSI par défaut
    }
    $colors = [
        'green' => "\033[32m",
        'red' => "\033[31m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'cyan' => "\033[36m",
        'reset' => "\033[0m",
        'bold' => "\033[1m",
    ];
    return ($colors[$color] ?? '') . $text . $colors['reset'];
}

function printSuccess($text) { echo "[OK] " . $text . "\n"; }
function printError($text) { echo "[X] " . $text . "\n"; }
function printWarning($text) { echo "[!] " . $text . "\n"; }
function printInfo($text) { echo "    " . $text . "\n"; }
function printHeader($text) { echo "\n=== " . $text . " ===\n"; }

// Parse arguments
$options = [
    'limit' => 50,
    'thumbnails-only' => false,
    'ocr-only' => false,
    'diagnose' => false,
    'force' => false,
];

foreach ($argv as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $options['limit'] = (int)substr($arg, 8);
    } elseif ($arg === '--thumbnails-only') {
        $options['thumbnails-only'] = true;
    } elseif ($arg === '--ocr-only') {
        $options['ocr-only'] = true;
    } elseif ($arg === '--diagnose') {
        $options['diagnose'] = true;
    } elseif ($arg === '--force') {
        $options['force'] = true;
    }
}

echo "\n";
echo "╔══════════════════════════════════════════╗\n";
echo "║  K-Docs - Traitement des documents       ║\n";
echo "╚══════════════════════════════════════════╝\n";

// Connexion base de données
printHeader("Connexion base de données");
try {
    $db = Database::getInstance();
    printSuccess("Connecté à la base de données");
} catch (Exception $e) {
    printError("Impossible de se connecter: " . $e->getMessage());
    exit(1);
}

// Diagnostic de l'état des documents
printHeader("Diagnostic des documents");

$stats = $db->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN thumbnail_path IS NULL OR thumbnail_path = '' THEN 1 ELSE 0 END) as sans_miniature,
        SUM(CASE WHEN ocr_text IS NULL OR ocr_text = '' THEN 1 ELSE 0 END) as sans_ocr,
        SUM(CASE WHEN content IS NULL OR content = '' THEN 1 ELSE 0 END) as sans_contenu,
        SUM(CASE WHEN ocr_text LIKE '%OCR échoué%' OR ocr_text LIKE '%Erreur OCR%' THEN 1 ELSE 0 END) as ocr_erreur,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN is_indexed = 0 OR is_indexed IS NULL THEN 1 ELSE 0 END) as non_indexes
    FROM documents
    WHERE deleted_at IS NULL
")->fetch(PDO::FETCH_ASSOC);

echo "\nÉtat actuel:\n";
echo "  Total documents:     " . str_pad($stats['total'], 5) . "\n";
echo "  Sans miniature:      " . str_pad($stats['sans_miniature'], 5) . " (" . round($stats['sans_miniature'] / max(1, $stats['total']) * 100) . "%)\n";
echo "  Sans OCR:            " . str_pad($stats['sans_ocr'], 5) . " (" . round($stats['sans_ocr'] / max(1, $stats['total']) * 100) . "%)\n";
echo "  Sans contenu:        " . str_pad($stats['sans_contenu'], 5) . "\n";
echo "  Erreurs OCR:         " . str_pad($stats['ocr_erreur'], 5) . "\n";
echo "  Status pending:      " . str_pad($stats['pending'], 5) . "\n";
echo "  Non indexés:         " . str_pad($stats['non_indexes'], 5) . "\n";

// Vérifier les outils système
printHeader("Vérification des outils");

$config = Config::load();

// Ghostscript
$gsPath = SystemHelper::findGhostscript();
if ($gsPath && file_exists($gsPath)) {
    printSuccess("Ghostscript: $gsPath");
} else {
    $gsPath = $config['tools']['ghostscript'] ?? null;
    if ($gsPath && file_exists($gsPath)) {
        printSuccess("Ghostscript (config): $gsPath");
    } else {
        printWarning("Ghostscript: NON TROUVÉ");
        printInfo("Les miniatures PDF ne seront pas générées");
    }
}

// Tesseract
$tesseractPath = $config['ocr']['tesseract_path'] ?? 'tesseract';
if (file_exists($tesseractPath)) {
    printSuccess("Tesseract: $tesseractPath");
    // Vérifier les langues
    exec("\"$tesseractPath\" --list-langs 2>&1", $langs);
    $hasFra = false;
    foreach ($langs as $l) {
        if (trim($l) === 'fra') $hasFra = true;
    }
    if ($hasFra) {
        printSuccess("  Langue française disponible");
    } else {
        printWarning("  Langue française NON TROUVÉE");
    }
} else {
    printWarning("Tesseract: NON TROUVÉ");
    printInfo("L'OCR des images ne fonctionnera pas");
}

// pdftotext
$pdftotextPath = $config['tools']['pdftotext'] ?? null;
if ($pdftotextPath && file_exists($pdftotextPath)) {
    printSuccess("pdftotext: $pdftotextPath");
} else {
    // Chercher dans les emplacements standard
    $pdftotextPaths = [
        'C:\\Tools\\poppler\\Library\\bin\\pdftotext.exe',
        'C:\\Program Files\\poppler\\bin\\pdftotext.exe',
        'C:\\Program Files\\Git\\mingw64\\bin\\pdftotext.exe',
    ];
    $found = false;
    foreach ($pdftotextPaths as $path) {
        if (file_exists($path)) {
            printSuccess("pdftotext: $path (non configuré dans config.php)");
            $found = true;
            break;
        }
    }
    if (!$found) {
        printWarning("pdftotext: NON TROUVÉ");
        printInfo("L'extraction de texte PDF sera plus lente (via Tesseract)");
    }
}

// Vérifier le dossier thumbnails
$thumbPath = $config['storage']['thumbnails'] ?? __DIR__ . '/../storage/thumbnails';
if (!is_dir($thumbPath)) {
    mkdir($thumbPath, 0755, true);
    printSuccess("Dossier thumbnails créé: $thumbPath");
} else {
    printSuccess("Dossier thumbnails existe: $thumbPath");
}
if (is_writable($thumbPath)) {
    printSuccess("  Permissions d'écriture OK");
} else {
    printError("  Pas de permission d'écriture!");
}

// Si mode diagnostic seulement, s'arrêter ici
if ($options['diagnose']) {
    echo "\n[Mode diagnostic - aucun traitement effectué]\n\n";
    exit(0);
}

// Initialiser les services
$thumbnailGenerator = new ThumbnailGenerator();
$processor = new DocumentProcessor();

// Afficher les outils disponibles
$tools = $thumbnailGenerator->getAvailableTools();
printInfo("Outils miniatures: GS=" . ($tools['ghostscript'] ? 'OK' : 'NON') .
     ", IM=" . ($tools['imagemagick'] ? 'OK' : 'NON') .
     ", LO=" . ($tools['libreoffice'] ? 'OK' : 'NON') .
     ", GD=" . ($tools['gd'] ? 'OK' : 'NON'));

// Traitement des miniatures
if (!$options['ocr-only']) {
    printHeader("Génération des miniatures manquantes");

    $whereClause = "(thumbnail_path IS NULL OR thumbnail_path = '')";
    if ($options['force']) {
        $whereClause = "1=1"; // Tout régénérer
    }

    $stmt = $db->prepare("
        SELECT id, file_path, filename, original_filename, mime_type
        FROM documents
        WHERE $whereClause
          AND file_path IS NOT NULL
          AND deleted_at IS NULL
        LIMIT ?
    ");
    $stmt->execute([$options['limit']]);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $thumbStats = ['total' => count($docs), 'success' => 0, 'failed' => 0];

    foreach ($docs as $i => $doc) {
        $progress = sprintf("[%d/%d]", $i + 1, $thumbStats['total']);
        $name = $doc['original_filename'] ?? $doc['filename'] ?? "Doc #{$doc['id']}";

        if (!file_exists($doc['file_path'])) {
            printWarning("$progress $name - Fichier introuvable");
            $thumbStats['failed']++;
            continue;
        }

        $thumbFilename = $thumbnailGenerator->generate($doc['file_path'], $doc['id']);

        if ($thumbFilename) {
            $db->prepare("UPDATE documents SET thumbnail_path = ? WHERE id = ?")
               ->execute([$thumbFilename, $doc['id']]);
            printSuccess("$progress $name");
            $thumbStats['success']++;
        } else {
            printWarning("$progress $name - Échec génération");
            $thumbStats['failed']++;
        }
    }

    echo "\nRésultat miniatures: {$thumbStats['success']} générées, {$thumbStats['failed']} échecs sur {$thumbStats['total']}\n";
}

// Traitement OCR
if (!$options['thumbnails-only']) {
    printHeader("Extraction de contenu (OCR)");

    $whereClause = "(ocr_text IS NULL OR ocr_text = '' OR ocr_text LIKE '%OCR échoué%' OR ocr_text LIKE '%Erreur OCR%')";
    if ($options['force']) {
        $whereClause = "1=1"; // Tout retraiter
    }

    $stmt = $db->prepare("
        SELECT id, file_path, filename, original_filename, mime_type
        FROM documents
        WHERE $whereClause
          AND file_path IS NOT NULL
          AND deleted_at IS NULL
        LIMIT ?
    ");
    $stmt->execute([$options['limit']]);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ocrStats = ['total' => count($docs), 'success' => 0, 'failed' => 0];

    $ocrService = new OCRService();

    foreach ($docs as $i => $doc) {
        $progress = sprintf("[%d/%d]", $i + 1, $ocrStats['total']);
        $name = $doc['original_filename'] ?? $doc['filename'] ?? "Doc #{$doc['id']}";

        if (!file_exists($doc['file_path'])) {
            printWarning("$progress $name - Fichier introuvable");
            $ocrStats['failed']++;
            continue;
        }

        try {
            $content = $ocrService->extractText($doc['file_path']);

            if ($content && !empty(trim($content))) {
                // Nettoyer le texte
                $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
                $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);

                $db->prepare("UPDATE documents SET content = ?, ocr_text = ? WHERE id = ?")
                   ->execute([$content, $content, $doc['id']]);

                $chars = strlen($content);
                printSuccess("$progress $name ({$chars} caractères)");
                $ocrStats['success']++;
            } else {
                printWarning("$progress $name - Aucun texte extrait");
                $ocrStats['failed']++;
            }
        } catch (Exception $e) {
            printWarning("$progress $name - Erreur: " . $e->getMessage());
            $ocrStats['failed']++;
        }
    }

    echo "\nRésultat OCR: {$ocrStats['success']} extraits, {$ocrStats['failed']} échecs sur {$ocrStats['total']}\n";
}

// Résumé final
printHeader("Résumé final");

$newStats = $db->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN thumbnail_path IS NULL OR thumbnail_path = '' THEN 1 ELSE 0 END) as sans_miniature,
        SUM(CASE WHEN ocr_text IS NULL OR ocr_text = '' THEN 1 ELSE 0 END) as sans_ocr
    FROM documents
    WHERE deleted_at IS NULL
")->fetch(PDO::FETCH_ASSOC);

$thumbPercent = round((1 - $newStats['sans_miniature'] / max(1, $newStats['total'])) * 100);
$ocrPercent = round((1 - $newStats['sans_ocr'] / max(1, $newStats['total'])) * 100);

echo "Documents avec miniature: {$thumbPercent}%\n";
echo "Documents avec contenu:   {$ocrPercent}%\n";

if ($thumbPercent >= 80 && $ocrPercent >= 80) {
    echo "\n[SUCCÈS] Objectifs atteints (>80%)\n";
} else {
    echo "\n[EN COURS] Relancez le script pour continuer le traitement\n";
    echo "           php scripts/process_pending.php --limit=" . ($options['limit'] * 2) . "\n";
}

echo "\n";

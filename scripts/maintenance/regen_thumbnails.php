<?php
/**
 * Script de régénération COMPLÈTE des miniatures
 * Usage: php regen_thumbnails.php
 */

require_once __DIR__ . '/vendor/autoload.php';

use KDocs\Core\Database;
use KDocs\Core\Config;
use KDocs\Services\ThumbnailGenerator;

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║         K-DOCS - RÉGÉNÉRATION MINIATURES                     ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

$config = require __DIR__ . '/config/config.php';
$db = Database::getInstance();
$gen = new ThumbnailGenerator();

// Vérifier les outils
echo "=== OUTILS DISPONIBLES ===\n";
$tools = $gen->getAvailableTools();
foreach ($tools as $name => $value) {
    if (is_bool($value)) {
        echo ($value ? "✓" : "✗") . " $name\n";
    } else {
        echo "  $name: $value\n";
    }
}
echo "\n";

// Chemins
$basePath = realpath($config['storage']['documents'] ?? __DIR__ . '/storage/documents');
$thumbPath = realpath($config['storage']['thumbnails'] ?? __DIR__ . '/storage/thumbnails');

echo "=== CHEMINS ===\n";
echo "Documents: $basePath\n";
echo "Miniatures: $thumbPath\n\n";

if (!$basePath || !is_dir($basePath)) {
    echo "ERREUR: Dossier documents introuvable!\n";
    exit(1);
}

// Récupérer tous les documents
$stmt = $db->query("
    SELECT id, filename, original_filename, file_path, relative_path, mime_type
    FROM documents
    WHERE deleted_at IS NULL
    ORDER BY id
");
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== TRAITEMENT DE " . count($documents) . " DOCUMENTS ===\n\n";

$stats = ['ok' => 0, 'fail' => 0, 'missing' => 0];

foreach ($documents as $doc) {
    $docId = $doc['id'];
    $filename = $doc['original_filename'] ?: $doc['filename'];

    // Construire le chemin complet du fichier
    $filePath = null;

    // Essayer plusieurs chemins possibles
    $possiblePaths = [
        $doc['file_path'],
        $basePath . DIRECTORY_SEPARATOR . $doc['file_path'],
        $basePath . DIRECTORY_SEPARATOR . $doc['relative_path'],
        $basePath . DIRECTORY_SEPARATOR . $doc['filename'],
    ];

    foreach ($possiblePaths as $path) {
        if ($path && file_exists($path)) {
            $filePath = $path;
            break;
        }
    }

    if (!$filePath) {
        echo "✗ [ID:$docId] $filename - FICHIER MANQUANT\n";
        $stats['missing']++;
        continue;
    }

    // Générer la miniature
    $thumbFilename = $gen->generate($filePath, $docId);

    if ($thumbFilename) {
        // Mettre à jour la DB
        $updateStmt = $db->prepare("UPDATE documents SET thumbnail_path = ? WHERE id = ?");
        $updateStmt->execute([$thumbFilename, $docId]);

        echo "✓ [ID:$docId] $filename\n";
        $stats['ok']++;
    } else {
        echo "✗ [ID:$docId] $filename - GÉNÉRATION ÉCHOUÉE\n";
        $stats['fail']++;
    }
}

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║                      RÉSUMÉ                                  ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "Réussis:         {$stats['ok']}\n";
echo "Échoués:         {$stats['fail']}\n";
echo "Fichiers manquants: {$stats['missing']}\n";
echo "\n";

if ($stats['ok'] > 0) {
    echo "✓ Rafraîchissez la page (Ctrl+Shift+R) pour voir les miniatures\n";
}

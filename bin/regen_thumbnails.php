<?php
/**
 * Script de régénération des miniatures pour documents Office
 * Usage: php regen_thumbnails.php
 */

require __DIR__ . '/vendor/autoload.php';

use KDocs\Core\Database;
use KDocs\Services\OfficeThumbnailService;
use KDocs\Services\ThumbnailGenerator;

echo "=== Régénération des miniatures Office ===\n\n";

$db = Database::getInstance();
$thumbService = new OfficeThumbnailService();
$pdfThumbService = new ThumbnailGenerator();

// Vérifier si OnlyOffice est disponible
$diag = $thumbService->getDiagnosticInfo();
echo "OnlyOffice disponible: " . ($diag['onlyoffice_available'] ? 'OUI' : 'NON') . "\n";
echo "OnlyOffice URL: " . $diag['onlyoffice_url'] . "\n\n";

if (!$diag['can_generate_thumbnails']) {
    echo "ERREUR: Aucun outil de génération de miniatures disponible.\n";
    echo "Configurez OnlyOffice ou LibreOffice.\n";
    exit(1);
}

// Trouver tous les documents Office
$stmt = $db->query("
    SELECT id, file_path, original_filename, filename
    FROM documents
    WHERE deleted_at IS NULL
    AND (
        original_filename LIKE '%.docx' OR original_filename LIKE '%.doc' OR
        original_filename LIKE '%.xlsx' OR original_filename LIKE '%.xls' OR
        original_filename LIKE '%.pptx' OR original_filename LIKE '%.ppt' OR
        original_filename LIKE '%.odt' OR original_filename LIKE '%.ods' OR
        original_filename LIKE '%.odp' OR original_filename LIKE '%.rtf'
    )
    ORDER BY id DESC
");

$docs = $stmt->fetchAll();
$total = count($docs);
$generated = 0;
$skipped = 0;
$errors = 0;

echo "Documents Office trouvés: $total\n\n";

foreach ($docs as $i => $doc) {
    $num = $i + 1;
    $thumbPath = __DIR__ . '/storage/thumbnails/' . $doc['id'] . '_thumb.png';

    // Vérifier si miniature existe déjà
    if (file_exists($thumbPath)) {
        echo "[$num/$total] #{$doc['id']} {$doc['original_filename']} - Miniature existe déjà\n";
        $skipped++;
        continue;
    }

    // Vérifier si le fichier source existe
    $filePath = $doc['file_path'];
    if (!file_exists($filePath)) {
        echo "[$num/$total] #{$doc['id']} {$doc['original_filename']} - ERREUR: Fichier source introuvable\n";
        $errors++;
        continue;
    }

    echo "[$num/$total] #{$doc['id']} {$doc['original_filename']} - Génération... ";

    $result = $thumbService->generateThumbnail($filePath, $doc['id']);

    if ($result && file_exists($result)) {
        echo "OK\n";
        $generated++;
    } else {
        echo "ÉCHEC\n";
        $errors++;
    }

    // Pause pour ne pas surcharger OnlyOffice
    usleep(500000); // 0.5 seconde
}

echo "\n=== Résumé ===\n";
echo "Total: $total\n";
echo "Générées: $generated\n";
echo "Existantes: $skipped\n";
echo "Erreurs: $errors\n";

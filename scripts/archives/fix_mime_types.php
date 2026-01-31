<?php
/**
 * K-Docs - Fix MIME Types
 * Corrige les documents avec MIME type = application/octet-stream
 */

require_once __DIR__ . '/../vendor/autoload.php';

use KDocs\Core\Database;

echo "=== Fix MIME Types ===\n\n";

$db = Database::getInstance();

// Map des extensions vers MIME types
$mimeMap = [
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt' => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'odt' => 'application/vnd.oasis.opendocument.text',
    'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    'odp' => 'application/vnd.oasis.opendocument.presentation',
    'rtf' => 'application/rtf',
    'pdf' => 'application/pdf',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'tiff' => 'image/tiff',
    'tif' => 'image/tiff',
];

// Récupérer les documents avec MIME type générique
$stmt = $db->query("
    SELECT id, filename, original_filename, mime_type
    FROM documents
    WHERE mime_type = 'application/octet-stream' OR mime_type IS NULL OR mime_type = ''
    AND deleted_at IS NULL
");

$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
$count = count($documents);

echo "Documents à corriger: $count\n\n";

if ($count === 0) {
    echo "Aucun document à corriger.\n";
    exit(0);
}

$updateStmt = $db->prepare("UPDATE documents SET mime_type = ? WHERE id = ?");
$fixed = 0;
$skipped = 0;

foreach ($documents as $doc) {
    $filename = $doc['original_filename'] ?? $doc['filename'] ?? '';
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if (isset($mimeMap[$ext])) {
        $newMime = $mimeMap[$ext];
        $updateStmt->execute([$newMime, $doc['id']]);
        echo "  [OK] ID {$doc['id']}: $filename -> $newMime\n";
        $fixed++;
    } else {
        echo "  [--] ID {$doc['id']}: $filename (extension '$ext' non reconnue)\n";
        $skipped++;
    }
}

echo "\n=== Résultat ===\n";
echo "Corrigés: $fixed\n";
echo "Ignorés: $skipped\n";

<?php
/**
 * Seed doc jetable pour Lot C Playwright (sans OCR synchrone).
 * Usage : php tools/seed-lot-c-doc.php
 * Sortie JSON : {"id":123,"folder":"eval/lot-doc-c","filename":"..."}
 */
declare(strict_types=1);

define('KDOCS_ROOT', dirname(__DIR__));
require KDOCS_ROOT . '/vendor/autoload.php';
require_once KDOCS_ROOT . '/app/helpers.php';
\KDocs\Core\Config::load();

use KDocs\Core\Config;
use KDocs\Core\Database;
use KDocs\Models\Document;

$folder = 'eval/lot-doc-c';
$sample = KDOCS_ROOT . '/tests/samples/test.pdf';
if (!is_file($sample)) {
    fwrite(STDERR, "Sample PDF introuvable: $sample\n");
    exit(1);
}

$base = rtrim((string) Config::get('storage.documents'), '/\\');
$dir = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $folder);
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    fwrite(STDERR, "Impossible de créer $dir\n");
    exit(1);
}

$stamp = time();
$filename = "fiche_c_seed_{$stamp}.pdf";
$dest = $dir . DIRECTORY_SEPARATOR . $filename;
if (!copy($sample, $dest)) {
    fwrite(STDERR, "Copy failed\n");
    exit(1);
}

$relativePath = $folder . '/' . $filename;
$userId = (int) (Database::getInstance()->query("SELECT id FROM users WHERE username='root' LIMIT 1")->fetchColumn() ?: 1);

$docId = Document::create([
    'title' => pathinfo($filename, PATHINFO_FILENAME),
    'filename' => $filename,
    'original_filename' => $filename,
    'file_path' => $dest,
    'file_size' => filesize($dest),
    'mime_type' => 'application/pdf',
    'created_by' => $userId,
]);

$db = Database::getInstance();
$db->prepare('UPDATE documents SET relative_path = ?, validation_status = NULL WHERE id = ?')
    ->execute([$relativePath, $docId]);

echo json_encode(['id' => $docId, 'folder' => $folder, 'filename' => $filename], JSON_UNESCAPED_UNICODE) . "\n";

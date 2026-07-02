<?php
/**
 * Seed document jetable pour le spec Playwright legal-seal (WORM P2).
 * Usage : php tools/seed-legal-seal.php  → {"id": N}
 */
declare(strict_types=1);

define('KDOCS_ROOT', dirname(__DIR__));
require KDOCS_ROOT . '/vendor/autoload.php';
require_once KDOCS_ROOT . '/app/helpers.php';
\KDocs\Core\Config::load();

use KDocs\Core\Config;
use KDocs\Core\Database;
use KDocs\Models\Document;

$folder = 'eval/legal-seal';
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
$filename = "test_legal_seal_{$stamp}.pdf";
$dest = $dir . DIRECTORY_SEPARATOR . $filename;
if (!copy($sample, $dest)) {
    fwrite(STDERR, "Copy failed\n");
    exit(1);
}

$db = Database::getInstance();
$userId = (int) ($db->query("SELECT id FROM users WHERE username='root' LIMIT 1")->fetchColumn() ?: 1);

$docId = Document::create([
    'title' => 'test_legal_seal_' . $stamp,
    'filename' => $filename,
    'original_filename' => $filename,
    'file_path' => $dest,
    'file_size' => filesize($dest),
    'mime_type' => 'application/pdf',
    'created_by' => $userId,
]);

$db->prepare('UPDATE documents SET relative_path = ? WHERE id = ?')->execute([$folder . '/' . $filename, $docId]);

echo json_encode(['id' => $docId]) . PHP_EOL;

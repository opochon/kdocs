<?php
/**
 * Seed document en attente de validation pour Lot D Playwright (sans OCR).
 * Usage : php tools/seed-lot-d-validation.php
 */
declare(strict_types=1);

define('KDOCS_ROOT', dirname(__DIR__));
require KDOCS_ROOT . '/vendor/autoload.php';
require_once KDOCS_ROOT . '/app/helpers.php';
\KDocs\Core\Config::load();

use KDocs\Core\Config;
use KDocs\Core\Database;
use KDocs\Models\Document;
use KDocs\Services\ValidationService;

$folder = 'eval/lot-d';
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
$filename = "lot_d_val_{$stamp}.pdf";
$dest = $dir . DIRECTORY_SEPARATOR . $filename;
if (!copy($sample, $dest)) {
    fwrite(STDERR, "Copy failed\n");
    exit(1);
}

$db = Database::getInstance();
$userId = (int) ($db->query("SELECT id FROM users WHERE username='root' LIMIT 1")->fetchColumn() ?: 1);

$relativePath = $folder . '/' . $filename;
$docId = Document::create([
    'title' => 'Lot D validation ' . $stamp,
    'filename' => $filename,
    'original_filename' => $filename,
    'file_path' => $dest,
    'file_size' => filesize($dest),
    'mime_type' => 'application/pdf',
    'created_by' => $userId,
]);

$db->prepare('UPDATE documents SET relative_path = ? WHERE id = ?')->execute([$relativePath, $docId]);

$validation = new ValidationService();
$result = $validation->submitForApproval($docId, $userId);
if (empty($result['success'])) {
    fwrite(STDERR, 'submitForApproval failed: ' . ($result['error'] ?? 'unknown') . "\n");
    exit(1);
}

echo json_encode(['id' => $docId, 'folder' => $folder, 'validation_status' => 'pending'], JSON_UNESCAPED_UNICODE) . "\n";

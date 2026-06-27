<?php
/**
 * Applique un fichier de migration .sql via la connexion DB de l'app.
 * Les migrations .sql ne passent pas par app/Core/Migrations.php (qui ne gère que les .php).
 *
 * Usage : php tools/apply-sql-migration.php database/migrations/031_document_read_receipts.sql
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/helpers.php';

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Fichier .sql introuvable: $file\n");
    exit(1);
}

$cfg = require __DIR__ . '/../config/config.php';
$d = $cfg['database'] ?? [];
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $d['host'] ?? '127.0.0.1',
    (int) ($d['port'] ?? 3306),
    $d['name'] ?? ''
);

try {
    $pdo = new PDO($dsn, $d['user'] ?? 'root', $d['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec((string) file_get_contents($file));
    echo 'Migration appliquee: ' . basename($file) . "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERREUR: ' . $e->getMessage() . "\n");
    exit(1);
}

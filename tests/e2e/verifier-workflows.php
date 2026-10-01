<?php

declare(strict_types=1);

/**
 * Empreinte ciblée d'un document pour prouver qu'un essai à blanc n'a rien écrit :
 * nombre d'entrées de journal, nombre d'instances de circuit, sha256 du fichier et du `.meta.json`.
 *
 *   DOCUMENT_ID=<id> php tests/e2e/verifier-workflows.php
 *
 * Sortie JSON : { document_id, journal, instances, fichier, meta }.
 */

$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';

use Ged\Base;
use Ged\Config;

putenv('DB_NAME=gedv2_test');
putenv('APP_ENV=test');

$documentId = (int) (getenv('DOCUMENT_ID') ?: 0);
$config = new Config($racine);
$pdo = Base::pdo($config);
$fonds = $config->chemin('STORAGE_ROOT', 'storage/documents');

$journal = 0;
$instances = 0;
$fichierMeta = '';
try {
    $journal = (int) $pdo->query('SELECT COUNT(*) FROM workflow_journal WHERE document_id = ' . $documentId)->fetchColumn();
    $instances = (int) $pdo->query('SELECT COUNT(*) FROM workflow_instances WHERE document_id = ' . $documentId)->fetchColumn();
} catch (Throwable $e) {
    $journal = -1;
    $instances = -1;
}

$chemin = '';
try {
    $chemin = (string) $pdo->query('SELECT chemin_relatif FROM documents WHERE id = ' . $documentId)->fetchColumn();
} catch (Throwable) {
    $chemin = '';
}
if ($chemin !== '') {
    $fichier = $fonds . '/' . str_replace('\\', '/', $chemin);
    $fichierMeta = is_file($fichier) ? (string) hash_file('sha256', $fichier) : '';
}

echo json_encode([
    'document_id' => $documentId,
    'journal' => $journal,
    'instances' => $instances,
    'fichier' => $fichierMeta,
    'meta' => $chemin !== '' && is_file($fonds . '/' . $chemin . '.meta.json')
        ? (string) hash_file('sha256', $fonds . '/' . $chemin . '.meta.json') : '',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;

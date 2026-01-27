<?php
/**
 * Worker d'indexation en arriere-plan
 *
 * Usage: php indexing_worker.php
 * Ce script s'execute en arriere-plan et met a jour un fichier de progression
 */

// Eviter le timeout
set_time_limit(0);
ini_set('memory_limit', '256M');

// Charger l'application
require_once dirname(__DIR__, 2) . '/app/autoload.php';

use KDocs\Services\FilesystemIndexer;

$logFile = dirname(__DIR__, 2) . '/storage/logs/indexing_' . date('Y-m-d') . '.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

function logMessage(string $level, string $message, array $context = []): void
{
    global $logFile;

    $line = sprintf(
        "[%s] %s: %s %s\n",
        date('Y-m-d H:i:s'),
        $level,
        $message,
        $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : ''
    );

    @file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

// Verifier le fichier stop
$stopFile = dirname(__DIR__, 2) . '/storage/.indexing.stop';

try {
    logMessage('INFO', 'Demarrage worker indexation');

    $indexer = new FilesystemIndexer();

    // Verifier si arret demande avant de commencer
    if (file_exists($stopFile)) {
        logMessage('INFO', 'Arret demande avant demarrage');
        $indexer->resetProgress();
        @unlink($stopFile);
        exit(0);
    }

    $results = $indexer->indexAll(true); // true = track progress

    // Verifier arret pendant l'execution
    if (file_exists($stopFile)) {
        logMessage('INFO', 'Arret demande pendant execution');
        @unlink($stopFile);
    }

    if (isset($results['error'])) {
        logMessage('ERROR', 'Erreur indexation', ['error' => $results['error']]);
    } else {
        logMessage('INFO', 'Indexation terminee', $results);
    }

} catch (\Exception $e) {
    logMessage('ERROR', 'Exception worker', ['error' => $e->getMessage()]);

    // Marquer comme erreur dans le fichier de progression
    $progressFile = dirname(__DIR__, 2) . '/storage/.indexing_progress.json';
    $data = @json_decode(@file_get_contents($progressFile), true) ?: [];
    $data['status'] = 'error';
    $data['error'] = $e->getMessage();
    @file_put_contents($progressFile, json_encode($data, JSON_PRETTY_PRINT));
}

// Nettoyer le fichier stop si present
if (file_exists($stopFile)) {
    @unlink($stopFile);
}

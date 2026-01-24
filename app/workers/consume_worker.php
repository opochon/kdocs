<?php
/**
 * K-Docs - Worker pour Consume Folder
 * À exécuter périodiquement (cron) pour traiter les fichiers du dossier consume
 */

require_once __DIR__ . '/../autoload.php';

use KDocs\Services\ConsumeFolderService;

try {
    $service = new ConsumeFolderService();
    $results = $service->scan();
    
    echo "=== Consume Folder Worker ===\n";
    echo "Dossier surveillé: " . $service->getConsumePath() . "\n";
    echo "Fichiers scannés: " . ($results['scanned'] ?? 0) . "\n";
    echo "Fichiers importés: " . ($results['imported'] ?? 0) . "\n";
    echo "Fichiers ignorés: " . ($results['skipped'] ?? 0) . "\n";
    
    if (!empty($results['errors'])) {
        echo "\nErreurs:\n";
        foreach ($results['errors'] as $error) {
            echo "  - $error\n";
        }
        exit(1);
    }
    
    echo "\nSuccès!\n";
    exit(0);
} catch (\Exception $e) {
    echo "ERREUR: " . $e->getMessage() . "\n";
    exit(1);
}

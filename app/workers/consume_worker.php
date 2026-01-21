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
    echo "Fichiers traités: " . $results['processed'] . "\n";
    
    if (!empty($results['errors'])) {
        echo "Erreurs:\n";
        foreach ($results['errors'] as $error) {
            echo "  - $error\n";
        }
        exit(1);
    }
    
    echo "Succès!\n";
    exit(0);
} catch (\Exception $e) {
    echo "ERREUR: " . $e->getMessage() . "\n";
    exit(1);
}

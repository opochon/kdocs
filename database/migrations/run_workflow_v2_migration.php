<?php
/**
 * K-Docs - Migration Runner for Workflow V2
 * Execute: php run_workflow_v2_migration.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use KDocs\Core\Database;

echo "=== K-Docs Workflow V2 Migration ===\n\n";

try {
    $db = Database::getInstance();
    
    // Lire le fichier de migration
    $migrationFile = __DIR__ . '/workflow_v2/001_user_groups_complete.sql';
    if (!file_exists($migrationFile)) {
        die("Fichier de migration non trouvé: $migrationFile\n");
    }
    
    $sql = file_get_contents($migrationFile);
    
    // Exécuter chaque statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (empty($statement) || strpos($statement, '--') === 0) {
            continue;
        }
        
        try {
            echo "Executing: " . substr($statement, 0, 80) . "...\n";
            $db->exec($statement);
            echo "  ✓ OK\n";
        } catch (\PDOException $e) {
            // Ignorer les erreurs "table already exists" ou "duplicate entry"
            if (strpos($e->getMessage(), 'already exists') !== false || 
                strpos($e->getMessage(), 'Duplicate') !== false) {
                echo "  ⚠ Déjà existant (ignoré)\n";
            } else {
                echo "  ✗ Erreur: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "\n=== Migration terminée ===\n";
    
} catch (\Exception $e) {
    echo "Erreur fatale: " . $e->getMessage() . "\n";
    exit(1);
}

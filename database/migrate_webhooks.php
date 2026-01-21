<?php
/**
 * Migration Webhooks pour K-Docs
 * Exécuter avec: php database/migrate_webhooks.php
 */

require_once __DIR__ . '/../app/autoload.php';

use KDocs\Core\Database;

$db = Database::getInstance();

echo "🚀 Migration Webhooks...\n\n";

try {
    // Lire le fichier SQL
    $sql = file_get_contents(__DIR__ . '/migration_webhooks.sql');
    
    // Séparer les requêtes
    $queries = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($queries as $query) {
        if (empty($query) || strpos($query, '--') === 0) {
            continue;
        }
        
        try {
            $db->exec($query);
            echo "✅ Requête exécutée avec succès\n";
        } catch (PDOException $e) {
            // Ignorer les erreurs "table already exists" ou "column already exists"
            if (strpos($e->getMessage(), 'already exists') !== false || 
                strpos($e->getMessage(), 'Duplicate') !== false) {
                echo "⚠️  " . $e->getMessage() . " (ignoré)\n";
            } else {
                throw $e;
            }
        }
    }
    
    echo "\n✅ Migration Webhooks terminée avec succès !\n";
    
} catch (Exception $e) {
    echo "\n❌ Erreur lors de la migration : " . $e->getMessage() . "\n";
    exit(1);
}

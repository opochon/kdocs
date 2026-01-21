<?php
/**
 * Migration Audit Log pour K-Docs
 * Exécuter avec: php database/migrate_audit_log.php
 */

require_once __DIR__ . '/../app/autoload.php';

use KDocs\Core\Database;

$db = Database::getInstance();

echo "🚀 Migration Audit Log...\n\n";

try {
    // Lire le fichier SQL
    $sql = file_get_contents(__DIR__ . '/migration_audit_log.sql');
    
    // Séparer les requêtes
    $queries = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($queries as $query) {
        if (empty($query) || strpos($query, '--') === 0) {
            continue;
        }
        
        try {
            $db->exec($query);
            echo "✅ Table audit_logs créée\n";
        } catch (PDOException $e) {
            // Ignorer les erreurs "table already exists"
            if (strpos($e->getMessage(), 'already exists') !== false || 
                strpos($e->getMessage(), 'Duplicate') !== false) {
                echo "⚠️  Table existe déjà (ignoré)\n";
            } else {
                throw $e;
            }
        }
    }
    
    echo "\n✅ Migration Audit Log terminée avec succès !\n";
    
} catch (Exception $e) {
    echo "\n❌ Erreur lors de la migration : " . $e->getMessage() . "\n";
    exit(1);
}

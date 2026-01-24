<?php
/**
 * Migration: Ajout de la tâche planifiée pour scanner le dossier consume
 * Exécuter avec: php database/migrate_consume_folder_task.php
 */

require_once __DIR__ . '/../app/autoload.php';

use KDocs\Core\Database;

$db = Database::getInstance();

echo "🚀 Migration: Ajout de la tâche planifiée pour scanner le dossier consume\n";
echo "==========================================================================\n\n";

try {
    // Lire le fichier SQL
    $sql = file_get_contents(__DIR__ . '/migrations/011_add_consume_folder_task.sql');
    
    // Séparer les requêtes
    $queries = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($queries as $query) {
        if (empty($query) || strpos($query, '--') === 0) {
            continue;
        }
        
        try {
            $db->exec($query);
            if (strpos($query, 'INSERT') !== false) {
                echo "✅ Tâche planifiée 'Scan dossier consume' ajoutée\n";
            }
        } catch (PDOException $e) {
            // Ignorer les erreurs "Duplicate entry" (tâche déjà existante)
            if (strpos($e->getMessage(), 'Duplicate entry') !== false || 
                strpos($e->getMessage(), 'already exists') !== false) {
                echo "⚠️  Tâche existe déjà (ignoré)\n";
            } else {
                throw $e;
            }
        }
    }
    
    echo "\n✅ Migration terminée avec succès !\n";
    echo "\nLa tâche 'Scan dossier consume' sera exécutée toutes les 5 minutes.\n";
    echo "Assurez-vous que le worker task_worker.php est exécuté régulièrement (cron ou tâche planifiée).\n";
    
} catch (Exception $e) {
    echo "\n❌ Erreur lors de la migration : " . $e->getMessage() . "\n";
    exit(1);
}

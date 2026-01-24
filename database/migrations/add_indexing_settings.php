<?php
/**
 * Migration - Ajout des paramètres d'indexation
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Core/Database.php';

use KDocs\Core\Database;

echo "=== Migration - Paramètres d'indexation ===\n\n";

try {
    $db = Database::getInstance();
    
    // Vérifier si la table settings existe
    $tableExists = false;
    try {
        $db->query("SELECT 1 FROM settings LIMIT 1");
        $tableExists = true;
    } catch (\Exception $e) {
        echo "⚠️  Table settings n'existe pas, création...\n";
        $db->exec("
            CREATE TABLE IF NOT EXISTS settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                key_name VARCHAR(255) UNIQUE NOT NULL,
                value TEXT,
                description TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $tableExists = true;
        echo "✅ Table settings créée\n\n";
    }
    
    if ($tableExists) {
        $settings = [
            ['indexing_max_concurrent_queues', '2', 'Nombre max de queues simultanées'],
            ['indexing_process_priority', '10', 'Priorité processus (0-19, Linux seulement)'],
            ['indexing_memory_limit', '128', 'Mémoire max par worker (MB)'],
            ['indexing_delay_between_files', '50', 'Pause entre fichiers (ms)'],
            ['indexing_delay_between_folders', '100', 'Pause entre dossiers (ms)'],
            ['indexing_batch_size', '20', 'Fichiers par batch'],
            ['indexing_batch_pause', '500', 'Pause après batch (ms)'],
            ['indexing_queue_timeout', '300', 'Timeout queue (secondes)'],
            ['indexing_progress_update_interval', '5', 'Intervalle mise à jour progression (secondes)'],
            ['indexing_turbo_mode', '0', 'Mode turbo (0=non, 1=oui)'],
        ];
        
        $stmt = $db->prepare("
            INSERT INTO settings (key_name, value, description) 
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE value = VALUES(value), description = VALUES(description)
        ");
        
        foreach ($settings as $setting) {
            $stmt->execute($setting);
            echo "✅ Setting {$setting[0]} = {$setting[1]}\n";
        }
        
        echo "\n✅ Migration terminée avec succès\n";
    }
    
} catch (\Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
    exit(1);
}

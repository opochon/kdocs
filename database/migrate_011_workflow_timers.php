<?php
/**
 * Migration 011: Table workflow_timers
 * Pour gérer les timers/délais dans les workflows
 */

require_once __DIR__ . '/../app/Core/Database.php';

use KDocs\Core\Database;

function columnExists($db, $table, $column): bool
{
    try {
        $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $stmt->rowCount() > 0;
    } catch (\Exception $e) {
        return false;
    }
}

function tableExists($db, $table): bool
{
    try {
        $stmt = $db->query("SHOW TABLES LIKE '$table'");
        return $stmt->rowCount() > 0;
    } catch (\Exception $e) {
        return false;
    }
}

try {
    $db = Database::getInstance();
    
    echo "Migration 011: Création table workflow_timers...\n";
    
    // Vérifier si la table existe déjà
    if (tableExists($db, 'workflow_timers')) {
        echo "Table workflow_timers existe déjà. Vérification des colonnes...\n";
        
        // Vérifier les colonnes essentielles
        $requiredColumns = ['id', 'execution_id', 'node_id', 'timer_type', 'fire_at', 'status'];
        $missingColumns = [];
        
        foreach ($requiredColumns as $col) {
            if (!columnExists($db, 'workflow_timers', $col)) {
                $missingColumns[] = $col;
            }
        }
        
        if (empty($missingColumns)) {
            echo "Table workflow_timers est complète. Migration terminée.\n";
            exit(0);
        } else {
            echo "Colonnes manquantes: " . implode(', ', $missingColumns) . "\n";
            echo "Veuillez supprimer la table et relancer la migration.\n";
            exit(1);
        }
    }
    
    // Lire le fichier SQL
    $sqlFile = __DIR__ . '/migrations/011_workflow_timers.sql';
    if (!file_exists($sqlFile)) {
        throw new \Exception("Fichier SQL introuvable: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Exécuter la migration
    $db->exec($sql);
    
    echo "Table workflow_timers créée avec succès.\n";
    echo "Migration 011 terminée.\n";
    
} catch (\Exception $e) {
    echo "ERREUR Migration 011: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}

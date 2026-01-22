<?php
/**
 * Migration PHP pour ajouter les colonnes manquantes aux workflows
 * Compatible avec MySQL qui ne supporte pas IF NOT EXISTS dans ALTER TABLE
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Core/Config.php';

use KDocs\Core\Database;
use KDocs\Core\Config;

echo "Migration: Ajout des colonnes manquantes aux workflows...\n\n";

try {
    $db = Database::getInstance();
    
    // Fonction pour vérifier si une colonne existe
    $columnExists = function($table, $column) use ($db) {
        try {
            $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            return $stmt->fetch() !== false;
        } catch (\Exception $e) {
            return false;
        }
    };
    
    // Ajouter les colonnes pour workflow_triggers
    echo "1. Ajout des colonnes aux workflow_triggers...\n";
    $triggerColumns = [
        'sources' => "TEXT DEFAULT NULL COMMENT 'JSON: consume_folder, api_upload, mail_fetch'",
        'filter_filename' => "VARCHAR(500) DEFAULT NULL",
        'filter_path' => "VARCHAR(500) DEFAULT NULL",
        'filter_has_tags' => "TEXT DEFAULT NULL COMMENT 'JSON array of tag IDs'",
        'filter_has_all_tags' => "TEXT DEFAULT NULL COMMENT 'JSON array of tag IDs (ALL must match)'",
        'filter_has_not_tags' => "TEXT DEFAULT NULL COMMENT 'JSON array of tag IDs to exclude'",
        'filter_has_correspondent' => "INT DEFAULT NULL",
        'filter_has_not_correspondents' => "TEXT DEFAULT NULL COMMENT 'JSON array'",
        'filter_has_document_type' => "INT DEFAULT NULL",
        'filter_has_not_document_types' => "TEXT DEFAULT NULL COMMENT 'JSON array'",
        'filter_has_storage_path' => "INT DEFAULT NULL",
        'filter_has_not_storage_paths' => "TEXT DEFAULT NULL COMMENT 'JSON array'",
        'match_text' => "VARCHAR(500) DEFAULT NULL COMMENT 'Text to match in content'",
        'matching_algorithm' => "ENUM('any', 'all', 'exact', 'regex', 'fuzzy') DEFAULT 'any'",
        'is_insensitive' => "BOOLEAN DEFAULT TRUE",
        'schedule_date_field' => "ENUM('created', 'added', 'modified', 'custom_field') DEFAULT 'created'",
        'schedule_date_custom_field' => "INT DEFAULT NULL",
        'schedule_offset_days' => "INT DEFAULT 0",
        'schedule_is_recurring' => "BOOLEAN DEFAULT FALSE",
        'schedule_recurring_interval_days' => "INT DEFAULT 30",
    ];
    
    foreach ($triggerColumns as $column => $definition) {
        if (!$columnExists('workflow_triggers', $column)) {
            try {
                $db->exec("ALTER TABLE workflow_triggers ADD COLUMN `$column` $definition");
                echo "   ✅ Colonne $column ajoutée\n";
            } catch (\Exception $e) {
                echo "   ⚠️  Erreur pour $column: " . $e->getMessage() . "\n";
            }
        } else {
            echo "   ℹ️  Colonne $column existe déjà\n";
        }
    }
    
    // Mettre à jour trigger_type ENUM
    echo "\n2. Mise à jour du type trigger_type...\n";
    try {
        $db->exec("ALTER TABLE workflow_triggers MODIFY COLUMN trigger_type ENUM('consumption', 'document_added', 'document_updated', 'scheduled', 'document_modified', 'tag_added', 'correspondent_added', 'type_added') NOT NULL");
        echo "   ✅ trigger_type mis à jour\n";
    } catch (\Exception $e) {
        echo "   ⚠️  Erreur mise à jour trigger_type: " . $e->getMessage() . "\n";
    }
    
    // Ajouter les colonnes pour workflow_actions
    echo "\n3. Ajout des colonnes aux workflow_actions...\n";
    $actionColumns = [
        'assign_title' => "VARCHAR(500) DEFAULT NULL COMMENT 'Title template with placeholders'",
        'assign_tags' => "TEXT DEFAULT NULL COMMENT 'JSON array of tag IDs'",
        'assign_document_type' => "INT DEFAULT NULL",
        'assign_correspondent' => "INT DEFAULT NULL",
        'assign_storage_path' => "INT DEFAULT NULL",
        'assign_owner' => "INT DEFAULT NULL",
        'assign_view_users' => "TEXT DEFAULT NULL COMMENT 'JSON array'",
        'assign_view_groups' => "TEXT DEFAULT NULL COMMENT 'JSON array'",
        'assign_change_users' => "TEXT DEFAULT NULL COMMENT 'JSON array'",
        'assign_change_groups' => "TEXT DEFAULT NULL COMMENT 'JSON array'",
        'assign_custom_fields' => "TEXT DEFAULT NULL COMMENT 'JSON array of field IDs'",
        'assign_custom_fields_values' => "TEXT DEFAULT NULL COMMENT 'JSON object {field_id: value}'",
        'remove_tags' => "TEXT DEFAULT NULL COMMENT 'JSON array'",
        'remove_all_tags' => "BOOLEAN DEFAULT FALSE",
        'remove_correspondents' => "TEXT DEFAULT NULL",
        'remove_all_correspondents' => "BOOLEAN DEFAULT FALSE",
        'remove_document_types' => "TEXT DEFAULT NULL",
        'remove_all_document_types' => "BOOLEAN DEFAULT FALSE",
        'remove_storage_paths' => "TEXT DEFAULT NULL",
        'remove_all_storage_paths' => "BOOLEAN DEFAULT FALSE",
        'remove_custom_fields' => "TEXT DEFAULT NULL",
        'remove_all_custom_fields' => "BOOLEAN DEFAULT FALSE",
        'remove_owners' => "TEXT DEFAULT NULL",
        'remove_all_owners' => "BOOLEAN DEFAULT FALSE",
        'remove_view_users' => "TEXT DEFAULT NULL",
        'remove_view_groups' => "TEXT DEFAULT NULL",
        'remove_change_users' => "TEXT DEFAULT NULL",
        'remove_change_groups' => "TEXT DEFAULT NULL",
        'remove_all_permissions' => "BOOLEAN DEFAULT FALSE",
        'email_subject' => "VARCHAR(500) DEFAULT NULL",
        'email_body' => "TEXT DEFAULT NULL",
        'email_to' => "VARCHAR(255) DEFAULT NULL",
        'email_include_document' => "BOOLEAN DEFAULT FALSE",
        'webhook_url' => "VARCHAR(500) DEFAULT NULL",
        'webhook_use_params' => "BOOLEAN DEFAULT FALSE",
        'webhook_as_json' => "BOOLEAN DEFAULT TRUE",
        'webhook_params' => "TEXT DEFAULT NULL COMMENT 'JSON object'",
        'webhook_body' => "TEXT DEFAULT NULL",
        'webhook_headers' => "TEXT DEFAULT NULL COMMENT 'JSON object'",
        'webhook_include_document' => "BOOLEAN DEFAULT FALSE",
    ];
    
    foreach ($actionColumns as $column => $definition) {
        if (!$columnExists('workflow_actions', $column)) {
            try {
                $db->exec("ALTER TABLE workflow_actions ADD COLUMN `$column` $definition");
                echo "   ✅ Colonne $column ajoutée\n";
            } catch (\Exception $e) {
                echo "   ⚠️  Erreur pour $column: " . $e->getMessage() . "\n";
            }
        } else {
            echo "   ℹ️  Colonne $column existe déjà\n";
        }
    }
    
    // Mettre à jour action_type pour utiliser INT
    echo "\n4. Mise à jour du type action_type...\n";
    try {
        // Vérifier le type actuel
        $stmt = $db->query("SHOW COLUMNS FROM workflow_actions WHERE Field = 'action_type'");
        $col = $stmt->fetch();
        
        if ($col && strpos($col['Type'], 'ENUM') !== false) {
            // Convertir les anciennes valeurs ENUM en INT
            $db->exec("UPDATE workflow_actions SET action_type = CASE 
                WHEN action_type = 'assign_tag' THEN 1
                WHEN action_type = 'assign_correspondent' THEN 1
                WHEN action_type = 'assign_type' THEN 1
                WHEN action_type = 'assign_storage_path' THEN 1
                WHEN action_type = 'set_field' THEN 1
                ELSE 1
            END");
            
            $db->exec("ALTER TABLE workflow_actions MODIFY COLUMN action_type INT NOT NULL COMMENT '1=Assignment, 2=Removal, 3=Email, 4=Webhook'");
            echo "   ✅ action_type converti en INT\n";
        } else {
            echo "   ℹ️  action_type est déjà INT\n";
        }
    } catch (\Exception $e) {
        echo "   ⚠️  Erreur mise à jour action_type: " . $e->getMessage() . "\n";
    }
    
    // Rendre action_value nullable
    echo "\n5. Mise à jour de action_value...\n";
    try {
        $db->exec("ALTER TABLE workflow_actions MODIFY COLUMN action_value TEXT NULL COMMENT 'Valeur de l\'action (deprecated, utiliser les colonnes spécifiques)'");
        echo "   ✅ action_value mis à jour\n";
    } catch (\Exception $e) {
        echo "   ⚠️  Erreur mise à jour action_value: " . $e->getMessage() . "\n";
    }
    
    echo "\n✅ Migration terminée avec succès!\n";
    
} catch (\Exception $e) {
    echo "❌ Erreur de migration: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}

<?php
/**
 * Migration pour compléter les actions de workflow (comme Paperless-ngx)
 * Types: Assignment (1), Removal (2), Email (3), Webhook (4)
 */

require_once __DIR__ . '/../app/autoload.php';

use KDocs\Core\Database;

$db = Database::getInstance();

echo "Migration des actions de workflow...\n\n";

try {
    // 1. Modifier action_type pour utiliser des valeurs numériques
    echo "1. Modification de action_type...\n";
    $db->exec("ALTER TABLE workflow_actions 
               MODIFY COLUMN action_type INT DEFAULT 1 COMMENT '1=Assignment, 2=Removal, 3=Email, 4=Webhook'");
    echo "   ✅ Colonne action_type modifiée\n\n";
    
    // 2. Supprimer l'ancienne colonne action_value et ajouter toutes les nouvelles colonnes
    echo "2. Ajout des colonnes pour Assignment...\n";
    $assignmentFields = [
        "assign_title VARCHAR(255) NULL",
        "assign_tags TEXT NULL COMMENT 'JSON array of tag IDs'",
        "assign_document_type INT NULL",
        "assign_correspondent INT NULL",
        "assign_storage_path INT NULL",
        "assign_custom_fields TEXT NULL COMMENT 'JSON array of custom field IDs'",
        "assign_custom_fields_values TEXT NULL COMMENT 'JSON object with custom field values'",
        "assign_owner INT NULL",
        "assign_view_users TEXT NULL COMMENT 'JSON array of user IDs'",
        "assign_view_groups TEXT NULL COMMENT 'JSON array of group IDs'",
        "assign_change_users TEXT NULL COMMENT 'JSON array of user IDs'",
        "assign_change_groups TEXT NULL COMMENT 'JSON array of group IDs'"
    ];
    
    foreach ($assignmentFields as $field) {
        $fieldName = explode(' ', $field)[0];
        try {
            $db->exec("ALTER TABLE workflow_actions ADD COLUMN $field");
            echo "   ✅ Colonne $fieldName ajoutée\n";
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                echo "   ⚠️ Colonne $fieldName existe déjà\n";
            } else {
                echo "   ❌ Erreur pour $fieldName: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "\n3. Ajout des colonnes pour Removal...\n";
    $removalFields = [
        "remove_tags TEXT NULL COMMENT 'JSON array of tag IDs'",
        "remove_all_tags BOOLEAN DEFAULT FALSE",
        "remove_correspondents TEXT NULL COMMENT 'JSON array of correspondent IDs'",
        "remove_all_correspondents BOOLEAN DEFAULT FALSE",
        "remove_document_types TEXT NULL COMMENT 'JSON array of document type IDs'",
        "remove_all_document_types BOOLEAN DEFAULT FALSE",
        "remove_storage_paths TEXT NULL COMMENT 'JSON array of storage path IDs'",
        "remove_all_storage_paths BOOLEAN DEFAULT FALSE",
        "remove_custom_fields TEXT NULL COMMENT 'JSON array of custom field IDs'",
        "remove_all_custom_fields BOOLEAN DEFAULT FALSE",
        "remove_owners TEXT NULL COMMENT 'JSON array of user IDs'",
        "remove_all_owners BOOLEAN DEFAULT FALSE",
        "remove_view_users TEXT NULL COMMENT 'JSON array of user IDs'",
        "remove_view_groups TEXT NULL COMMENT 'JSON array of group IDs'",
        "remove_change_users TEXT NULL COMMENT 'JSON array of user IDs'",
        "remove_change_groups TEXT NULL COMMENT 'JSON array of group IDs'",
        "remove_all_permissions BOOLEAN DEFAULT FALSE"
    ];
    
    foreach ($removalFields as $field) {
        $fieldName = explode(' ', $field)[0];
        try {
            $db->exec("ALTER TABLE workflow_actions ADD COLUMN $field");
            echo "   ✅ Colonne $fieldName ajoutée\n";
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                echo "   ⚠️ Colonne $fieldName existe déjà\n";
            } else {
                echo "   ❌ Erreur pour $fieldName: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "\n4. Ajout des colonnes pour Email...\n";
    $emailFields = [
        "email_subject VARCHAR(255) NULL",
        "email_body TEXT NULL",
        "email_to VARCHAR(255) NULL",
        "email_include_document BOOLEAN DEFAULT FALSE"
    ];
    
    foreach ($emailFields as $field) {
        $fieldName = explode(' ', $field)[0];
        try {
            $db->exec("ALTER TABLE workflow_actions ADD COLUMN $field");
            echo "   ✅ Colonne $fieldName ajoutée\n";
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                echo "   ⚠️ Colonne $fieldName existe déjà\n";
            } else {
                echo "   ❌ Erreur pour $fieldName: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "\n5. Ajout des colonnes pour Webhook...\n";
    $webhookFields = [
        "webhook_url VARCHAR(500) NULL",
        "webhook_use_params BOOLEAN DEFAULT FALSE",
        "webhook_as_json BOOLEAN DEFAULT TRUE",
        "webhook_params TEXT NULL COMMENT 'JSON object'",
        "webhook_body TEXT NULL",
        "webhook_headers TEXT NULL COMMENT 'JSON object'",
        "webhook_include_document BOOLEAN DEFAULT FALSE"
    ];
    
    foreach ($webhookFields as $field) {
        $fieldName = explode(' ', $field)[0];
        try {
            $db->exec("ALTER TABLE workflow_actions ADD COLUMN $field");
            echo "   ✅ Colonne $fieldName ajoutée\n";
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                echo "   ⚠️ Colonne $fieldName existe déjà\n";
            } else {
                echo "   ❌ Erreur pour $fieldName: " . $e->getMessage() . "\n";
            }
        }
    }
    
    // 6. Ajouter les clés étrangères
    echo "\n6. Ajout des clés étrangères...\n";
    $foreignKeys = [
        "ALTER TABLE workflow_actions ADD FOREIGN KEY (assign_document_type) REFERENCES document_types(id) ON DELETE SET NULL",
        "ALTER TABLE workflow_actions ADD FOREIGN KEY (assign_correspondent) REFERENCES correspondents(id) ON DELETE SET NULL",
        "ALTER TABLE workflow_actions ADD FOREIGN KEY (assign_storage_path) REFERENCES storage_paths(id) ON DELETE SET NULL",
        "ALTER TABLE workflow_actions ADD FOREIGN KEY (assign_owner) REFERENCES users(id) ON DELETE SET NULL"
    ];
    
    foreach ($foreignKeys as $fk) {
        try {
            $db->exec($fk);
            echo "   ✅ Clé étrangère ajoutée\n";
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already exists') !== false) {
                echo "   ⚠️ Clé étrangère existe déjà\n";
            } else {
                echo "   ⚠️ Erreur (peut être ignorée): " . $e->getMessage() . "\n";
            }
        }
    }
    
    // 7. Supprimer l'ancienne colonne action_value si elle existe
    echo "\n7. Suppression de l'ancienne colonne action_value...\n";
    try {
        $db->exec("ALTER TABLE workflow_actions DROP COLUMN action_value");
        echo "   ✅ Colonne action_value supprimée\n";
    } catch (\Exception $e) {
        echo "   ⚠️ Colonne action_value n'existe pas ou ne peut pas être supprimée\n";
    }
    
    echo "\n✅ Migration terminée avec succès!\n";
    
} catch (\Exception $e) {
    echo "\n❌ Erreur lors de la migration: " . $e->getMessage() . "\n";
    exit(1);
}

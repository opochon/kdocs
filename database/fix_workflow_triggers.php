<?php
/**
 * Script de migration pour corriger les types de triggers de workflows
 * Aligne avec Paperless-ngx : consumption, document_added, document_updated, scheduled
 */

require_once __DIR__ . '/../app/autoload.php';

use KDocs\Core\Database;

$db = Database::getInstance();

echo "Migration des triggers de workflows...\n\n";

try {
    // 1. Modifier la colonne trigger_type
    echo "1. Modification de la colonne trigger_type...\n";
    $db->exec("ALTER TABLE workflow_triggers 
               MODIFY COLUMN trigger_type ENUM('consumption', 'document_added', 'document_updated', 'scheduled') NOT NULL");
    echo "   ✅ Colonne trigger_type modifiée\n\n";
    
    // 2. Mettre à jour les valeurs existantes
    echo "2. Mise à jour des valeurs existantes...\n";
    $db->exec("UPDATE workflow_triggers SET trigger_type = 'document_added' WHERE trigger_type = 'document_added'");
    $db->exec("UPDATE workflow_triggers SET trigger_type = 'document_updated' WHERE trigger_type = 'document_modified'");
    echo "   ✅ Valeurs mises à jour\n\n";
    
    // 3. Ajouter les nouvelles colonnes pour les filtres
    echo "3. Ajout des colonnes de filtres...\n";
    $columns = [
        "filter_filename VARCHAR(255) NULL COMMENT 'Filtre sur le nom de fichier'",
        "filter_path VARCHAR(255) NULL COMMENT 'Filtre sur le chemin'",
        "filter_has_tags TEXT NULL COMMENT 'IDs de tags (JSON array)'",
        "filter_has_all_tags TEXT NULL COMMENT 'IDs de tags requis (JSON array)'",
        "filter_has_not_tags TEXT NULL COMMENT 'IDs de tags exclus (JSON array)'",
        "filter_has_correspondent INT NULL COMMENT 'ID du correspondant'",
        "filter_has_not_correspondents TEXT NULL COMMENT 'IDs de correspondants exclus (JSON array)'",
        "filter_has_document_type INT NULL COMMENT 'ID du type de document'",
        "filter_has_not_document_types TEXT NULL COMMENT 'IDs de types exclus (JSON array)'",
        "filter_has_storage_path INT NULL COMMENT 'ID du chemin de stockage'",
        "filter_has_not_storage_paths TEXT NULL COMMENT 'IDs de chemins exclus (JSON array)'",
        "match VARCHAR(255) NULL COMMENT 'Expression de correspondance'",
        "matching_algorithm INT DEFAULT 0 COMMENT 'Algorithme de correspondance'",
        "is_insensitive BOOLEAN DEFAULT FALSE COMMENT 'Correspondance insensible à la casse'",
        "schedule_offset_days INT NULL COMMENT 'Décalage en jours pour trigger Scheduled'",
        "schedule_is_recurring BOOLEAN DEFAULT FALSE COMMENT 'Récurrence pour trigger Scheduled'",
        "schedule_recurring_interval_days INT NULL COMMENT 'Intervalle de récurrence en jours'",
        "schedule_date_field VARCHAR(50) NULL COMMENT 'Champ de date pour trigger Scheduled'",
        "schedule_date_custom_field INT NULL COMMENT 'ID du champ personnalisé pour schedule_date_field'"
    ];
    
    foreach ($columns as $column) {
        $columnName = explode(' ', $column)[0];
        try {
            $db->exec("ALTER TABLE workflow_triggers ADD COLUMN $column");
            echo "   ✅ Colonne $columnName ajoutée\n";
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                echo "   ⚠️ Colonne $columnName existe déjà\n";
            } else {
                echo "   ❌ Erreur pour $columnName: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "\n✅ Migration terminée avec succès!\n";
    
} catch (\Exception $e) {
    echo "\n❌ Erreur lors de la migration: " . $e->getMessage() . "\n";
    exit(1);
}

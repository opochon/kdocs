<?php
/**
 * Migration 010: Champs obligatoires
 */

require_once __DIR__ . '/../app/autoload.php';

use KDocs\Core\Database;

$db = Database::getInstance();

echo "🚀 Migration 010: Champs obligatoires\n";
echo "======================================\n\n";

try {
    $columnExists = function($table, $column) use ($db) {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
            return $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    };
    
    echo "1. Ajout colonne is_required...\n";
    
    if (!$columnExists('classification_fields', 'is_required')) {
        $db->exec("ALTER TABLE classification_fields ADD COLUMN is_required BOOLEAN DEFAULT FALSE COMMENT 'Champ obligatoire, ne peut pas être supprimé'");
        echo "   ✅ Colonne is_required ajoutée\n";
    } else {
        echo "   ⚠️  Colonne is_required existe déjà\n";
    }
    
    echo "\n2. Marquage des champs obligatoires (date, type)...\n";
    $db->exec("UPDATE classification_fields SET is_required = TRUE WHERE field_code IN ('date', 'type')");
    echo "   ✅ Champs date et type marqués comme obligatoires\n";
    
    echo "\n✅ Migration 010 terminée avec succès !\n";
    
} catch (Exception $e) {
    echo "\n❌ Erreur lors de la migration : " . $e->getMessage() . "\n";
    exit(1);
}

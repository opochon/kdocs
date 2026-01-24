<?php
/**
 * Migration 008: Champs paramétrables pour classification
 * Exécuter avec: php database/migrate_008_classification_fields.php
 */

require_once __DIR__ . '/../app/autoload.php';

use KDocs\Core\Database;

$db = Database::getInstance();

echo "🚀 Migration 008: Champs paramétrables pour classification\n";
echo "===========================================================\n\n";

try {
    // Fonction pour vérifier si une colonne existe
    $columnExists = function($table, $column) use ($db) {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
            return $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    };
    
    // Étendre custom_fields
    echo "1. Extension de la table custom_fields...\n";
    $customFieldsColumns = [
        'is_active' => 'BOOLEAN DEFAULT TRUE',
        'use_for_storage_path' => 'BOOLEAN DEFAULT FALSE',
        'use_for_tag' => 'BOOLEAN DEFAULT FALSE',
        'storage_path_position' => 'INT DEFAULT NULL',
        'matching_keywords' => 'TEXT',
        'matching_algorithm' => "VARCHAR(20) DEFAULT 'any'",
        'field_code' => 'VARCHAR(50)'
    ];
    foreach ($customFieldsColumns as $col => $def) {
        if (!$columnExists('custom_fields', $col)) {
            $db->exec("ALTER TABLE custom_fields ADD COLUMN `$col` $def");
            echo "   ✅ Colonne $col ajoutée\n";
        } else {
            echo "   ⚠️  Colonne $col existe déjà\n";
        }
    }
    
    // Créer table classification_fields
    echo "\n2. Création table classification_fields...\n";
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS classification_fields (
                id INT AUTO_INCREMENT PRIMARY KEY,
                field_code VARCHAR(50) UNIQUE NOT NULL,
                field_name VARCHAR(100) NOT NULL,
                field_type ENUM('year', 'supplier', 'type', 'amount', 'date', 'custom') NOT NULL,
                is_active BOOLEAN DEFAULT TRUE,
                use_for_storage_path BOOLEAN DEFAULT TRUE,
                storage_path_position INT DEFAULT NULL,
                use_for_tag BOOLEAN DEFAULT FALSE,
                matching_keywords TEXT,
                matching_algorithm VARCHAR(20) DEFAULT 'any',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_code (field_code),
                INDEX idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "   ✅ Table classification_fields créée\n";
    } catch (\Exception $e) {
        if (strpos($e->getMessage(), 'already exists') === false) {
            throw $e;
        }
        echo "   ⚠️  Table existe déjà\n";
    }
    
    // Insérer les champs standards
    echo "\n3. Insertion des champs standards...\n";
    $fields = [
        ['year', 'Année', 'year', 1],
        ['supplier', 'Fournisseur', 'supplier', 2],
        ['type', 'Type de document', 'type', 3],
        ['amount', 'Montant', 'amount', NULL],
        ['date', 'Date du document', 'date', NULL],
    ];
    
    foreach ($fields as $field) {
        try {
            $db->prepare("
                INSERT INTO classification_fields (field_code, field_name, field_type, is_active, use_for_storage_path, storage_path_position, use_for_tag)
                VALUES (?, ?, ?, TRUE, ?, ?, FALSE)
            ")->execute([
                $field[0], $field[1], $field[2],
                $field[3] !== NULL ? 1 : 0, $field[3]
            ]);
            echo "   ✅ Champ {$field[1]} ajouté\n";
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                echo "   ⚠️  Champ {$field[1]} existe déjà\n";
            } else {
                echo "   ❌ Erreur champ {$field[1]}: " . $e->getMessage() . "\n";
            }
        }
    }
    
    // Ajouter colonne uploaded_at
    echo "\n4. Ajout colonne uploaded_at à documents...\n";
    if (!$columnExists('documents', 'uploaded_at')) {
        $db->exec("ALTER TABLE documents ADD COLUMN uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Date d\'upload du document'");
        echo "   ✅ Colonne uploaded_at ajoutée\n";
    } else {
        echo "   ⚠️  Colonne uploaded_at existe déjà\n";
    }
    
    echo "\n✅ Migration 008 terminée avec succès !\n";
    
} catch (Exception $e) {
    echo "\n❌ Erreur lors de la migration : " . $e->getMessage() . "\n";
    exit(1);
}

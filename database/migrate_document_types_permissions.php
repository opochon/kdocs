<?php
/**
 * Migration pour ajouter les permissions aux types de documents (comme Paperless-ngx)
 */

require_once __DIR__ . '/../app/autoload.php';

use KDocs\Core\Database;

$db = Database::getInstance();

echo "Migration des permissions pour document_types...\n\n";

try {
    // 1. Ajouter les colonnes de permissions
    echo "1. Ajout des colonnes de permissions...\n";
    $columns = [
        "owner_id INT NULL COMMENT 'Propriétaire du type de document'",
        "view_users TEXT NULL COMMENT 'IDs des utilisateurs autorisés à voir (JSON array)'",
        "view_groups TEXT NULL COMMENT 'IDs des groupes autorisés à voir (JSON array)'",
        "modify_users TEXT NULL COMMENT 'IDs des utilisateurs autorisés à modifier (JSON array)'",
        "modify_groups TEXT NULL COMMENT 'IDs des groupes autorisés à modifier (JSON array)'",
        "is_insensitive BOOLEAN DEFAULT TRUE COMMENT 'Correspondance insensible à la casse'"
    ];
    
    foreach ($columns as $column) {
        $columnName = explode(' ', $column)[0];
        try {
            $db->exec("ALTER TABLE document_types ADD COLUMN $column");
            echo "   ✅ Colonne $columnName ajoutée\n";
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                echo "   ⚠️ Colonne $columnName existe déjà\n";
            } else {
                echo "   ❌ Erreur pour $columnName: " . $e->getMessage() . "\n";
            }
        }
    }
    
    // 2. Ajouter la clé étrangère pour owner_id
    echo "\n2. Ajout de la clé étrangère owner_id...\n";
    try {
        $db->exec("ALTER TABLE document_types ADD FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL");
        echo "   ✅ Clé étrangère owner_id ajoutée\n";
    } catch (\Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "   ⚠️ Clé étrangère owner_id existe déjà\n";
        } else {
            echo "   ❌ Erreur: " . $e->getMessage() . "\n";
        }
    }
    
    // 3. Modifier matching_algorithm pour utiliser des valeurs numériques
    echo "\n3. Modification de matching_algorithm...\n";
    try {
        // Vérifier le type actuel
        $check = $db->query("SHOW COLUMNS FROM document_types WHERE Field = 'matching_algorithm'")->fetch();
        
        if ($check && strpos($check['Type'], 'enum') !== false || strpos($check['Type'], 'varchar') !== false) {
            // Convertir ENUM/VARCHAR en INT
            $db->exec("ALTER TABLE document_types MODIFY COLUMN matching_algorithm INT DEFAULT 6 COMMENT '0=None, 1=Any, 2=All, 3=Literal, 4=Regex, 5=Fuzzy, 6=Auto'");
            echo "   ✅ Colonne matching_algorithm convertie en INT\n";
            
            // Mettre à jour les valeurs existantes
            $db->exec("UPDATE document_types SET matching_algorithm = 6 WHERE matching_algorithm = 'auto' OR matching_algorithm IS NULL");
            $db->exec("UPDATE document_types SET matching_algorithm = 0 WHERE matching_algorithm = 'none'");
            $db->exec("UPDATE document_types SET matching_algorithm = 1 WHERE matching_algorithm = 'any'");
            $db->exec("UPDATE document_types SET matching_algorithm = 2 WHERE matching_algorithm = 'all'");
            $db->exec("UPDATE document_types SET matching_algorithm = 3 WHERE matching_algorithm = 'exact' OR matching_algorithm = 'literal'");
            $db->exec("UPDATE document_types SET matching_algorithm = 4 WHERE matching_algorithm = 'regex'");
            $db->exec("UPDATE document_types SET matching_algorithm = 5 WHERE matching_algorithm = 'fuzzy'");
            echo "   ✅ Valeurs mises à jour\n";
        } else {
            echo "   ⚠️ Colonne matching_algorithm est déjà de type INT\n";
        }
    } catch (\Exception $e) {
        echo "   ⚠️ Erreur (peut être ignorée): " . $e->getMessage() . "\n";
    }
    
    echo "\n✅ Migration terminée avec succès!\n";
    
} catch (\Exception $e) {
    echo "\n❌ Erreur lors de la migration: " . $e->getMessage() . "\n";
    exit(1);
}

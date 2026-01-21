<?php
/**
 * Migration Phase 2 - Custom Fields, Storage Paths, ASN, Document Notes
 * Exécuter ce script pour créer les tables nécessaires
 */

require_once __DIR__ . '/../app/autoload.php';

use KDocs\Core\Database;

$db = Database::getInstance();

echo "🚀 Migration Phase 2 - K-Docs\n";
echo "==============================\n\n";

try {
    // 1. Custom Fields
    echo "1. Création table custom_fields...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS custom_fields (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            field_type ENUM('text', 'number', 'date', 'boolean', 'url', 'email', 'select') NOT NULL DEFAULT 'text',
            options TEXT NULL COMMENT 'JSON pour les options (select)',
            required BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "   ✅ Table custom_fields créée\n\n";
    
    echo "2. Création table document_custom_field_values...\n";
    try {
        // Désactiver temporairement la vérification des clés étrangères
        $db->exec("SET FOREIGN_KEY_CHECKS = 0");
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_custom_field_values (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_id INT NOT NULL,
                custom_field_id INT NOT NULL,
                value TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_document_field (document_id, custom_field_id),
                INDEX idx_document (document_id),
                INDEX idx_field (custom_field_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Ajouter les clés étrangères après création de la table
        try {
            $db->exec("ALTER TABLE document_custom_field_values ADD FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE");
        } catch (\Exception $e) {
            // Clé étrangère existe peut-être déjà
        }
        
        try {
            $db->exec("ALTER TABLE document_custom_field_values ADD FOREIGN KEY (custom_field_id) REFERENCES custom_fields(id) ON DELETE CASCADE");
        } catch (\Exception $e) {
            // Clé étrangère existe peut-être déjà
        }
        
        // Réactiver la vérification des clés étrangères
        $db->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        echo "   ✅ Table document_custom_field_values créée\n\n";
    } catch (\Exception $e) {
        $db->exec("SET FOREIGN_KEY_CHECKS = 1");
        if (strpos($e->getMessage(), 'already exists') === false) {
            throw $e;
        }
        echo "   ⚠️ Table document_custom_field_values existe déjà\n\n";
    }
    
    // 2. Storage Paths
    echo "3. Création table storage_paths...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS storage_paths (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            path VARCHAR(500) NOT NULL COMMENT 'Chemin relatif dans le filesystem',
            `match` VARCHAR(500) NULL COMMENT 'Texte de correspondance pour matching automatique',
            matching_algorithm ENUM('none', 'any', 'all', 'exact', 'regex', 'fuzzy', 'auto') DEFAULT 'none',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_path (path),
            INDEX idx_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "   ✅ Table storage_paths créée\n\n";
    
    echo "4. Ajout colonne storage_path_id dans documents...\n";
    try {
        $checkStmt = $db->query("SHOW COLUMNS FROM documents LIKE 'storage_path_id'");
        if ($checkStmt->rowCount() == 0) {
            $db->exec("ALTER TABLE documents ADD COLUMN storage_path_id INT NULL AFTER document_type_id");
            $db->exec("ALTER TABLE documents ADD INDEX idx_storage_path (storage_path_id)");
            try {
                $db->exec("ALTER TABLE documents ADD FOREIGN KEY (storage_path_id) REFERENCES storage_paths(id) ON DELETE SET NULL");
            } catch (\Exception $e) {
                // Clé étrangère peut échouer si la table n'existe pas encore
            }
        }
        echo "   ✅ Colonne storage_path_id ajoutée\n\n";
    } catch (\Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "   ⚠️ Colonne storage_path_id existe déjà\n\n";
        } else {
            echo "   ⚠️ Erreur (peut être ignorée si colonne existe déjà): " . $e->getMessage() . "\n\n";
        }
    }
    
    // 3. ASN
    echo "5. Ajout colonne asn dans documents...\n";
    try {
        $checkStmt = $db->query("SHOW COLUMNS FROM documents LIKE 'asn'");
        if ($checkStmt->rowCount() == 0) {
            $db->exec("ALTER TABLE documents ADD COLUMN asn INT NULL COMMENT 'Archive Serial Number' AFTER id");
            try {
                $db->exec("ALTER TABLE documents ADD UNIQUE KEY unique_asn (asn)");
            } catch (\Exception $e) {
                // Clé unique peut échouer si des valeurs NULL existent
            }
            try {
                $db->exec("ALTER TABLE documents ADD INDEX idx_asn (asn)");
            } catch (\Exception $e) {
                // Index peut exister déjà
            }
        }
        echo "   ✅ Colonne asn ajoutée\n\n";
    } catch (\Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "   ⚠️ Colonne asn existe déjà\n\n";
        } else {
            echo "   ⚠️ Erreur (peut être ignorée): " . $e->getMessage() . "\n\n";
        }
    }
    
    // 4. Document Notes
    echo "6. Création table document_notes...\n";
    try {
        $db->exec("SET FOREIGN_KEY_CHECKS = 0");
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_notes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_id INT NOT NULL,
                user_id INT NULL COMMENT 'ID de l\'utilisateur qui a créé la note',
                note TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_document (document_id),
                INDEX idx_user (user_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Ajouter les clés étrangères après création
        try {
            $db->exec("ALTER TABLE document_notes ADD FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE");
        } catch (\Exception $e) {
            // Clé étrangère existe peut-être déjà
        }
        
        $db->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        echo "   ✅ Table document_notes créée\n\n";
    } catch (\Exception $e) {
        $db->exec("SET FOREIGN_KEY_CHECKS = 1");
        if (strpos($e->getMessage(), 'already exists') === false) {
            throw $e;
        }
        echo "   ⚠️ Table document_notes existe déjà\n\n";
    }
    
    echo "✅ Migration Phase 2 terminée avec succès !\n";
    
} catch (\Exception $e) {
    echo "❌ Erreur lors de la migration : " . $e->getMessage() . "\n";
    echo "Fichier : " . $e->getFile() . " ligne " . $e->getLine() . "\n";
    exit(1);
}

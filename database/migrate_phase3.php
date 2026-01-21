<?php
/**
 * Migration Phase 3 - Matching Algorithms, Nested Tags, Workflows
 * Exécuter ce script pour créer les tables nécessaires
 */

require_once __DIR__ . '/../app/autoload.php';

use KDocs\Core\Database;

$db = Database::getInstance();

echo "🚀 Migration Phase 3 - K-Docs\n";
echo "==============================\n\n";

try {
    // 1. Matching Algorithms pour Tags
    echo "1. Ajout colonnes matching dans tags...\n";
    try {
        // Vérifier si la colonne match existe
        $checkStmt = $db->query("SHOW COLUMNS FROM tags LIKE 'match'");
        if ($checkStmt->rowCount() == 0) {
            $db->exec("ALTER TABLE tags ADD COLUMN `match` VARCHAR(500) NULL COMMENT 'Texte de correspondance pour matching automatique' AFTER color");
        }
        
        // Vérifier si la colonne matching_algorithm existe
        $checkStmt = $db->query("SHOW COLUMNS FROM tags LIKE 'matching_algorithm'");
        if ($checkStmt->rowCount() == 0) {
            $db->exec("ALTER TABLE tags ADD COLUMN matching_algorithm ENUM('none', 'any', 'all', 'exact', 'regex', 'fuzzy', 'auto') DEFAULT 'none' AFTER `match`");
        }
        
        echo "   ✅ Colonnes match et matching_algorithm ajoutées dans tags\n\n";
    } catch (\Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "   ⚠️ Colonnes existent déjà dans tags\n\n";
        } else {
            throw $e;
        }
    }
    
    // 2. Matching Algorithms pour Correspondants
    echo "2. Ajout colonne matching_algorithm dans correspondents...\n";
    try {
        // Vérifier si la table existe
        $tableCheck = $db->query("SHOW TABLES LIKE 'correspondents'");
        if ($tableCheck->rowCount() > 0) {
            // Vérifier si la colonne match existe
            $checkStmt = $db->query("SHOW COLUMNS FROM correspondents LIKE 'match'");
            if ($checkStmt->rowCount() == 0) {
                // Ajouter la colonne match d'abord
                $db->exec("ALTER TABLE correspondents ADD COLUMN `match` VARCHAR(500) NULL COMMENT 'Texte de correspondance pour matching automatique' AFTER name");
            }
            
            // Vérifier si la colonne matching_algorithm existe
            $checkStmt = $db->query("SHOW COLUMNS FROM correspondents LIKE 'matching_algorithm'");
            if ($checkStmt->rowCount() == 0) {
                $db->exec("ALTER TABLE correspondents ADD COLUMN matching_algorithm ENUM('none', 'any', 'all', 'exact', 'regex', 'fuzzy', 'auto') DEFAULT 'none' AFTER `match`");
            }
            echo "   ✅ Colonne matching_algorithm ajoutée dans correspondents\n\n";
        } else {
            echo "   ⚠️ Table correspondents n'existe pas encore\n\n";
        }
    } catch (\Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "   ⚠️ Colonne existe déjà dans correspondents\n\n";
        } else {
            echo "   ⚠️ Erreur (peut être ignorée): " . $e->getMessage() . "\n\n";
        }
    }
    
    // 3. Matching Algorithms pour Document Types
    echo "3. Ajout colonnes matching dans document_types...\n";
    try {
        $checkStmt = $db->query("SHOW COLUMNS FROM document_types LIKE 'match'");
        if ($checkStmt->rowCount() == 0) {
            $db->exec("ALTER TABLE document_types ADD COLUMN `match` VARCHAR(500) NULL COMMENT 'Texte de correspondance pour matching automatique' AFTER label");
        }
        
        $checkStmt = $db->query("SHOW COLUMNS FROM document_types LIKE 'matching_algorithm'");
        if ($checkStmt->rowCount() == 0) {
            $db->exec("ALTER TABLE document_types ADD COLUMN matching_algorithm ENUM('none', 'any', 'all', 'exact', 'regex', 'fuzzy', 'auto') DEFAULT 'none' AFTER `match`");
        }
        
        echo "   ✅ Colonnes match et matching_algorithm ajoutées dans document_types\n\n";
    } catch (\Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "   ⚠️ Colonnes existent déjà dans document_types\n\n";
        } else {
            echo "   ⚠️ Erreur (peut être ignorée): " . $e->getMessage() . "\n\n";
        }
    }
    
    // 4. Nested Tags
    echo "4. Ajout colonne parent_id dans tags...\n";
    try {
        $checkStmt = $db->query("SHOW COLUMNS FROM tags LIKE 'parent_id'");
        if ($checkStmt->rowCount() == 0) {
            $db->exec("ALTER TABLE tags ADD COLUMN parent_id INT NULL AFTER id");
            try {
                $db->exec("ALTER TABLE tags ADD FOREIGN KEY (parent_id) REFERENCES tags(id) ON DELETE SET NULL");
            } catch (\Exception $e) {
                // Clé étrangère peut échouer
            }
            try {
                $db->exec("ALTER TABLE tags ADD INDEX idx_parent (parent_id)");
            } catch (\Exception $e) {
                // Index peut exister déjà
            }
        }
        echo "   ✅ Colonne parent_id ajoutée dans tags\n\n";
    } catch (\Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false || strpos($e->getMessage(), 'Duplicate key') !== false) {
            echo "   ⚠️ Colonne parent_id existe déjà dans tags\n\n";
        } else {
            echo "   ⚠️ Erreur (peut être ignorée): " . $e->getMessage() . "\n\n";
        }
    }
    
    // 5. Workflows
    echo "5. Création table workflows...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS workflows (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            enabled BOOLEAN DEFAULT TRUE,
            order_index INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_enabled (enabled),
            INDEX idx_order (order_index)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "   ✅ Table workflows créée\n\n";
    
    echo "6. Création table workflow_triggers...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS workflow_triggers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            workflow_id INT NOT NULL,
            trigger_type ENUM('document_added', 'document_modified', 'tag_added', 'correspondent_added', 'type_added') NOT NULL,
            condition_type ENUM('always', 'if_match', 'if_has_tag', 'if_has_correspondent', 'if_has_type') DEFAULT 'always',
            condition_value TEXT NULL COMMENT 'Valeur de la condition (tag_id, correspondent_id, etc.)',
            FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE,
            INDEX idx_workflow (workflow_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "   ✅ Table workflow_triggers créée\n\n";
    
    echo "7. Création table workflow_actions...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS workflow_actions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            workflow_id INT NOT NULL,
            action_type ENUM('assign_tag', 'assign_correspondent', 'assign_type', 'assign_storage_path', 'set_field') NOT NULL,
            action_value TEXT NOT NULL COMMENT 'Valeur de l\'action (tag_id, correspondent_id, etc.)',
            order_index INT DEFAULT 0,
            FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE,
            INDEX idx_workflow (workflow_id),
            INDEX idx_order (order_index)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "   ✅ Table workflow_actions créée\n\n";
    
    echo "8. Création table workflow_logs...\n";
    try {
        $db->exec("SET FOREIGN_KEY_CHECKS = 0");
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS workflow_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                workflow_id INT NOT NULL,
                document_id INT NOT NULL,
                status ENUM('success', 'error', 'skipped') NOT NULL,
                message TEXT NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_workflow (workflow_id),
                INDEX idx_document (document_id),
                INDEX idx_executed (executed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Ajouter les clés étrangères après création
        try {
            $db->exec("ALTER TABLE workflow_logs ADD FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE");
        } catch (\Exception $e) {
            // Clé étrangère existe peut-être déjà
        }
        
        try {
            $db->exec("ALTER TABLE workflow_logs ADD FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE");
        } catch (\Exception $e) {
            // Clé étrangère existe peut-être déjà
        }
        
        $db->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        echo "   ✅ Table workflow_logs créée\n\n";
    } catch (\Exception $e) {
        $db->exec("SET FOREIGN_KEY_CHECKS = 1");
        if (strpos($e->getMessage(), 'already exists') === false) {
            throw $e;
        }
        echo "   ⚠️ Table workflow_logs existe déjà\n\n";
    }
    
    echo "✅ Migration Phase 3 terminée avec succès !\n";
    
} catch (\Exception $e) {
    echo "❌ Erreur lors de la migration : " . $e->getMessage() . "\n";
    echo "Fichier : " . $e->getFile() . " ligne " . $e->getLine() . "\n";
    exit(1);
}

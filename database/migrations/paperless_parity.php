<?php
/**
 * Migration K-Docs vers parité Paperless-ngx
 * Compatible avec MySQL qui ne supporte pas IF NOT EXISTS dans ALTER TABLE
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Core/Database.php';

use KDocs\Core\Database;

echo "=== Migration Paperless-ngx Parity ===\n\n";

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
    
    // 1. Matching algorithms pour tags
    echo "1. Ajout matching algorithms aux tags...\n";
    $tagColumns = [
        'matching_algorithm' => "TINYINT DEFAULT 0 COMMENT '0=none,1=any,2=all,3=exact,4=regex,5=fuzzy,6=auto'",
        'is_insensitive' => "BOOLEAN DEFAULT TRUE",
        'is_inbox_tag' => "BOOLEAN DEFAULT FALSE",
    ];
    
    foreach ($tagColumns as $column => $definition) {
        if (!$columnExists('tags', $column)) {
            try {
                $db->exec("ALTER TABLE tags ADD COLUMN `$column` $definition");
                echo "   ✅ Colonne tags.$column ajoutée\n";
            } catch (\Exception $e) {
                echo "   ⚠️  Erreur pour tags.$column: " . $e->getMessage() . "\n";
            }
        } else {
            echo "   ℹ️  Colonne tags.$column existe déjà\n";
        }
    }
    
    // 2. Matching algorithms pour correspondents
    echo "\n2. Ajout matching algorithms aux correspondents...\n";
    $correspondentColumns = [
        'match' => "VARCHAR(255) DEFAULT NULL",
        'matching_algorithm' => "TINYINT DEFAULT 0",
        'is_insensitive' => "BOOLEAN DEFAULT TRUE",
    ];
    
    foreach ($correspondentColumns as $column => $definition) {
        if (!$columnExists('correspondents', $column)) {
            try {
                $db->exec("ALTER TABLE correspondents ADD COLUMN `$column` $definition");
                echo "   ✅ Colonne correspondents.$column ajoutée\n";
            } catch (\Exception $e) {
                echo "   ⚠️  Erreur pour correspondents.$column: " . $e->getMessage() . "\n";
            }
        } else {
            echo "   ℹ️  Colonne correspondents.$column existe déjà\n";
        }
    }
    
    // 3. Matching algorithms pour document_types
    echo "\n3. Ajout matching algorithms aux document_types...\n";
    $typeColumns = [
        'match' => "VARCHAR(255) DEFAULT NULL",
        'matching_algorithm' => "TINYINT DEFAULT 0",
        'is_insensitive' => "BOOLEAN DEFAULT TRUE",
    ];
    
    foreach ($typeColumns as $column => $definition) {
        if (!$columnExists('document_types', $column)) {
            try {
                $db->exec("ALTER TABLE document_types ADD COLUMN `$column` $definition");
                echo "   ✅ Colonne document_types.$column ajoutée\n";
            } catch (\Exception $e) {
                echo "   ⚠️  Erreur pour document_types.$column: " . $e->getMessage() . "\n";
            }
        } else {
            echo "   ℹ️  Colonne document_types.$column existe déjà\n";
        }
    }
    
    // 4. Matching algorithms pour storage_paths
    echo "\n4. Ajout matching algorithms aux storage_paths...\n";
    $storageColumns = [
        'match' => "VARCHAR(255) DEFAULT NULL",
        'matching_algorithm' => "TINYINT DEFAULT 0",
        'is_insensitive' => "BOOLEAN DEFAULT TRUE",
    ];
    
    foreach ($storageColumns as $column => $definition) {
        if (!$columnExists('storage_paths', $column)) {
            try {
                $db->exec("ALTER TABLE storage_paths ADD COLUMN `$column` $definition");
                echo "   ✅ Colonne storage_paths.$column ajoutée\n";
            } catch (\Exception $e) {
                echo "   ⚠️  Erreur pour storage_paths.$column: " . $e->getMessage() . "\n";
            }
        } else {
            echo "   ℹ️  Colonne storage_paths.$column existe déjà\n";
        }
    }
    
    // 5. Permissions objet sur documents
    echo "\n5. Ajout permissions objet aux documents...\n";
    $docColumns = [
        'owner_id' => "INT DEFAULT NULL",
        'view_users' => "TEXT DEFAULT NULL COMMENT 'JSON array of user IDs'",
        'view_groups' => "TEXT DEFAULT NULL COMMENT 'JSON array of group IDs'",
        'change_users' => "TEXT DEFAULT NULL",
        'change_groups' => "TEXT DEFAULT NULL",
    ];
    
    foreach ($docColumns as $column => $definition) {
        if (!$columnExists('documents', $column)) {
            try {
                $db->exec("ALTER TABLE documents ADD COLUMN `$column` $definition");
                echo "   ✅ Colonne documents.$column ajoutée\n";
            } catch (\Exception $e) {
                echo "   ⚠️  Erreur pour documents.$column: " . $e->getMessage() . "\n";
            }
        } else {
            echo "   ℹ️  Colonne documents.$column existe déjà\n";
        }
    }
    
    // 6. Liens de partage public
    echo "\n6. Création table document_share_links...\n";
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_share_links (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_id INT NOT NULL,
                slug VARCHAR(64) NOT NULL UNIQUE,
                expires_at DATETIME DEFAULT NULL,
                download_count INT DEFAULT 0,
                max_downloads INT DEFAULT NULL,
                created_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id),
                INDEX idx_slug (slug),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "   ✅ Table document_share_links créée\n";
    } catch (\Exception $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            echo "   ℹ️  Table document_share_links existe déjà\n";
        } else {
            echo "   ⚠️  Erreur création table: " . $e->getMessage() . "\n";
        }
    }
    
    // 7. Saved views améliorées
    echo "\n7. Amélioration saved_searches...\n";
    $savedColumns = [
        'show_on_dashboard' => "BOOLEAN DEFAULT FALSE",
        'show_in_sidebar' => "BOOLEAN DEFAULT FALSE",
        'sort_field' => "VARCHAR(50) DEFAULT 'created_at'",
        'sort_reverse' => "BOOLEAN DEFAULT TRUE",
        'page_size' => "INT DEFAULT 25",
    ];
    
    foreach ($savedColumns as $column => $definition) {
        if (!$columnExists('saved_searches', $column)) {
            try {
                $db->exec("ALTER TABLE saved_searches ADD COLUMN `$column` $definition");
                echo "   ✅ Colonne saved_searches.$column ajoutée\n";
            } catch (\Exception $e) {
                echo "   ⚠️  Erreur pour saved_searches.$column: " . $e->getMessage() . "\n";
            }
        } else {
            echo "   ℹ️  Colonne saved_searches.$column existe déjà\n";
        }
    }
    
    // 8. Custom fields - types supplémentaires
    echo "\n8. Amélioration custom_fields...\n";
    try {
        // Vérifier le type actuel
        $stmt = $db->query("SHOW COLUMNS FROM custom_fields WHERE Field = 'field_type'");
        $col = $stmt->fetch();
        
        if ($col && strpos($col['Type'], 'ENUM') !== false && strpos($col['Type'], 'monetary') === false) {
            $db->exec("ALTER TABLE custom_fields MODIFY COLUMN field_type ENUM('text', 'number', 'date', 'boolean', 'select', 'url', 'monetary', 'documentlink') DEFAULT 'text'");
            echo "   ✅ field_type mis à jour\n";
        } else {
            echo "   ℹ️  field_type est déjà à jour\n";
        }
        
        $customColumns = [
            'select_options' => "TEXT DEFAULT NULL COMMENT 'JSON array for select type'",
            'currency' => "VARCHAR(3) DEFAULT 'CHF' COMMENT 'For monetary type'",
        ];
        
        foreach ($customColumns as $column => $definition) {
            if (!$columnExists('custom_fields', $column)) {
                try {
                    $db->exec("ALTER TABLE custom_fields ADD COLUMN `$column` $definition");
                    echo "   ✅ Colonne custom_fields.$column ajoutée\n";
                } catch (\Exception $e) {
                    echo "   ⚠️  Erreur pour custom_fields.$column: " . $e->getMessage() . "\n";
                }
            } else {
                echo "   ℹ️  Colonne custom_fields.$column existe déjà\n";
            }
        }
    } catch (\Exception $e) {
        echo "   ⚠️  Erreur amélioration custom_fields: " . $e->getMessage() . "\n";
    }
    
    // 9. Mail rules avancées
    echo "\n9. Amélioration mail_rules...\n";
    $mailColumns = [
        'filter_from' => "VARCHAR(255) DEFAULT NULL",
        'filter_subject' => "VARCHAR(255) DEFAULT NULL",
        'filter_body' => "VARCHAR(255) DEFAULT NULL",
        'filter_attachment_type' => "VARCHAR(100) DEFAULT NULL",
        'action_type' => "ENUM('mark_read', 'delete', 'move', 'flag', 'nothing') DEFAULT 'mark_read'",
        'action_parameter' => "VARCHAR(255) DEFAULT NULL",
        'maximum_age' => "INT DEFAULT NULL COMMENT 'Days'",
    ];
    
    foreach ($mailColumns as $column => $definition) {
        if (!$columnExists('mail_rules', $column)) {
            try {
                $db->exec("ALTER TABLE mail_rules ADD COLUMN `$column` $definition");
                echo "   ✅ Colonne mail_rules.$column ajoutée\n";
            } catch (\Exception $e) {
                echo "   ⚠️  Erreur pour mail_rules.$column: " . $e->getMessage() . "\n";
            }
        } else {
            echo "   ℹ️  Colonne mail_rules.$column existe déjà\n";
        }
    }
    
    // 10. Index pour performances
    echo "\n10. Création index pour performances...\n";
    $indexes = [
        'idx_documents_owner' => "CREATE INDEX IF NOT EXISTS idx_documents_owner ON documents(owner_id)",
        'idx_documents_created' => "CREATE INDEX IF NOT EXISTS idx_documents_created ON documents(created_at)",
        'idx_documents_correspondent' => "CREATE INDEX IF NOT EXISTS idx_documents_correspondent ON documents(correspondent_id)",
        'idx_documents_type' => "CREATE INDEX IF NOT EXISTS idx_documents_type ON documents(document_type_id)",
    ];
    
    foreach ($indexes as $name => $sql) {
        try {
            // MySQL n'a pas IF NOT EXISTS pour CREATE INDEX, donc on vérifie d'abord
            $stmt = $db->query("SHOW INDEX FROM documents WHERE Key_name = '$name'");
            if (!$stmt->fetch()) {
                $db->exec(str_replace('IF NOT EXISTS ', '', $sql));
                echo "   ✅ Index $name créé\n";
            } else {
                echo "   ℹ️  Index $name existe déjà\n";
            }
        } catch (\Exception $e) {
            echo "   ⚠️  Erreur index $name: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n✅ Migration terminée avec succès!\n";
    
} catch (\Exception $e) {
    echo "❌ Erreur de migration: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}

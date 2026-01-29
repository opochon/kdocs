<?php
/**
 * Migration: Classification Attribution System
 *
 * Creates tables for:
 * - Classification field options (dropdown values)
 * - Attribution rules (IF/THEN rules)
 * - ML learning data
 * - Invoice line items extraction
 * - Audit logging
 *
 * Note: Foreign keys to documents/users tables are replaced with indexes
 * because those tables use MyISAM engine which doesn't support FK
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Core/Database.php';

use KDocs\Core\Database;

try {
    $db = Database::getInstance();

    echo "=== Migration: Classification Attribution System ===\n\n";

    // Helper functions
    $tableExists = function($table) use ($db) {
        try {
            $stmt = $db->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            return $stmt->fetch() !== false;
        } catch (\Exception $e) {
            return false;
        }
    };

    $columnExists = function($table, $column) use ($db) {
        try {
            $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            return $stmt->fetch() !== false;
        } catch (\Exception $e) {
            return false;
        }
    };

    // ============================================================
    // 1. Classification Field Options (dropdown values)
    // ============================================================
    echo "1. Creating classification_field_options table...\n";

    if (!$tableExists('classification_field_options')) {
        $db->exec("
            CREATE TABLE classification_field_options (
                id INT AUTO_INCREMENT PRIMARY KEY,
                field_code VARCHAR(50) NOT NULL COMMENT 'Field identifier: compte_comptable, centre_cout, projet',
                option_value VARCHAR(100) NOT NULL COMMENT 'The actual value',
                option_label VARCHAR(255) NOT NULL COMMENT 'Display label',
                description TEXT NULL COMMENT 'Optional description',
                is_active BOOLEAN DEFAULT TRUE,
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_field_value (field_code, option_value),
                KEY idx_field_active (field_code, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "   ✅ Table created\n";

        // Insert default options
        $db->exec("
            INSERT INTO classification_field_options (field_code, option_value, option_label, sort_order) VALUES
            -- Comptes comptables
            ('compte_comptable', '1000', '1000 - Caisse', 10),
            ('compte_comptable', '1020', '1020 - Banque', 20),
            ('compte_comptable', '1100', '1100 - Clients', 30),
            ('compte_comptable', '2000', '2000 - Fournisseurs', 40),
            ('compte_comptable', '4000', '4000 - Achats marchandises', 50),
            ('compte_comptable', '4400', '4400 - Charges de personnel', 60),
            ('compte_comptable', '6000', '6000 - Charges diverses', 70),
            ('compte_comptable', '6100', '6100 - Loyers', 80),
            ('compte_comptable', '6200', '6200 - Assurances', 90),
            ('compte_comptable', '6300', '6300 - Frais de bureau', 100),
            -- Centres de coût
            ('centre_cout', 'ADMIN', 'Administration', 10),
            ('centre_cout', 'PROD', 'Production', 20),
            ('centre_cout', 'VENTE', 'Ventes', 30),
            ('centre_cout', 'RH', 'Ressources Humaines', 40),
            ('centre_cout', 'IT', 'Informatique', 50),
            ('centre_cout', 'MKTG', 'Marketing', 60),
            -- Projets
            ('projet', 'GENERAL', 'Général', 10),
            ('projet', 'PROJ-2024-001', 'Projet Alpha 2024', 20),
            ('projet', 'PROJ-2024-002', 'Projet Beta 2024', 30)
        ");
        echo "   ✅ Default options inserted\n";
    } else {
        echo "   ⏭️  Table already exists\n";
    }

    // ============================================================
    // 2. Attribution Rules
    // ============================================================
    echo "\n2. Creating attribution_rules table...\n";

    if (!$tableExists('attribution_rules')) {
        $db->exec("
            CREATE TABLE attribution_rules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description TEXT NULL,
                priority INT DEFAULT 100 COMMENT 'Higher = evaluated first',
                is_active BOOLEAN DEFAULT TRUE,
                stop_on_match BOOLEAN DEFAULT TRUE COMMENT 'Stop evaluating further rules if matched',
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_active_priority (is_active, priority DESC),
                KEY idx_created_by (created_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "   ✅ Table created\n";
    } else {
        echo "   ⏭️  Table already exists\n";
    }

    // ============================================================
    // 3. Attribution Rule Conditions
    // ============================================================
    echo "\n3. Creating attribution_rule_conditions table...\n";

    if (!$tableExists('attribution_rule_conditions')) {
        $db->exec("
            CREATE TABLE attribution_rule_conditions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                rule_id INT NOT NULL,
                condition_group INT DEFAULT 0 COMMENT 'Group for OR conditions between groups',
                field_type ENUM('correspondent', 'document_type', 'tag', 'amount', 'content', 'date', 'custom_field') NOT NULL,
                field_name VARCHAR(100) NULL COMMENT 'For custom fields',
                operator ENUM('equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with', 'greater_than', 'less_than', 'between', 'in', 'not_in', 'is_empty', 'is_not_empty', 'regex') NOT NULL,
                value TEXT NOT NULL COMMENT 'JSON encoded for complex values',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_rule (rule_id),
                CONSTRAINT fk_conditions_rule FOREIGN KEY (rule_id) REFERENCES attribution_rules(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "   ✅ Table created\n";
    } else {
        echo "   ⏭️  Table already exists\n";
    }

    // ============================================================
    // 4. Attribution Rule Actions
    // ============================================================
    echo "\n4. Creating attribution_rule_actions table...\n";

    if (!$tableExists('attribution_rule_actions')) {
        $db->exec("
            CREATE TABLE attribution_rule_actions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                rule_id INT NOT NULL,
                action_type ENUM('set_field', 'add_tag', 'remove_tag', 'move_to_folder', 'set_correspondent', 'set_document_type') NOT NULL,
                field_name VARCHAR(100) NULL COMMENT 'For set_field action',
                value TEXT NOT NULL COMMENT 'JSON encoded value',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_rule (rule_id),
                CONSTRAINT fk_actions_rule FOREIGN KEY (rule_id) REFERENCES attribution_rules(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "   ✅ Table created\n";
    } else {
        echo "   ⏭️  Table already exists\n";
    }

    // ============================================================
    // 5. Attribution Rule Execution Logs
    // ============================================================
    echo "\n5. Creating attribution_rule_logs table...\n";

    if (!$tableExists('attribution_rule_logs')) {
        $db->exec("
            CREATE TABLE attribution_rule_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                rule_id INT NOT NULL,
                document_id INT NOT NULL,
                matched BOOLEAN NOT NULL,
                conditions_evaluated JSON NULL COMMENT 'Which conditions matched/failed',
                actions_applied JSON NULL COMMENT 'What actions were taken',
                execution_time_ms INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_rule (rule_id),
                KEY idx_document (document_id),
                KEY idx_rule_date (rule_id, created_at),
                CONSTRAINT fk_logs_rule FOREIGN KEY (rule_id) REFERENCES attribution_rules(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "   ✅ Table created\n";
    } else {
        echo "   ⏭️  Table already exists\n";
    }

    // ============================================================
    // 6. Classification Training Data (ML)
    // ============================================================
    echo "\n6. Creating classification_training_data table...\n";

    if (!$tableExists('classification_training_data')) {
        $db->exec("
            CREATE TABLE classification_training_data (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_id INT NOT NULL,
                field_code VARCHAR(50) NOT NULL COMMENT 'Which field this training is for',
                field_value VARCHAR(255) NOT NULL COMMENT 'The classified value',
                features JSON NOT NULL COMMENT 'Extracted features for similarity matching',
                source ENUM('manual', 'rules', 'ai', 'import') DEFAULT 'manual',
                confidence DECIMAL(3,2) DEFAULT 1.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_field (field_code),
                KEY idx_document (document_id),
                KEY idx_field_value (field_code, field_value)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "   ✅ Table created\n";
    } else {
        echo "   ⏭️  Table already exists\n";
    }

    // ============================================================
    // 7. Classification Suggestions
    // ============================================================
    echo "\n7. Creating classification_suggestions table...\n";

    if (!$tableExists('classification_suggestions')) {
        $db->exec("
            CREATE TABLE classification_suggestions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_id INT NOT NULL,
                field_code VARCHAR(50) NOT NULL,
                suggested_value VARCHAR(255) NOT NULL,
                confidence DECIMAL(3,2) NOT NULL,
                source ENUM('ml', 'rules', 'ai') NOT NULL,
                similar_documents JSON NULL COMMENT 'IDs of similar documents used for suggestion',
                status ENUM('pending', 'applied', 'ignored') DEFAULT 'pending',
                applied_at TIMESTAMP NULL,
                applied_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_document (document_id),
                KEY idx_document_pending (document_id, status),
                KEY idx_status (status),
                KEY idx_applied_by (applied_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "   ✅ Table created\n";
    } else {
        echo "   ⏭️  Table already exists\n";
    }

    // ============================================================
    // 8. Invoice Line Items
    // ============================================================
    echo "\n8. Creating invoice_line_items table...\n";

    if (!$tableExists('invoice_line_items')) {
        $db->exec("
            CREATE TABLE invoice_line_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_id INT NOT NULL,
                line_number INT NOT NULL,
                quantity DECIMAL(10,3) NULL,
                unit VARCHAR(20) NULL,
                code VARCHAR(100) NULL COMMENT 'Article/Product code',
                description TEXT NOT NULL,
                unit_price DECIMAL(15,2) NULL,
                discount_percent DECIMAL(5,2) NULL,
                tax_rate DECIMAL(5,2) NULL,
                tax_amount DECIMAL(15,2) NULL,
                line_total DECIMAL(15,2) NULL,
                compte_comptable VARCHAR(50) NULL,
                centre_cout VARCHAR(50) NULL,
                projet VARCHAR(50) NULL,
                raw_text TEXT NULL COMMENT 'Original text from extraction',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_document (document_id),
                UNIQUE KEY uk_document_line (document_id, line_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "   ✅ Table created\n";
    } else {
        echo "   ⏭️  Table already exists\n";
    }

    // ============================================================
    // 9. Invoice Extraction Results (raw AI output)
    // ============================================================
    echo "\n9. Creating invoice_extraction_results table...\n";

    if (!$tableExists('invoice_extraction_results')) {
        $db->exec("
            CREATE TABLE invoice_extraction_results (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_id INT NOT NULL,
                extraction_type ENUM('line_items', 'header', 'full') DEFAULT 'line_items',
                raw_response JSON NOT NULL COMMENT 'Full AI response',
                parsed_data JSON NULL COMMENT 'Structured parsed data',
                model_used VARCHAR(100) NULL,
                tokens_used INT NULL,
                extraction_time_ms INT NULL,
                success BOOLEAN DEFAULT TRUE,
                error_message TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_document (document_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "   ✅ Table created\n";
    } else {
        echo "   ⏭️  Table already exists\n";
    }

    // ============================================================
    // 10. Classification Audit Log
    // ============================================================
    echo "\n10. Creating classification_audit_log table...\n";

    if (!$tableExists('classification_audit_log')) {
        $db->exec("
            CREATE TABLE classification_audit_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_id INT NOT NULL,
                field_code VARCHAR(50) NOT NULL,
                old_value VARCHAR(255) NULL,
                new_value VARCHAR(255) NULL,
                change_source ENUM('manual', 'rules', 'ml', 'ai', 'import', 'api') NOT NULL,
                change_reason TEXT NULL,
                rule_id INT NULL COMMENT 'If changed by rule',
                suggestion_id INT NULL COMMENT 'If applied from suggestion',
                user_id INT NULL,
                ip_address VARCHAR(45) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_document (document_id),
                KEY idx_field (field_code),
                KEY idx_date (created_at),
                KEY idx_source (change_source),
                KEY idx_user (user_id),
                KEY idx_rule (rule_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "   ✅ Table created\n";
    } else {
        echo "   ⏭️  Table already exists\n";
    }

    // ============================================================
    // 11. Add columns to documents table
    // ============================================================
    echo "\n11. Adding classification columns to documents table...\n";

    $columnsToAdd = [
        'compte_comptable' => "VARCHAR(50) NULL COMMENT 'Accounting account code'",
        'centre_cout' => "VARCHAR(50) NULL COMMENT 'Cost center'",
        'projet' => "VARCHAR(50) NULL COMMENT 'Project code'",
        'classification_confidence' => "DECIMAL(3,2) NULL COMMENT 'Overall classification confidence 0-1'",
        'needs_review' => "BOOLEAN DEFAULT FALSE COMMENT 'Flag for manual review needed'",
        'last_classified_at' => "TIMESTAMP NULL COMMENT 'Last classification timestamp'",
        'last_classified_by' => "ENUM('manual', 'rules', 'ml', 'ai') NULL COMMENT 'Source of last classification'"
    ];

    foreach ($columnsToAdd as $column => $definition) {
        if (!$columnExists('documents', $column)) {
            $db->exec("ALTER TABLE documents ADD COLUMN $column $definition");
            echo "   ✅ Added column: $column\n";
        } else {
            echo "   ⏭️  Column already exists: $column\n";
        }
    }

    // Add indexes
    echo "\n12. Adding indexes...\n";

    try {
        $db->exec("CREATE INDEX idx_documents_needs_review ON documents(needs_review)");
        echo "   ✅ Index idx_documents_needs_review created\n";
    } catch (\Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "   ⏭️  Index already exists\n";
        } else {
            echo "   ⚠️  " . $e->getMessage() . "\n";
        }
    }

    try {
        $db->exec("CREATE INDEX idx_documents_classification ON documents(last_classified_by, last_classified_at)");
        echo "   ✅ Index idx_documents_classification created\n";
    } catch (\Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "   ⏭️  Index already exists\n";
        } else {
            echo "   ⚠️  " . $e->getMessage() . "\n";
        }
    }

    echo "\n=== Migration completed successfully! ===\n";

} catch (\Exception $e) {
    echo "\n❌ Migration failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

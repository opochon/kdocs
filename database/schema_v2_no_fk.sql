-- ============================================================
-- K-DOCS - Schéma de base de données consolidé v2
-- Version: 2026-01-25
-- ============================================================
-- IMPORTANT: Désactiver FK checks pour éviter les erreurs d'ordre
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- Supprimer les tables existantes pour repartir propre
DROP TABLE IF EXISTS `api_usage`;
DROP TABLE IF EXISTS `audit_log`;
DROP TABLE IF EXISTS `indexing_queues`;
DROP TABLE IF EXISTS `sessions`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `task_history`;
DROP TABLE IF EXISTS `tasks`;
DROP TABLE IF EXISTS `workflow_instances`;
DROP TABLE IF EXISTS `workflow_transitions`;
DROP TABLE IF EXISTS `workflow_steps`;
DROP TABLE IF EXISTS `workflow_templates`;
DROP TABLE IF EXISTS `category_mappings`;
DROP TABLE IF EXISTS `classification_fields`;
DROP TABLE IF EXISTS `document_tags`;
DROP TABLE IF EXISTS `tags`;
DROP TABLE IF EXISTS `logical_folders`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `documents`;
DROP TABLE IF EXISTS `storage_paths`;
DROP TABLE IF EXISTS `correspondents`;
DROP TABLE IF EXISTS `document_types`;
DROP TABLE IF EXISTS `user_roles`;
DROP TABLE IF EXISTS `role_types`;
DROP TABLE IF EXISTS `user_groups`;
DROP TABLE IF EXISTS `groups`;
DROP TABLE IF EXISTS `users`;

-- ============================================================
-- TABLES DE BASE (sans dépendances)
-- ============================================================

CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) UNIQUE NOT NULL,
    `email` VARCHAR(100) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL DEFAULT '',
    `first_name` VARCHAR(50),
    `last_name` VARCHAR(50),
    `is_active` BOOLEAN DEFAULT TRUE,
    `is_admin` BOOLEAN DEFAULT FALSE,
    `last_login` DATETIME,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `groups` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL,
    `description` TEXT,
    `parent_id` INT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `role_types` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) UNIQUE NOT NULL,
    `label` VARCHAR(100) NOT NULL,
    `description` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `document_types` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) UNIQUE NOT NULL,
    `label` VARCHAR(100) NOT NULL,
    `description` TEXT,
    `retention_days` INT DEFAULT NULL,
    `matching_algorithm` VARCHAR(20) DEFAULT 'any',
    `matching_keywords` TEXT,
    `is_insensitive` BOOLEAN DEFAULT TRUE,
    `consume_subfolder` VARCHAR(100),
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `correspondents` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100),
    `address` TEXT,
    `is_supplier` BOOLEAN DEFAULT FALSE,
    `is_customer` BOOLEAN DEFAULT FALSE,
    `matching_algorithm` VARCHAR(20) DEFAULT 'any',
    `matching_keywords` TEXT,
    `is_insensitive` BOOLEAN DEFAULT TRUE,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `storage_paths` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `path` VARCHAR(500) NOT NULL,
    `match` VARCHAR(500) NULL,
    `matching_algorithm` ENUM('none', 'any', 'all', 'exact', 'regex', 'fuzzy', 'auto') DEFAULT 'none',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_path` (`path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tags` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `color` VARCHAR(7) DEFAULT '#6B7280',
    `matching_algorithm` VARCHAR(20) DEFAULT 'any',
    `matching_keywords` TEXT,
    `is_insensitive` BOOLEAN DEFAULT TRUE,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `classification_fields` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `field_code` VARCHAR(50) UNIQUE NOT NULL,
    `field_name` VARCHAR(100) NOT NULL,
    `field_type` VARCHAR(20) NOT NULL,
    `is_active` BOOLEAN DEFAULT TRUE,
    `is_required` BOOLEAN DEFAULT FALSE,
    `use_for_storage_path` BOOLEAN DEFAULT TRUE,
    `storage_path_position` INT DEFAULT NULL,
    `use_for_tag` BOOLEAN DEFAULT FALSE,
    `matching_keywords` TEXT,
    `matching_algorithm` VARCHAR(20) DEFAULT 'any',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `category_mappings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `category_name` VARCHAR(255) NOT NULL,
    `mapped_type` VARCHAR(50) NOT NULL,
    `mapped_id` INT NOT NULL,
    `mapped_name` VARCHAR(255) NOT NULL,
    `usage_count` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_mapping` (`category_name`, `mapped_type`, `mapped_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) NOT NULL UNIQUE,
    `value` TEXT,
    `type` VARCHAR(20) DEFAULT 'string',
    `description` TEXT,
    `category` VARCHAR(50) DEFAULT 'general',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `indexing_queues` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `folder_path` VARCHAR(500) NOT NULL,
    `folder_hash` VARCHAR(64) NOT NULL,
    `status` VARCHAR(20) DEFAULT 'pending',
    `priority` INT DEFAULT 0,
    `total_files` INT DEFAULT 0,
    `processed_files` INT DEFAULT 0,
    `error_count` INT DEFAULT 0,
    `last_error` TEXT,
    `started_at` DATETIME,
    `completed_at` DATETIME,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_folder` (`folder_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLES AVEC DEPENDANCES NIVEAU 1
-- ============================================================

CREATE TABLE `user_groups` (
    `user_id` INT NOT NULL,
    `group_id` INT NOT NULL,
    PRIMARY KEY (`user_id`, `group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `role_type_id` INT NOT NULL,
    `scope` VARCHAR(50) DEFAULT '*',
    `max_amount` DECIMAL(15,2) NULL,
    `valid_from` DATE NULL,
    `valid_to` DATE NULL,
    UNIQUE(`user_id`, `role_type_id`, `scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `logical_folders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `parent_id` INT NULL,
    `depth` INT DEFAULT 0,
    `icon` VARCHAR(50) DEFAULT 'folder',
    `color` VARCHAR(7) DEFAULT '#3b82f6',
    `filter_type` VARCHAR(20) DEFAULT 'filesystem',
    `filter_config` JSON,
    `sort_order` INT DEFAULT 0,
    `is_system` BOOLEAN DEFAULT FALSE,
    `created_by` INT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `documents` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255),
    `filename` VARCHAR(255) NOT NULL,
    `original_filename` VARCHAR(255),
    `file_path` VARCHAR(500) NOT NULL,
    `file_size` INT,
    `mime_type` VARCHAR(100),
    `document_type_id` INT NULL,
    `storage_path_id` INT NULL,
    `correspondent_id` INT NULL,
    `content` TEXT,
    `ocr_text` TEXT,
    `doc_date` DATE,
    `amount` DECIMAL(15,2),
    `currency` VARCHAR(3) DEFAULT 'CHF',
    `metadata` JSON,
    `checksum` VARCHAR(64),
    `ocr_status` VARCHAR(20) DEFAULT 'pending',
    `status` VARCHAR(20) DEFAULT 'pending',
    `classification_suggestions` JSON,
    `ai_additional_categories` JSON NULL,
    `ai_ignored_tags` JSON NULL,
    `consume_subfolder` VARCHAR(100),
    `thumbnail_path` VARCHAR(500),
    `deleted_at` DATETIME NULL,
    `deleted_by` INT NULL,
    `file_modified_at` INT NULL,
    `uploaded_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `document_tags` (
    `document_id` INT NOT NULL,
    `tag_id` INT NOT NULL,
    PRIMARY KEY (`document_id`, `tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `workflow_templates` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) UNIQUE NOT NULL,
    `label` VARCHAR(100) NOT NULL,
    `description` TEXT,
    `document_type_id` INT NULL,
    `is_active` BOOLEAN DEFAULT TRUE,
    `config` JSON,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `type` VARCHAR(50) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT,
    `link` VARCHAR(255),
    `is_read` BOOLEAN DEFAULT FALSE,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sessions` (
    `id` VARCHAR(128) PRIMARY KEY,
    `user_id` INT NOT NULL,
    `ip_address` VARCHAR(45),
    `user_agent` VARCHAR(255),
    `payload` TEXT,
    `last_activity` INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `action` VARCHAR(50) NOT NULL,
    `entity_type` VARCHAR(50),
    `entity_id` INT,
    `old_values` JSON,
    `new_values` JSON,
    `ip_address` VARCHAR(45),
    `user_agent` VARCHAR(255),
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `api_usage` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `provider` VARCHAR(50) NOT NULL DEFAULT 'anthropic',
    `model` VARCHAR(100),
    `endpoint` VARCHAR(100),
    `input_tokens` INT DEFAULT 0,
    `output_tokens` INT DEFAULT 0,
    `total_tokens` INT DEFAULT 0,
    `cost_estimate` DECIMAL(10,6) DEFAULT 0,
    `document_id` INT NULL,
    `operation` VARCHAR(50),
    `success` BOOLEAN DEFAULT TRUE,
    `error_message` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLES WORKFLOW NIVEAU 2
-- ============================================================

CREATE TABLE `workflow_steps` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `workflow_id` INT NOT NULL,
    `step_order` INT NOT NULL,
    `code` VARCHAR(50) NOT NULL,
    `label` VARCHAR(100),
    `role_type_id` INT NULL,
    `config` JSON,
    UNIQUE(`workflow_id`, `step_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `workflow_transitions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `from_step_id` INT NULL,
    `to_step_id` INT NULL,
    `action` VARCHAR(50) NOT NULL,
    `label` VARCHAR(100),
    `condition_config` JSON
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `workflow_instances` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `document_id` INT NOT NULL,
    `template_id` INT NOT NULL,
    `current_step_id` INT NULL,
    `status` VARCHAR(20) DEFAULT 'active',
    `started_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME,
    `started_by` INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tasks` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `workflow_instance_id` INT NULL,
    `step_id` INT NULL,
    `document_id` INT NULL,
    `assigned_to` INT NULL,
    `assigned_role_id` INT NULL,
    `status` VARCHAR(20) DEFAULT 'pending',
    `task_type` VARCHAR(50) DEFAULT 'workflow',
    `title` VARCHAR(255),
    `description` TEXT,
    `due_date` DATETIME,
    `reminder_sent_at` DATETIME,
    `escalated_at` DATETIME,
    `completed_at` DATETIME,
    `action_taken` VARCHAR(50),
    `comment` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `task_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `task_id` INT NOT NULL,
    `user_id` INT NULL,
    `action` VARCHAR(50) NOT NULL,
    `comment` TEXT,
    `metadata` JSON,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- INDEX (sans FOREIGN KEY pour éviter les erreurs)
-- ============================================================

CREATE INDEX `idx_documents_type` ON `documents`(`document_type_id`);
CREATE INDEX `idx_documents_correspondent` ON `documents`(`correspondent_id`);
CREATE INDEX `idx_documents_date` ON `documents`(`doc_date`);
CREATE INDEX `idx_documents_status` ON `documents`(`status`);
CREATE INDEX `idx_documents_deleted` ON `documents`(`deleted_at`);
CREATE INDEX `idx_storage_path` ON `documents`(`storage_path_id`);
CREATE INDEX `idx_tasks_status` ON `tasks`(`status`);
CREATE INDEX `idx_tasks_assigned` ON `tasks`(`assigned_to`, `status`);
CREATE INDEX `idx_notifications_user` ON `notifications`(`user_id`, `is_read`);
CREATE INDEX `idx_logical_folders_parent` ON `logical_folders`(`parent_id`);
CREATE INDEX `idx_audit_entity` ON `audit_log`(`entity_type`, `entity_id`);
CREATE INDEX `idx_category` ON `category_mappings`(`category_name`);
CREATE INDEX `idx_field_code` ON `classification_fields`(`field_code`);

-- ============================================================
-- DONNEES INITIALES
-- ============================================================

INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `first_name`, `last_name`, `is_admin`) 
VALUES (1, 'root', 'root@localhost', '', 'Admin', 'Root', TRUE);

INSERT INTO `role_types` (`code`, `label`, `description`) VALUES
('VALIDATEUR_FACTURE', 'Validateur factures', 'Peut valider les factures fournisseurs'),
('VALIDATEUR_CONTRAT', 'Validateur contrats', 'Peut valider les contrats'),
('SAISIE_COMPTA', 'Saisie comptable', 'Peut saisir en comptabilité'),
('APPROBATEUR_PAIEMENT', 'Approbateur paiement', 'Peut approuver les paiements');

INSERT INTO `document_types` (`code`, `label`) VALUES
('FACTURE', 'Facture'),
('CONTRAT', 'Contrat'),
('CORRESPONDANCE', 'Correspondance'),
('RH', 'Document RH'),
('AUTRE', 'Autre');

INSERT INTO `groups` (`id`, `name`, `description`) VALUES
(1, 'Direction', 'Direction générale'),
(2, 'Comptabilité', 'Service comptabilité'),
(3, 'Achats', 'Service achats'),
(4, 'RH', 'Ressources humaines');

INSERT INTO `classification_fields` (`field_code`, `field_name`, `field_type`, `is_active`, `use_for_storage_path`, `storage_path_position`) VALUES
('year', 'Année', 'year', TRUE, TRUE, 1),
('type', 'Type de document', 'type', TRUE, TRUE, 2),
('supplier', 'Fournisseur', 'supplier', TRUE, TRUE, 3),
('amount', 'Montant', 'amount', TRUE, FALSE, NULL),
('date', 'Date du document', 'date', TRUE, FALSE, NULL);

INSERT INTO `settings` (`key`, `value`, `type`, `description`, `category`) VALUES
('storage.base_path', '', 'string', 'Chemin racine des documents', 'storage'),
('storage.allowed_extensions', 'pdf,png,jpg,jpeg,tiff,doc,docx', 'string', 'Extensions autorisées', 'storage'),
('ocr.tesseract_path', '', 'string', 'Chemin Tesseract', 'ocr'),
('ai.claude_api_key', '', 'string', 'Clé API Claude', 'ai');

INSERT INTO `logical_folders` (`id`, `name`, `description`, `filter_type`, `filter_config`, `is_system`, `sort_order`) VALUES
(1, 'Tous les documents', 'Vue complète', 'filesystem', '{}', TRUE, 0),
(2, 'Factures', 'Toutes les factures', 'document_type', '{"document_type_code": "FACTURE"}', TRUE, 1),
(3, 'Contrats', 'Tous les contrats', 'document_type', '{"document_type_code": "CONTRAT"}', TRUE, 2),
(4, 'Correspondance', 'Correspondance', 'document_type', '{"document_type_code": "CORRESPONDANCE"}', TRUE, 3);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- FIN - Schema v2 sans FOREIGN KEY constraints
-- ============================================================

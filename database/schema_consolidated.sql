-- ============================================================
-- K-DOCS - Schéma de base de données consolidé
-- Version: 2026-01-25
-- ============================================================
-- Ce fichier contient TOUT le schéma nécessaire, fusionné depuis
-- schema.sql + toutes les migrations
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ============================================================
-- TABLES PRINCIPALES
-- ============================================================

-- Utilisateurs
CREATE TABLE IF NOT EXISTS `users` (
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

-- Groupes (backticks car mot réservé)
CREATE TABLE IF NOT EXISTS `groups` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL,
    `description` TEXT,
    `parent_id` INT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`parent_id`) REFERENCES `groups`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Association users-groups
CREATE TABLE IF NOT EXISTS `user_groups` (
    `user_id` INT NOT NULL,
    `group_id` INT NOT NULL,
    PRIMARY KEY (`user_id`, `group_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`group_id`) REFERENCES `groups`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Types de rôles métier
CREATE TABLE IF NOT EXISTS `role_types` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) UNIQUE NOT NULL,
    `label` VARCHAR(100) NOT NULL,
    `description` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attribution des rôles
CREATE TABLE IF NOT EXISTS `user_roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `role_type_id` INT NOT NULL,
    `scope` VARCHAR(50) DEFAULT '*',
    `max_amount` DECIMAL(15,2) NULL,
    `valid_from` DATE NULL,
    `valid_to` DATE NULL,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`role_type_id`) REFERENCES `role_types`(`id`) ON DELETE CASCADE,
    UNIQUE(`user_id`, `role_type_id`, `scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Types de documents
CREATE TABLE IF NOT EXISTS `document_types` (
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

-- Correspondants (expéditeurs/destinataires)
CREATE TABLE IF NOT EXISTS `correspondents` (
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

-- Storage Paths
CREATE TABLE IF NOT EXISTS `storage_paths` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `path` VARCHAR(500) NOT NULL,
    `match` VARCHAR(500) NULL,
    `matching_algorithm` ENUM('none', 'any', 'all', 'exact', 'regex', 'fuzzy', 'auto') DEFAULT 'none',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_path` (`path`),
    INDEX `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Documents (table principale)
CREATE TABLE IF NOT EXISTS `documents` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255),
    `filename` VARCHAR(255) NOT NULL,
    `original_filename` VARCHAR(255),
    `file_path` VARCHAR(500) NOT NULL,
    `file_size` INT,
    `mime_type` VARCHAR(100),
    `document_type_id` INT,
    `storage_path_id` INT NULL,
    `correspondent_id` INT,
    `content` TEXT,
    `ocr_text` TEXT,
    `doc_date` DATE,
    `amount` DECIMAL(15,2),
    `currency` VARCHAR(3) DEFAULT 'CHF',
    `metadata` JSON,
    `checksum` VARCHAR(64),
    `ocr_status` ENUM('pending', 'processing', 'done', 'error') DEFAULT 'pending',
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
    `created_by` INT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`document_type_id`) REFERENCES `document_types`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`storage_path_id`) REFERENCES `storage_paths`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`correspondent_id`) REFERENCES `correspondents`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_documents_type` (`document_type_id`),
    INDEX `idx_documents_correspondent` (`correspondent_id`),
    INDEX `idx_documents_date` (`doc_date`),
    INDEX `idx_documents_status` (`status`),
    INDEX `idx_documents_deleted` (`deleted_at`),
    INDEX `idx_storage_path` (`storage_path_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tags
CREATE TABLE IF NOT EXISTS `tags` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `color` VARCHAR(7) DEFAULT '#6B7280',
    `matching_algorithm` VARCHAR(20) DEFAULT 'any',
    `matching_keywords` TEXT,
    `is_insensitive` BOOLEAN DEFAULT TRUE,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Association documents-tags
CREATE TABLE IF NOT EXISTS `document_tags` (
    `document_id` INT NOT NULL,
    `tag_id` INT NOT NULL,
    PRIMARY KEY (`document_id`, `tag_id`),
    FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`tag_id`) REFERENCES `tags`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Champs de classification
CREATE TABLE IF NOT EXISTS `classification_fields` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `field_code` VARCHAR(50) UNIQUE NOT NULL,
    `field_name` VARCHAR(100) NOT NULL,
    `field_type` ENUM('year', 'supplier', 'type', 'amount', 'date', 'custom', 'correspondent') NOT NULL,
    `is_active` BOOLEAN DEFAULT TRUE,
    `is_required` BOOLEAN DEFAULT FALSE,
    `use_for_storage_path` BOOLEAN DEFAULT TRUE,
    `storage_path_position` INT DEFAULT NULL,
    `use_for_tag` BOOLEAN DEFAULT FALSE,
    `matching_keywords` TEXT,
    `matching_algorithm` VARCHAR(20) DEFAULT 'any',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_code` (`field_code`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mapping des catégories IA
CREATE TABLE IF NOT EXISTS `category_mappings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `category_name` VARCHAR(255) NOT NULL,
    `mapped_type` ENUM('tag', 'classification_field', 'correspondent', 'document_type') NOT NULL,
    `mapped_id` INT NOT NULL,
    `mapped_name` VARCHAR(255) NOT NULL,
    `usage_count` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_category` (`category_name`),
    INDEX `idx_mapped` (`mapped_type`, `mapped_id`),
    UNIQUE KEY `unique_mapping` (`category_name`, `mapped_type`, `mapped_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dossiers logiques
CREATE TABLE IF NOT EXISTS `logical_folders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `parent_id` INT NULL,
    `depth` INT DEFAULT 0,
    `icon` VARCHAR(50) DEFAULT 'folder',
    `color` VARCHAR(7) DEFAULT '#3b82f6',
    `filter_type` ENUM('filesystem', 'document_type', 'correspondent', 'tag', 'custom') DEFAULT 'filesystem',
    `filter_config` JSON,
    `sort_order` INT DEFAULT 0,
    `is_system` BOOLEAN DEFAULT FALSE,
    `created_by` INT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`parent_id`) REFERENCES `logical_folders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`),
    INDEX `idx_logical_folders_parent` (`parent_id`),
    INDEX `idx_logical_folders_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paramètres système
CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) NOT NULL UNIQUE,
    `value` TEXT,
    `type` VARCHAR(20) DEFAULT 'string',
    `description` TEXT,
    `category` VARCHAR(50) DEFAULT 'general',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` INT,
    FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_key` (`key`),
    INDEX `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLES WORKFLOW
-- ============================================================

-- Templates de workflow
CREATE TABLE IF NOT EXISTS `workflow_templates` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) UNIQUE NOT NULL,
    `label` VARCHAR(100) NOT NULL,
    `description` TEXT,
    `document_type_id` INT,
    `is_active` BOOLEAN DEFAULT TRUE,
    `config` JSON,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`document_type_id`) REFERENCES `document_types`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Étapes de workflow
CREATE TABLE IF NOT EXISTS `workflow_steps` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `workflow_id` INT NOT NULL,
    `step_order` INT NOT NULL,
    `code` VARCHAR(50) NOT NULL,
    `label` VARCHAR(100),
    `role_type_id` INT,
    `config` JSON,
    FOREIGN KEY (`workflow_id`) REFERENCES `workflow_templates`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`role_type_id`) REFERENCES `role_types`(`id`) ON DELETE SET NULL,
    UNIQUE(`workflow_id`, `step_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transitions entre étapes
CREATE TABLE IF NOT EXISTS `workflow_transitions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `from_step_id` INT,
    `to_step_id` INT,
    `action` VARCHAR(50) NOT NULL,
    `label` VARCHAR(100),
    `condition_config` JSON,
    FOREIGN KEY (`from_step_id`) REFERENCES `workflow_steps`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`to_step_id`) REFERENCES `workflow_steps`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Instances de workflow
CREATE TABLE IF NOT EXISTS `workflow_instances` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `document_id` INT NOT NULL,
    `template_id` INT NOT NULL,
    `current_step_id` INT,
    `status` ENUM('active', 'completed', 'cancelled', 'paused') DEFAULT 'active',
    `started_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME,
    `started_by` INT,
    FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`template_id`) REFERENCES `workflow_templates`(`id`),
    FOREIGN KEY (`current_step_id`) REFERENCES `workflow_steps`(`id`),
    FOREIGN KEY (`started_by`) REFERENCES `users`(`id`),
    INDEX `idx_workflow_instances_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tâches
CREATE TABLE IF NOT EXISTS `tasks` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `workflow_instance_id` INT,
    `step_id` INT,
    `document_id` INT,
    `assigned_to` INT,
    `assigned_role_id` INT,
    `status` ENUM('pending', 'in_progress', 'completed', 'escalated', 'cancelled') DEFAULT 'pending',
    `task_type` VARCHAR(50) DEFAULT 'workflow',
    `title` VARCHAR(255),
    `description` TEXT,
    `due_date` DATETIME,
    `reminder_sent_at` DATETIME,
    `escalated_at` DATETIME,
    `completed_at` DATETIME,
    `action_taken` VARCHAR(50),
    `comment` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`workflow_instance_id`) REFERENCES `workflow_instances`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`step_id`) REFERENCES `workflow_steps`(`id`),
    FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`),
    FOREIGN KEY (`assigned_role_id`) REFERENCES `role_types`(`id`),
    INDEX `idx_tasks_status` (`status`),
    INDEX `idx_tasks_assigned` (`assigned_to`, `status`),
    INDEX `idx_tasks_due` (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historique des tâches
CREATE TABLE IF NOT EXISTS `task_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `task_id` INT NOT NULL,
    `user_id` INT,
    `action` VARCHAR(50) NOT NULL,
    `comment` TEXT,
    `metadata` JSON,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLES SYSTEME
-- ============================================================

-- Notifications
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `type` VARCHAR(50) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT,
    `link` VARCHAR(255),
    `is_read` BOOLEAN DEFAULT FALSE,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_notifications_user` (`user_id`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessions
CREATE TABLE IF NOT EXISTS `sessions` (
    `id` VARCHAR(128) PRIMARY KEY,
    `user_id` INT NOT NULL,
    `ip_address` VARCHAR(45),
    `user_agent` VARCHAR(255),
    `payload` TEXT,
    `last_activity` INT,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit log
CREATE TABLE IF NOT EXISTS `audit_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT,
    `action` VARCHAR(50) NOT NULL,
    `entity_type` VARCHAR(50),
    `entity_id` INT,
    `old_values` JSON,
    `new_values` JSON,
    `ip_address` VARCHAR(45),
    `user_agent` VARCHAR(255),
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_audit_entity` (`entity_type`, `entity_id`),
    INDEX `idx_audit_user` (`user_id`),
    INDEX `idx_audit_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- File d'attente d'indexation
CREATE TABLE IF NOT EXISTS `indexing_queues` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `folder_path` VARCHAR(500) NOT NULL,
    `folder_hash` VARCHAR(64) NOT NULL,
    `status` ENUM('pending', 'processing', 'completed', 'error', 'cancelled') DEFAULT 'pending',
    `priority` INT DEFAULT 0,
    `total_files` INT DEFAULT 0,
    `processed_files` INT DEFAULT 0,
    `error_count` INT DEFAULT 0,
    `last_error` TEXT,
    `started_at` DATETIME,
    `completed_at` DATETIME,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_folder` (`folder_hash`),
    INDEX `idx_status` (`status`),
    INDEX `idx_priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Suivi utilisation API
CREATE TABLE IF NOT EXISTS `api_usage` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `provider` VARCHAR(50) NOT NULL DEFAULT 'anthropic',
    `model` VARCHAR(100),
    `endpoint` VARCHAR(100),
    `input_tokens` INT DEFAULT 0,
    `output_tokens` INT DEFAULT 0,
    `total_tokens` INT DEFAULT 0,
    `cost_estimate` DECIMAL(10,6) DEFAULT 0,
    `document_id` INT,
    `operation` VARCHAR(50),
    `success` BOOLEAN DEFAULT TRUE,
    `error_message` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE SET NULL,
    INDEX `idx_provider` (`provider`),
    INDEX `idx_date` (`created_at`),
    INDEX `idx_document` (`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DONNEES INITIALES
-- ============================================================

-- Utilisateur root
INSERT IGNORE INTO `users` (`id`, `username`, `email`, `password_hash`, `first_name`, `last_name`, `is_admin`) 
VALUES (1, 'root', 'root@localhost', '', 'Admin', 'Root', TRUE);

-- Types de rôles
INSERT IGNORE INTO `role_types` (`code`, `label`, `description`) VALUES
('VALIDATEUR_FACTURE', 'Validateur factures', 'Peut valider les factures fournisseurs'),
('VALIDATEUR_CONTRAT', 'Validateur contrats', 'Peut valider les contrats'),
('SAISIE_COMPTA', 'Saisie comptable', 'Peut saisir en comptabilité'),
('APPROBATEUR_PAIEMENT', 'Approbateur paiement', 'Peut approuver les paiements');

-- Types de documents
INSERT IGNORE INTO `document_types` (`code`, `label`) VALUES
('FACTURE', 'Facture'),
('CONTRAT', 'Contrat'),
('CORRESPONDANCE', 'Correspondance'),
('RH', 'Document RH'),
('AUTRE', 'Autre');

-- Groupes
INSERT IGNORE INTO `groups` (`id`, `name`, `description`) VALUES
(1, 'Direction', 'Direction générale'),
(2, 'Comptabilité', 'Service comptabilité'),
(3, 'Achats', 'Service achats'),
(4, 'RH', 'Ressources humaines');

-- Champs de classification standards
INSERT IGNORE INTO `classification_fields` (`field_code`, `field_name`, `field_type`, `is_active`, `use_for_storage_path`, `storage_path_position`, `use_for_tag`) VALUES
('year', 'Année', 'year', TRUE, TRUE, 1, FALSE),
('type', 'Type de document', 'type', TRUE, TRUE, 2, FALSE),
('supplier', 'Fournisseur', 'supplier', TRUE, TRUE, 3, FALSE),
('amount', 'Montant', 'amount', TRUE, FALSE, NULL, FALSE),
('date', 'Date du document', 'date', TRUE, FALSE, NULL, FALSE);

-- Paramètres par défaut
INSERT IGNORE INTO `settings` (`key`, `value`, `type`, `description`, `category`) VALUES
('storage.base_path', '', 'string', 'Chemin racine des documents', 'storage'),
('storage.allowed_extensions', 'pdf,png,jpg,jpeg,tiff,doc,docx', 'string', 'Extensions autorisées', 'storage'),
('ocr.tesseract_path', '', 'string', 'Chemin Tesseract', 'ocr'),
('ai.claude_api_key', '', 'string', 'Clé API Claude', 'ai');

-- Dossiers logiques par défaut
INSERT IGNORE INTO `logical_folders` (`id`, `name`, `description`, `filter_type`, `filter_config`, `is_system`, `sort_order`) VALUES
(1, '📁 Tous les documents', 'Vue complète', 'filesystem', '{}', TRUE, 0),
(2, '📄 Factures', 'Toutes les factures', 'document_type', '{"document_type_code": "FACTURE"}', TRUE, 1),
(3, '📋 Contrats', 'Tous les contrats', 'document_type', '{"document_type_code": "CONTRAT"}', TRUE, 2),
(4, '📧 Correspondance', 'Correspondance', 'document_type', '{"document_type_code": "CORRESPONDANCE"}', TRUE, 3);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- FIN DU SCHEMA CONSOLIDE
-- ============================================================

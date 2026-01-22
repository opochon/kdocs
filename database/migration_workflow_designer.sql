-- Migration Workflow Designer - Remplacement du système workflow Paperless-ngx
-- Date: 2025-01-23
-- Description: Remplace les tables workflow linéaires par un système de graphe avec nodes et connections

-- Supprimer les anciennes tables workflow (dans l'ordre pour respecter les contraintes FK)
-- Désactiver temporairement les vérifications de clés étrangères
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `workflow_runs`;
DROP TABLE IF EXISTS `workflow_action_tags`;
DROP TABLE IF EXISTS `workflow_actions`;
DROP TABLE IF EXISTS `workflow_trigger_tags`;
DROP TABLE IF EXISTS `workflow_triggers`;
DROP TABLE IF EXISTS `workflows`;

-- Réactiver les vérifications de clés étrangères
SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- WORKFLOW DEFINITIONS (le canvas)
-- =============================================================================

CREATE TABLE `workflow_definitions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(256) NOT NULL,
    `description` TEXT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `canvas_data` JSON NULL COMMENT 'Positions des nodes pour le designer',
    `created_by` INT(11) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_workflow_name` (`name`),
    KEY `idx_enabled` (`enabled`),
    CONSTRAINT `fk_workflow_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- WORKFLOW NODES (les blocs du designer)
-- =============================================================================

CREATE TABLE `workflow_nodes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `workflow_id` INT UNSIGNED NOT NULL,
    `node_type` ENUM(
        -- Triggers (entrées)
        'trigger_scan',
        'trigger_upload',
        'trigger_email',
        'trigger_schedule',
        'trigger_manual',
        
        -- Processing (traitement)
        'process_ocr',
        'process_ai_extract',
        'process_classify',
        'process_split',
        'process_merge',
        
        -- Conditions (branchement)
        'condition_field',
        'condition_category',
        'condition_amount',
        'condition_tag',
        'condition_correspondent',
        'condition_custom',
        
        -- Actions
        'action_assign_user',
        'action_assign_group',
        'action_add_tag',
        'action_remove_tag',
        'action_set_category',
        'action_set_field',
        'action_move',
        'action_copy',
        'action_archive',
        
        -- Notifications
        'notify_email',
        'notify_webhook',
        'notify_internal',
        
        -- Attentes
        'wait_approval',
        'wait_timer',
        'wait_condition',
        
        -- Fin
        'end_success',
        'end_error',
        'end_archive'
    ) NOT NULL,
    
    `name` VARCHAR(128) NOT NULL COMMENT 'Label affiché',
    `config` JSON NOT NULL COMMENT 'Configuration du node',
    `position_x` INT NOT NULL DEFAULT 0,
    `position_y` INT NOT NULL DEFAULT 0,
    `is_entry_point` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    KEY `idx_workflow` (`workflow_id`),
    KEY `idx_type` (`node_type`),
    CONSTRAINT `fk_node_workflow` FOREIGN KEY (`workflow_id`) REFERENCES `workflow_definitions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- WORKFLOW CONNECTIONS (les flèches entre nodes)
-- =============================================================================

CREATE TABLE `workflow_connections` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `workflow_id` INT UNSIGNED NOT NULL,
    `from_node_id` INT UNSIGNED NOT NULL,
    `to_node_id` INT UNSIGNED NOT NULL,
    `output_name` VARCHAR(64) NOT NULL DEFAULT 'default' COMMENT 'Pour conditions: true/false/timeout',
    `label` VARCHAR(128) NULL,
    `order` INT UNSIGNED NOT NULL DEFAULT 0,
    
    PRIMARY KEY (`id`),
    KEY `idx_workflow` (`workflow_id`),
    UNIQUE KEY `uk_connection` (`from_node_id`, `to_node_id`, `output_name`),
    CONSTRAINT `fk_conn_workflow` FOREIGN KEY (`workflow_id`) REFERENCES `workflow_definitions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_conn_from` FOREIGN KEY (`from_node_id`) REFERENCES `workflow_nodes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_conn_to` FOREIGN KEY (`to_node_id`) REFERENCES `workflow_nodes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- WORKFLOW EXECUTIONS (instances en cours)
-- =============================================================================

CREATE TABLE `workflow_executions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `workflow_id` INT UNSIGNED NOT NULL,
    `document_id` INT NULL,
    `status` ENUM('pending', 'running', 'waiting', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    `current_node_id` INT UNSIGNED NULL,
    `context` JSON NOT NULL COMMENT 'Données accumulées pendant execution',
    `error_message` TEXT NULL,
    `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME NULL,
    `waiting_until` DATETIME NULL,
    `waiting_for` VARCHAR(64) NULL,
    
    PRIMARY KEY (`id`),
    KEY `idx_workflow` (`workflow_id`),
    KEY `idx_document` (`document_id`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_exec_workflow` FOREIGN KEY (`workflow_id`) REFERENCES `workflow_definitions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_exec_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_exec_current_node` FOREIGN KEY (`current_node_id`) REFERENCES `workflow_nodes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- WORKFLOW EXECUTION LOG (historique détaillé)
-- =============================================================================

CREATE TABLE `workflow_execution_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `execution_id` INT UNSIGNED NOT NULL,
    `node_id` INT UNSIGNED NOT NULL,
    `status` ENUM('started', 'completed', 'failed', 'skipped') NOT NULL,
    `input_data` JSON NULL,
    `output_data` JSON NULL,
    `error_message` TEXT NULL,
    `duration_ms` INT UNSIGNED NULL,
    `executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    KEY `idx_execution` (`execution_id`),
    CONSTRAINT `fk_log_execution` FOREIGN KEY (`execution_id`) REFERENCES `workflow_executions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_log_node` FOREIGN KEY (`node_id`) REFERENCES `workflow_nodes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- APPROVAL TASKS (pour wait_approval)
-- =============================================================================

CREATE TABLE `workflow_approval_tasks` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `execution_id` INT UNSIGNED NOT NULL,
    `node_id` INT UNSIGNED NOT NULL,
    `document_id` INT NOT NULL,
    `assigned_user_id` INT(11) NULL,
    `assigned_group_id` INT UNSIGNED NULL,
    `status` ENUM('pending', 'approved', 'rejected', 'expired') NOT NULL DEFAULT 'pending',
    `comment` TEXT NULL,
    `decided_by` INT(11) NULL,
    `decided_at` DATETIME NULL,
    `expires_at` DATETIME NULL,
    `escalate_to_user_id` INT(11) NULL,
    `escalate_after_hours` INT UNSIGNED NULL,
    `reminder_sent` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    KEY `idx_execution` (`execution_id`),
    KEY `idx_assigned_user` (`assigned_user_id`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_approval_execution` FOREIGN KEY (`execution_id`) REFERENCES `workflow_executions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_approval_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_approval_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- NODE TEMPLATES (bibliothèque de nodes)
-- =============================================================================

CREATE TABLE `workflow_node_templates` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(128) NOT NULL,
    `description` TEXT NULL,
    `node_type` VARCHAR(32) NOT NULL,
    `default_config` JSON NOT NULL,
    `icon` VARCHAR(64) NULL,
    `color` VARCHAR(7) NULL,
    `category` VARCHAR(64) NOT NULL DEFAULT 'general',
    `is_system` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    KEY `idx_type` (`node_type`),
    KEY `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- WORKFLOW GROUPS (pour permissions)
-- =============================================================================

CREATE TABLE `workflow_groups` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(128) NOT NULL,
    `description` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `workflow_group_members` (
    `group_id` INT UNSIGNED NOT NULL,
    `user_id` INT(11) NOT NULL,
    `is_manager` TINYINT(1) NOT NULL DEFAULT 0,
    `added_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`group_id`, `user_id`),
    KEY `idx_group` (`group_id`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ajouter les contraintes FK séparément (certaines versions MySQL/MariaDB ont des problèmes avec les FK dans CREATE TABLE)
ALTER TABLE `workflow_group_members`
    ADD CONSTRAINT `fk_gm_group` FOREIGN KEY (`group_id`) REFERENCES `workflow_groups` (`id`) ON DELETE CASCADE,
    ADD CONSTRAINT `fk_gm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

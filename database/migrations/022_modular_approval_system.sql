-- Migration: 022_modular_approval_system.sql
-- Système d'approbation modulaire - Variables inter-nœuds
-- Date: 2026-01-29

-- =============================================================================
-- TABLE: workflow_node_outputs
-- Stocke les variables produites par chaque nœud pendant l'exécution
-- =============================================================================

CREATE TABLE IF NOT EXISTS `workflow_node_outputs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `execution_id` INT UNSIGNED NOT NULL,
    `node_id` INT UNSIGNED NOT NULL,
    `output_key` VARCHAR(64) NOT NULL COMMENT 'Nom de la variable (ex: approval_link)',
    `output_value` TEXT NULL COMMENT 'Valeur de la variable',
    `output_type` VARCHAR(32) NOT NULL DEFAULT 'string' COMMENT 'Type: string, integer, boolean, json, url',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_execution_node_key` (`execution_id`, `node_id`, `output_key`),
    KEY `idx_execution` (`execution_id`),
    KEY `idx_node` (`node_id`),

    CONSTRAINT `fk_node_outputs_execution`
        FOREIGN KEY (`execution_id`) REFERENCES `workflow_executions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_node_outputs_node`
        FOREIGN KEY (`node_id`) REFERENCES `workflow_nodes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- TABLE: workflow_node_output_schema
-- Définit les outputs déclarés par chaque type de nœud (métadonnées)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `workflow_node_output_schema` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `node_type` VARCHAR(64) NOT NULL COMMENT 'Type de nœud (ex: action_create_approval)',
    `output_key` VARCHAR(64) NOT NULL COMMENT 'Nom de la variable',
    `output_type` VARCHAR(32) NOT NULL DEFAULT 'string',
    `description` VARCHAR(256) NULL,
    `example` VARCHAR(256) NULL COMMENT 'Exemple de valeur',

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_node_type_key` (`node_type`, `output_key`),
    KEY `idx_node_type` (`node_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- Données: Schéma des outputs pour le nouveau nœud CreateApproval
-- =============================================================================

INSERT INTO `workflow_node_output_schema` (`node_type`, `output_key`, `output_type`, `description`, `example`) VALUES
('action_create_approval', 'approval_token', 'string', 'Token unique d\'approbation (64 caractères)', 'a1b2c3d4e5f6...'),
('action_create_approval', 'approval_link', 'url', 'Lien complet pour approuver', 'http://localhost/kdocs/workflow/approve/abc123?action=approve'),
('action_create_approval', 'reject_link', 'url', 'Lien complet pour refuser', 'http://localhost/kdocs/workflow/approve/abc123?action=reject'),
('action_create_approval', 'view_link', 'url', 'Lien pour voir le document', 'http://localhost/kdocs/documents/42'),
('action_create_approval', 'expires_at', 'string', 'Date d\'expiration ISO', '2026-01-30 15:30:00'),
('action_create_approval', 'token_id', 'integer', 'ID du token en base', '123');

-- Outputs pour SendEmail (pour documentation)
INSERT INTO `workflow_node_output_schema` (`node_type`, `output_key`, `output_type`, `description`, `example`) VALUES
('action_send_email', 'sent', 'boolean', 'Email envoyé avec succès', 'true'),
('action_send_email', 'recipients_count', 'integer', 'Nombre de destinataires', '3');

-- Outputs pour wait_approval
INSERT INTO `workflow_node_output_schema` (`node_type`, `output_key`, `output_type`, `description`, `example`) VALUES
('wait_approval', 'decision', 'string', 'Décision: approved ou rejected', 'approved'),
('wait_approval', 'decided_by', 'integer', 'ID de l\'utilisateur ayant décidé', '5'),
('wait_approval', 'decided_at', 'string', 'Date de la décision', '2026-01-29 14:00:00'),
('wait_approval', 'comment', 'string', 'Commentaire de l\'approbateur', 'OK, validé');

-- =============================================================================
-- Ajouter colonne pour stocker le node_id source du token dans approval_tokens
-- =============================================================================

ALTER TABLE `workflow_approval_tokens`
    ADD COLUMN IF NOT EXISTS `source_node_id` INT UNSIGNED NULL AFTER `node_id`,
    ADD CONSTRAINT `fk_approval_token_source_node`
        FOREIGN KEY (`source_node_id`) REFERENCES `workflow_nodes` (`id`) ON DELETE SET NULL;

-- =============================================================================
-- Vue: Variables disponibles pour un workflow en cours
-- =============================================================================

CREATE OR REPLACE VIEW `v_workflow_available_variables` AS
SELECT
    wno.execution_id,
    wn.workflow_id,
    wn.id as node_id,
    wn.name as node_name,
    wn.node_type,
    wno.output_key,
    wno.output_value,
    wno.output_type,
    CONCAT('{', wn.id, '.', wno.output_key, '}') as variable_syntax,
    CONCAT('{', wn.name, '.', wno.output_key, '}') as variable_syntax_by_name
FROM workflow_node_outputs wno
INNER JOIN workflow_nodes wn ON wno.node_id = wn.id;

-- Migration 011: Table workflow_timers
-- Pour gérer les timers/délais dans les workflows

CREATE TABLE IF NOT EXISTS `workflow_timers` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `execution_id` INT UNSIGNED NOT NULL COMMENT 'ID de l\'exécution du workflow',
    `node_id` INT UNSIGNED NOT NULL COMMENT 'ID du node timer dans le workflow',
    `timer_type` ENUM('delay', 'date') NOT NULL COMMENT 'Type de timer: délai ou date spécifique',
    `fire_at` DATETIME NOT NULL COMMENT 'Date/heure à laquelle le timer doit se déclencher',
    `status` ENUM('waiting', 'fired', 'cancelled') NOT NULL DEFAULT 'waiting' COMMENT 'Statut du timer',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `fired_at` DATETIME NULL COMMENT 'Date/heure à laquelle le timer a été déclenché',
    
    PRIMARY KEY (`id`),
    KEY `idx_execution` (`execution_id`),
    KEY `idx_node` (`node_id`),
    KEY `idx_fire_at` (`fire_at`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_timer_execution` FOREIGN KEY (`execution_id`) REFERENCES `workflow_executions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Timers pour les workflows (délais et dates)';

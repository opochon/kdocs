-- Migration Scheduled Tasks & Background Processing pour K-Docs
-- Date: 2026-01-21

-- Table des tâches planifiées
CREATE TABLE IF NOT EXISTS scheduled_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE COMMENT 'Nom de la tâche',
    task_type VARCHAR(100) NOT NULL COMMENT 'Type de tâche (index_filesystem, cleanup_trash, etc.)',
    schedule_cron VARCHAR(100) NOT NULL COMMENT 'Expression cron (ex: 0 2 * * *)',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Tâche active',
    last_run_at DATETIME NULL COMMENT 'Dernière exécution',
    next_run_at DATETIME NULL COMMENT 'Prochaine exécution',
    last_status ENUM('success', 'error', 'running') NULL COMMENT 'Dernier statut',
    last_error TEXT NULL COMMENT 'Dernière erreur',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_next_run (next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table de la file d'attente des tâches
CREATE TABLE IF NOT EXISTS task_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_type VARCHAR(100) NOT NULL COMMENT 'Type de tâche',
    task_data JSON NULL COMMENT 'Données de la tâche',
    priority INT DEFAULT 5 COMMENT 'Priorité (1-10, 10 = plus haute)',
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending' COMMENT 'Statut',
    attempts INT DEFAULT 0 COMMENT 'Nombre de tentatives',
    max_attempts INT DEFAULT 3 COMMENT 'Nombre maximum de tentatives',
    error_message TEXT NULL COMMENT 'Message d''erreur',
    started_at DATETIME NULL COMMENT 'Début du traitement',
    completed_at DATETIME NULL COMMENT 'Fin du traitement',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_priority (priority),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table des logs d'exécution des tâches
CREATE TABLE IF NOT EXISTS task_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NULL COMMENT 'ID de la tâche planifiée',
    queue_id INT NULL COMMENT 'ID de la tâche en queue',
    task_type VARCHAR(100) NOT NULL COMMENT 'Type de tâche',
    status ENUM('success', 'error', 'warning') NOT NULL COMMENT 'Statut',
    message TEXT NULL COMMENT 'Message',
    execution_time_ms INT NULL COMMENT 'Temps d''exécution en ms',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES scheduled_tasks(id) ON DELETE SET NULL,
    FOREIGN KEY (queue_id) REFERENCES task_queue(id) ON DELETE SET NULL,
    INDEX idx_task_type (task_type),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insérer les tâches par défaut (si elles n'existent pas)
INSERT IGNORE INTO scheduled_tasks (name, task_type, schedule_cron, is_active) VALUES
('Indexation filesystem', 'index_filesystem', '0 */6 * * *', TRUE),
('Nettoyage corbeille', 'cleanup_trash', '0 3 * * 0', TRUE),
('Vérification emails', 'check_mail', '*/15 * * * *', FALSE),
('Génération thumbnails manquants', 'generate_thumbnails', '0 4 * * *', TRUE);

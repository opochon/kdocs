-- Migration Audit Log pour K-Docs
-- Date: 2026-01-21

-- Table des logs d'audit
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL COMMENT 'Utilisateur ayant effectué l\'action',
    action VARCHAR(100) NOT NULL COMMENT 'Type d\'action (document.created, document.updated, document.deleted, etc.)',
    object_type VARCHAR(50) NOT NULL COMMENT 'Type d\'objet (document, tag, correspondent, etc.)',
    object_id INT NULL COMMENT 'ID de l\'objet concerné',
    object_name VARCHAR(255) NULL COMMENT 'Nom/titre de l\'objet pour référence',
    changes JSON NULL COMMENT 'Changements effectués (avant/après)',
    ip_address VARCHAR(45) NULL COMMENT 'Adresse IP de l\'utilisateur',
    user_agent TEXT NULL COMMENT 'User agent du navigateur',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_object_type (object_type),
    INDEX idx_object_id (object_id),
    INDEX idx_created_at (created_at),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration Multi-utilisateurs avancé pour K-Docs
-- Date: 2026-01-21

-- Ajouter les colonnes pour les rôles et permissions à la table users
-- Note: is_active et created_at/updated_at existent déjà
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS role VARCHAR(50) DEFAULT 'user' COMMENT 'Rôle de l\'utilisateur (admin, user, viewer)',
ADD COLUMN IF NOT EXISTS permissions JSON NULL COMMENT 'Permissions granulaires de l\'utilisateur',
ADD COLUMN IF NOT EXISTS last_login_at TIMESTAMP NULL COMMENT 'Dernière connexion';

-- Table des groupes d'utilisateurs
CREATE TABLE IF NOT EXISTS user_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL COMMENT 'Nom du groupe',
    description TEXT NULL COMMENT 'Description du groupe',
    permissions JSON NULL COMMENT 'Permissions du groupe',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table de liaison users <-> groups
CREATE TABLE IF NOT EXISTS user_group_memberships (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    group_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_group (user_id, group_id),
    INDEX idx_user_id (user_id),
    INDEX idx_group_id (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table des permissions sur les documents (partage)
CREATE TABLE IF NOT EXISTS document_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    user_id INT NULL COMMENT 'Utilisateur spécifique (NULL si groupe)',
    group_id INT NULL COMMENT 'Groupe spécifique (NULL si utilisateur)',
    permission VARCHAR(50) NOT NULL COMMENT 'Type de permission (read, write, delete)',
    granted_by INT NULL COMMENT 'Utilisateur ayant accordé la permission',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_document_id (document_id),
    INDEX idx_user_id (user_id),
    INDEX idx_group_id (group_id),
    INDEX idx_permission (permission)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

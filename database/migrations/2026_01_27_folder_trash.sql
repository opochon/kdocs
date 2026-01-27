-- Migration: Création de la table folder_trash
-- Date: 2026-01-27

CREATE TABLE IF NOT EXISTS folder_trash (
    id INT AUTO_INCREMENT PRIMARY KEY,
    original_path VARCHAR(500) NOT NULL COMMENT 'Chemin relatif original du dossier',
    trash_path VARCHAR(500) NOT NULL COMMENT 'Chemin dans le trash',
    deleted_by INT NULL COMMENT 'ID utilisateur qui a supprimé',
    deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    restored_at DATETIME NULL COMMENT 'Date de restauration (NULL si pas restauré)',
    restored_by INT NULL COMMENT 'ID utilisateur qui a restauré',
    file_count INT DEFAULT 0 COMMENT 'Nombre de fichiers dans le dossier',
    folder_count INT DEFAULT 0 COMMENT 'Nombre de sous-dossiers',
    
    INDEX idx_deleted_at (deleted_at),
    INDEX idx_restored_at (restored_at),
    INDEX idx_original_path (original_path(191)),
    
    FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (restored_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

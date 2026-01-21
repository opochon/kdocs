-- Migration pour architecture Filesystem-First
-- K-Docs - Ajout support filesystem

USE kdocs;

-- Table pour représenter l'arborescence du filesystem
CREATE TABLE IF NOT EXISTS document_folders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    path VARCHAR(500) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    parent_id INT NULL,
    depth INT DEFAULT 0,
    file_count INT DEFAULT 0,
    last_scanned DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES document_folders(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_folders_path ON document_folders(path);
CREATE INDEX IF NOT EXISTS idx_folders_parent ON document_folders(parent_id);

-- Modifications table documents
ALTER TABLE documents 
    ADD COLUMN IF NOT EXISTS folder_id INT NULL,
    ADD COLUMN IF NOT EXISTS relative_path VARCHAR(500),
    ADD COLUMN IF NOT EXISTS thumbnail_path VARCHAR(500),
    ADD COLUMN IF NOT EXISTS is_indexed BOOLEAN DEFAULT FALSE;

-- Ajouter la clé étrangère si elle n'existe pas déjà
SET @fk_exists = (
    SELECT COUNT(*) 
    FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = 'kdocs' 
    AND TABLE_NAME = 'documents' 
    AND CONSTRAINT_NAME = 'documents_ibfk_folder'
);

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE documents ADD CONSTRAINT documents_ibfk_folder FOREIGN KEY (folder_id) REFERENCES document_folders(id) ON DELETE SET NULL',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index pour améliorer les performances
CREATE INDEX IF NOT EXISTS idx_documents_folder ON documents(folder_id);
CREATE INDEX IF NOT EXISTS idx_documents_relative_path ON documents(relative_path);
CREATE INDEX IF NOT EXISTS idx_documents_indexed ON documents(is_indexed);

-- Migration pour Storage Paths (Phase 2.2)
-- Permet d'organiser les documents dans des chemins de stockage personnalisés

CREATE TABLE IF NOT EXISTS storage_paths (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    path VARCHAR(500) NOT NULL COMMENT 'Chemin relatif dans le filesystem',
    match VARCHAR(500) NULL COMMENT 'Texte de correspondance pour matching automatique',
    matching_algorithm ENUM('none', 'any', 'all', 'exact', 'regex', 'fuzzy', 'auto') DEFAULT 'none',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_path (path),
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ajouter la colonne storage_path_id dans documents
ALTER TABLE documents 
ADD COLUMN storage_path_id INT NULL AFTER document_type_id,
ADD FOREIGN KEY (storage_path_id) REFERENCES storage_paths(id) ON DELETE SET NULL,
ADD INDEX idx_storage_path (storage_path_id);

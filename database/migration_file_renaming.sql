-- Migration File Renaming & Organization pour K-Docs
-- Date: 2026-01-21

-- Table des règles de renommage
CREATE TABLE IF NOT EXISTS file_renaming_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'Nom de la règle',
    match_filter TEXT NULL COMMENT 'Filtre de correspondance (JSON)',
    filename_template VARCHAR(500) NOT NULL COMMENT 'Template de nommage (ex: {correspondent}_{date}_{title})',
    folder_template VARCHAR(500) NULL COMMENT 'Template de dossier (ex: {year}/{month})',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Règle active',
    order_index INT DEFAULT 0 COMMENT 'Ordre d''application',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_order (order_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Variables disponibles pour les templates :
-- {title} - Titre du document
-- {correspondent} - Nom du correspondant
-- {document_type} - Type de document
-- {date} - Date du document (YYYY-MM-DD)
-- {year} - Année
-- {month} - Mois (01-12)
-- {day} - Jour (01-31)
-- {asn} - Archive Serial Number
-- {id} - ID du document
-- {original_filename} - Nom de fichier original

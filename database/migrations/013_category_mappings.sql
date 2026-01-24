-- Migration 013: Système de mapping des catégories IA
-- Date: 2026-01-22
-- Description: Table pour mémoriser les mappings entre catégories IA et tags/champs de classification

CREATE TABLE IF NOT EXISTS category_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(255) NOT NULL COMMENT 'Nom de la catégorie extraite par IA (ex: "tribunal")',
    mapped_type ENUM('tag', 'classification_field', 'correspondent', 'document_type') NOT NULL COMMENT 'Type de mapping',
    mapped_id INT NOT NULL COMMENT 'ID de l''entité mappée (tag_id, classification_field_id, etc.)',
    mapped_name VARCHAR(255) NOT NULL COMMENT 'Nom final utilisé (peut être différent de category_name)',
    usage_count INT DEFAULT 0 COMMENT 'Nombre de fois que ce mapping a été utilisé',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category_name),
    INDEX idx_mapped (mapped_type, mapped_id),
    UNIQUE KEY unique_mapping (category_name, mapped_type, mapped_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table pour stocker les catégories proposées par IA pour chaque document
ALTER TABLE documents 
ADD COLUMN IF NOT EXISTS ai_additional_categories JSON NULL COMMENT 'Catégories supplémentaires extraites par IA';

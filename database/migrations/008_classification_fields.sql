-- Migration 008: Champs paramétrables pour classification et stockage
-- Étendre custom_fields pour supporter la classification

ALTER TABLE custom_fields
ADD COLUMN IF NOT EXISTS is_active BOOLEAN DEFAULT TRUE COMMENT 'Champ actif pour la classification',
ADD COLUMN IF NOT EXISTS use_for_storage_path BOOLEAN DEFAULT FALSE COMMENT 'Utiliser ce champ dans le chemin de stockage',
ADD COLUMN IF NOT EXISTS use_for_tag BOOLEAN DEFAULT FALSE COMMENT 'Créer un tag automatiquement si détecté',
ADD COLUMN IF NOT EXISTS storage_path_position INT DEFAULT NULL COMMENT 'Position dans le chemin (1=premier niveau, 2=deuxième, etc.)',
ADD COLUMN IF NOT EXISTS matching_keywords TEXT COMMENT 'Mots-clés pour matching automatique',
ADD COLUMN IF NOT EXISTS matching_algorithm VARCHAR(20) DEFAULT 'any' COMMENT 'Algorithme de matching',
ADD COLUMN IF NOT EXISTS field_code VARCHAR(50) COMMENT 'Code unique du champ (ex: year, supplier, type)';

-- Table pour configurer les champs de classification standards
CREATE TABLE IF NOT EXISTS classification_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    field_code VARCHAR(50) UNIQUE NOT NULL COMMENT 'Code unique: year, supplier, type, amount, etc.',
    field_name VARCHAR(100) NOT NULL COMMENT 'Nom affiché',
    field_type ENUM('year', 'supplier', 'type', 'amount', 'date', 'custom') NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    use_for_storage_path BOOLEAN DEFAULT TRUE,
    storage_path_position INT DEFAULT NULL COMMENT 'Position dans le chemin',
    use_for_tag BOOLEAN DEFAULT FALSE,
    matching_keywords TEXT,
    matching_algorithm VARCHAR(20) DEFAULT 'any',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (field_code),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insérer les champs standards
INSERT INTO classification_fields (field_code, field_name, field_type, is_active, use_for_storage_path, storage_path_position, use_for_tag) VALUES
('year', 'Année', 'year', TRUE, TRUE, 1, FALSE),
('supplier', 'Fournisseur', 'supplier', TRUE, TRUE, 2, FALSE),
('type', 'Type de document', 'type', TRUE, TRUE, 3, FALSE),
('amount', 'Montant', 'amount', TRUE, FALSE, NULL, FALSE),
('date', 'Date du document', 'date', TRUE, FALSE, NULL, FALSE)
ON DUPLICATE KEY UPDATE field_name = VALUES(field_name);

-- Ajouter colonne pour stocker la date d'upload séparément
ALTER TABLE documents
ADD COLUMN IF NOT EXISTS uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Date d\'upload du document';

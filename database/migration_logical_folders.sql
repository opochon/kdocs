-- Migration : Arborescences logiques (vues métier)
-- K-Docs - Support des arborescences logiques basées sur la DB

USE kdocs;

-- Table pour les arborescences logiques (vues métier)
CREATE TABLE IF NOT EXISTS logical_folders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    parent_id INT NULL,
    depth INT DEFAULT 0,
    icon VARCHAR(50) DEFAULT 'folder',
    color VARCHAR(7) DEFAULT '#3b82f6',
    filter_type ENUM('filesystem', 'document_type', 'correspondent', 'tag', 'custom') DEFAULT 'filesystem',
    filter_config JSON, -- Configuration du filtre (ex: {"document_type_id": 1, "correspondent_id": 2})
    sort_order INT DEFAULT 0,
    is_system BOOLEAN DEFAULT FALSE, -- Dossiers système (non supprimables)
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES logical_folders(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_logical_folders_parent ON logical_folders(parent_id);
CREATE INDEX IF NOT EXISTS idx_logical_folders_sort ON logical_folders(sort_order);

-- Insérer quelques arborescences logiques par défaut
INSERT INTO logical_folders (name, description, filter_type, filter_config, is_system, sort_order) VALUES
('📁 Tous les documents', 'Vue complète de tous les documents', 'filesystem', '{}', TRUE, 0),
('📄 Factures', 'Toutes les factures', 'document_type', '{"document_type_code": "facture"}', TRUE, 1),
('📋 Contrats', 'Tous les contrats', 'document_type', '{"document_type_code": "contrat"}', TRUE, 2),
('📧 Correspondance', 'Correspondance générale', 'document_type', '{"document_type_code": "correspondance"}', TRUE, 3),
('👥 RH', 'Documents RH', 'document_type', '{"document_type_code": "rh"}', TRUE, 4)
ON DUPLICATE KEY UPDATE name = VALUES(name);

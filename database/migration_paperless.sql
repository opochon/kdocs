CREATE TABLE IF NOT EXISTS logical_folders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    parent_id INT NULL,
    depth INT DEFAULT 0,
    icon VARCHAR(50) DEFAULT 'folder',
    color VARCHAR(7) DEFAULT '#3b82f6',
    filter_type ENUM('filesystem', 'document_type', 'correspondent', 'tag', 'custom') DEFAULT 'filesystem',
    filter_config JSON,
    sort_order INT DEFAULT 0,
    is_system BOOLEAN DEFAULT FALSE,
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES logical_folders(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_logical_folders_parent ON logical_folders(parent_id);
CREATE INDEX IF NOT EXISTS idx_logical_folders_sort ON logical_folders(sort_order);

-- Ajouter colonnes à documents (géré par migrate_paperless.php)

CREATE TABLE IF NOT EXISTS tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    color VARCHAR(7) DEFAULT '#6b7280',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS document_tags (
    document_id INT NOT NULL,
    tag_id INT NOT NULL,
    PRIMARY KEY (document_id, tag_id),
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(100) PRIMARY KEY,
    value TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO logical_folders (name, description, filter_type, filter_config, is_system, sort_order) VALUES
('📁 Tous les documents', 'Vue complète de tous les documents', 'filesystem', '{}', TRUE, 0),
('📄 Factures', 'Toutes les factures', 'document_type', JSON_OBJECT('document_type_code', 'facture'), TRUE, 1),
('📋 Contrats', 'Tous les contrats', 'document_type', JSON_OBJECT('document_type_code', 'contrat'), TRUE, 2),
('📧 Correspondance', 'Correspondance générale', 'document_type', JSON_OBJECT('document_type_code', 'correspondance'), TRUE, 3)
ON DUPLICATE KEY UPDATE name = VALUES(name);
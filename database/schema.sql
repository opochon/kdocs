-- Fichier: database/schema.sql
-- K-Docs - Schéma de base de données complet

CREATE DATABASE IF NOT EXISTS kdocs CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE kdocs;

-- Utilisateurs
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(50),
    last_name VARCHAR(50),
    is_active BOOLEAN DEFAULT TRUE,
    is_admin BOOLEAN DEFAULT FALSE,
    last_login DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Groupes
CREATE TABLE groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    description TEXT,
    parent_id INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES groups(id) ON DELETE SET NULL
);

-- Association users-groups
CREATE TABLE user_groups (
    user_id INT NOT NULL,
    group_id INT NOT NULL,
    PRIMARY KEY (user_id, group_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
);

-- Types de rôles métier
CREATE TABLE role_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    label VARCHAR(100) NOT NULL,
    description TEXT
);

-- Attribution des rôles
CREATE TABLE user_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    role_type_id INT NOT NULL,
    scope VARCHAR(50) DEFAULT '*',
    max_amount DECIMAL(15,2) NULL,
    valid_from DATE NULL,
    valid_to DATE NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_type_id) REFERENCES role_types(id) ON DELETE CASCADE,
    UNIQUE(user_id, role_type_id, scope)
);

-- Types de documents
CREATE TABLE document_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    label VARCHAR(100) NOT NULL,
    description TEXT,
    retention_days INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Correspondants (expéditeurs/destinataires)
CREATE TABLE correspondents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    address TEXT,
    is_supplier BOOLEAN DEFAULT FALSE,
    is_customer BOOLEAN DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Documents
CREATE TABLE documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255),
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255),
    file_path VARCHAR(500) NOT NULL,
    file_size INT,
    mime_type VARCHAR(100),
    document_type_id INT,
    correspondent_id INT,
    content TEXT,
    doc_date DATE,
    amount DECIMAL(15,2),
    currency VARCHAR(3) DEFAULT 'CHF',
    metadata JSON,
    checksum VARCHAR(64),
    ocr_status ENUM('pending', 'processing', 'done', 'error') DEFAULT 'pending',
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE SET NULL,
    FOREIGN KEY (correspondent_id) REFERENCES correspondents(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Tags
CREATE TABLE tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    color VARCHAR(7) DEFAULT '#6B7280',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Association documents-tags
CREATE TABLE document_tags (
    document_id INT NOT NULL,
    tag_id INT NOT NULL,
    PRIMARY KEY (document_id, tag_id),
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
);

-- Templates de workflow
CREATE TABLE workflow_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    label VARCHAR(100) NOT NULL,
    description TEXT,
    document_type_id INT,
    is_active BOOLEAN DEFAULT TRUE,
    config JSON,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE SET NULL
);

-- Étapes de workflow
CREATE TABLE workflow_steps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    workflow_id INT NOT NULL,
    step_order INT NOT NULL,
    code VARCHAR(50) NOT NULL,
    label VARCHAR(100),
    role_type_id INT,
    config JSON,
    FOREIGN KEY (workflow_id) REFERENCES workflow_templates(id) ON DELETE CASCADE,
    FOREIGN KEY (role_type_id) REFERENCES role_types(id) ON DELETE SET NULL,
    UNIQUE(workflow_id, step_order)
);

-- Transitions entre étapes
CREATE TABLE workflow_transitions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_step_id INT,
    to_step_id INT,
    action VARCHAR(50) NOT NULL,
    label VARCHAR(100),
    condition_config JSON,
    FOREIGN KEY (from_step_id) REFERENCES workflow_steps(id) ON DELETE CASCADE,
    FOREIGN KEY (to_step_id) REFERENCES workflow_steps(id) ON DELETE SET NULL
);

-- Instances de workflow (document en cours)
CREATE TABLE workflow_instances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    template_id INT NOT NULL,
    current_step_id INT,
    status ENUM('active', 'completed', 'cancelled', 'paused') DEFAULT 'active',
    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME,
    started_by INT,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES workflow_templates(id),
    FOREIGN KEY (current_step_id) REFERENCES workflow_steps(id),
    FOREIGN KEY (started_by) REFERENCES users(id)
);

-- Tâches
CREATE TABLE tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    workflow_instance_id INT NOT NULL,
    step_id INT NOT NULL,
    assigned_to INT,
    assigned_role_id INT,
    status ENUM('pending', 'in_progress', 'completed', 'escalated', 'cancelled') DEFAULT 'pending',
    due_date DATETIME,
    reminder_sent_at DATETIME,
    escalated_at DATETIME,
    completed_at DATETIME,
    action_taken VARCHAR(50),
    comment TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (workflow_instance_id) REFERENCES workflow_instances(id) ON DELETE CASCADE,
    FOREIGN KEY (step_id) REFERENCES workflow_steps(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id),
    FOREIGN KEY (assigned_role_id) REFERENCES role_types(id)
);

-- Historique des tâches
CREATE TABLE task_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    user_id INT,
    action VARCHAR(50) NOT NULL,
    comment TEXT,
    metadata JSON,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Notifications
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT,
    link VARCHAR(255),
    is_read BOOLEAN DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Sessions (pour auth)
CREATE TABLE sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    payload TEXT,
    last_activity INT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Données initiales
INSERT INTO users (username, email, password_hash, first_name, last_name, is_admin) 
VALUES ('root', 'root@localhost', '', 'Admin', 'Root', TRUE);

INSERT INTO role_types (code, label, description) VALUES
('VALIDATEUR_FACTURE', 'Validateur factures', 'Peut valider les factures fournisseurs'),
('VALIDATEUR_CONTRAT', 'Validateur contrats', 'Peut valider les contrats'),
('SAISIE_COMPTA', 'Saisie comptable', 'Peut saisir en comptabilité'),
('APPROBATEUR_PAIEMENT', 'Approbateur paiement', 'Peut approuver les paiements');

INSERT INTO document_types (code, label) VALUES
('FACTURE', 'Facture'),
('CONTRAT', 'Contrat'),
('CORRESPONDANCE', 'Correspondance'),
('RH', 'Document RH'),
('AUTRE', 'Autre');

INSERT INTO groups (name, description) VALUES
('Direction', 'Direction générale'),
('Comptabilité', 'Service comptabilité'),
('Achats', 'Service achats'),
('RH', 'Ressources humaines');

-- K-Docs - Migration: Système de groupes complet pour workflows
-- Version 2.0 - Style Alfresco

-- Table des groupes (si n'existe pas)
CREATE TABLE IF NOT EXISTS user_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50) UNIQUE,
    description TEXT,
    permissions JSON,
    is_system BOOLEAN DEFAULT FALSE,
    parent_group_id INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_group_id) REFERENCES user_groups(id) ON DELETE SET NULL,
    INDEX idx_code (code),
    INDEX idx_parent (parent_group_id)
);

-- Table d'appartenance utilisateur-groupe
CREATE TABLE IF NOT EXISTS user_group_memberships (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    group_id INT NOT NULL,
    role_in_group ENUM('member', 'manager', 'admin') DEFAULT 'member',
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE,
    UNIQUE KEY unique_membership (user_id, group_id)
);

-- Permissions par groupe sur types de documents
CREATE TABLE IF NOT EXISTS group_document_type_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    document_type_id INT NOT NULL,
    can_view BOOLEAN DEFAULT TRUE,
    can_edit BOOLEAN DEFAULT FALSE,
    can_delete BOOLEAN DEFAULT FALSE,
    can_approve BOOLEAN DEFAULT FALSE,
    max_approval_amount DECIMAL(15,2) NULL,
    FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE CASCADE,
    UNIQUE KEY unique_permission (group_id, document_type_id)
);

-- Tokens d'approbation pour workflow (liens sécurisés)
CREATE TABLE IF NOT EXISTS workflow_approval_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(128) UNIQUE NOT NULL,
    execution_id INT NOT NULL,
    document_id INT NOT NULL,
    node_id VARCHAR(100),
    assigned_user_id INT NULL,
    assigned_group_id INT NULL,
    action_required ENUM('approve', 'reject', 'review', 'sign') DEFAULT 'approve',
    message TEXT,
    email_subject VARCHAR(255),
    email_body TEXT,
    response_action VARCHAR(50) NULL,
    response_comment TEXT,
    responded_by INT NULL,
    responded_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    reminder_sent_at DATETIME NULL,
    escalated_to_user_id INT NULL,
    escalated_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_execution (execution_id),
    INDEX idx_document (document_id),
    INDEX idx_expires (expires_at),
    INDEX idx_assigned_user (assigned_user_id),
    INDEX idx_assigned_group (assigned_group_id)
);

-- Historique des décisions de workflow
CREATE TABLE IF NOT EXISTS workflow_decision_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    execution_id INT NOT NULL,
    document_id INT NOT NULL,
    node_id VARCHAR(100),
    token_id INT NULL,
    decision ENUM('approved', 'rejected', 'escalated', 'timeout', 'cancelled') NOT NULL,
    decided_by INT NULL,
    decided_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    comment TEXT,
    metadata JSON,
    FOREIGN KEY (token_id) REFERENCES workflow_approval_tokens(id) ON DELETE SET NULL,
    INDEX idx_execution (execution_id),
    INDEX idx_document (document_id),
    INDEX idx_decision (decision)
);

-- Notifications workflow
CREATE TABLE IF NOT EXISTS workflow_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    group_id INT NULL,
    execution_id INT,
    document_id INT,
    type ENUM('approval_request', 'approval_reminder', 'approved', 'rejected', 'escalated', 'info') NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT,
    link VARCHAR(500),
    is_read BOOLEAN DEFAULT FALSE,
    read_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_group (group_id),
    INDEX idx_read (is_read),
    INDEX idx_type (type)
);

-- Tâches d'approbation en attente (vue consolidée)
CREATE TABLE IF NOT EXISTS workflow_approval_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    execution_id INT NOT NULL,
    node_id VARCHAR(100),
    document_id INT NOT NULL,
    assigned_user_id INT NULL,
    assigned_group_id INT NULL,
    status ENUM('pending', 'in_progress', 'completed', 'escalated', 'cancelled', 'timeout') DEFAULT 'pending',
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    due_date DATETIME NULL,
    expires_at DATETIME NULL,
    escalate_to_user_id INT NULL,
    escalate_after_hours INT NULL,
    completed_at DATETIME NULL,
    completed_by INT NULL,
    decision VARCHAR(50) NULL,
    comment TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_assigned_user (assigned_user_id),
    INDEX idx_assigned_group (assigned_group_id),
    INDEX idx_document (document_id),
    INDEX idx_due_date (due_date)
);

-- Ajouter colonne group_id à users si pas présente
-- ALTER TABLE users ADD COLUMN IF NOT EXISTS primary_group_id INT NULL;

-- Données initiales - Groupes système
INSERT IGNORE INTO user_groups (name, code, description, is_system) VALUES
('Administrateurs', 'ADMIN', 'Administrateurs système avec tous les droits', TRUE),
('Superviseurs', 'SUPERVISORS', 'Superviseurs pouvant approuver les documents', TRUE),
('Comptabilité', 'ACCOUNTING', 'Service comptabilité', TRUE),
('Direction', 'MANAGEMENT', 'Direction générale', TRUE),
('Utilisateurs', 'USERS', 'Utilisateurs standard', TRUE);

-- Ajouter l'admin root au groupe Administrateurs
INSERT IGNORE INTO user_group_memberships (user_id, group_id, role_in_group)
SELECT u.id, g.id, 'admin'
FROM users u, user_groups g
WHERE u.username = 'root' AND g.code = 'ADMIN';

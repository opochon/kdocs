-- Migration: 016_document_validation.sql
-- Ajoute les champs de validation sur les documents et les rôles de base
-- Date: 2026-01-25

-- ============================================
-- 1. CHAMPS DE VALIDATION SUR DOCUMENTS
-- ============================================

ALTER TABLE documents
ADD COLUMN IF NOT EXISTS validation_status ENUM('pending', 'approved', 'rejected') DEFAULT NULL
    COMMENT 'Statut de validation du document';

ALTER TABLE documents
ADD COLUMN IF NOT EXISTS validated_by INT DEFAULT NULL
    COMMENT 'ID de l''utilisateur qui a validé';

ALTER TABLE documents
ADD COLUMN IF NOT EXISTS validated_at DATETIME DEFAULT NULL
    COMMENT 'Date/heure de validation';

ALTER TABLE documents
ADD COLUMN IF NOT EXISTS validation_comment TEXT DEFAULT NULL
    COMMENT 'Commentaire de validation';

ALTER TABLE documents
ADD COLUMN IF NOT EXISTS validation_level INT DEFAULT NULL
    COMMENT 'Niveau de validation atteint (pour multi-niveau)';

ALTER TABLE documents
ADD COLUMN IF NOT EXISTS requires_approval BOOLEAN DEFAULT FALSE
    COMMENT 'Indique si le document nécessite une approbation';

ALTER TABLE documents
ADD COLUMN IF NOT EXISTS approval_deadline DATETIME DEFAULT NULL
    COMMENT 'Date limite pour approbation';

-- Index pour recherche rapide
CREATE INDEX IF NOT EXISTS idx_documents_validation_status ON documents(validation_status);
CREATE INDEX IF NOT EXISTS idx_documents_validated_by ON documents(validated_by);
CREATE INDEX IF NOT EXISTS idx_documents_requires_approval ON documents(requires_approval);

-- Contrainte FK (si non existante)
-- ALTER TABLE documents ADD CONSTRAINT fk_documents_validated_by
--     FOREIGN KEY (validated_by) REFERENCES users(id) ON DELETE SET NULL;

-- ============================================
-- 2. RÔLES DE BASE POUR VALIDATION
-- ============================================

-- S'assurer que la table role_types existe
CREATE TABLE IF NOT EXISTS role_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    label VARCHAR(100) NOT NULL,
    description TEXT,
    level INT DEFAULT 0 COMMENT 'Niveau hiérarchique (0=base, 5=admin)',
    permissions JSON COMMENT 'Permissions associées au rôle',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- S'assurer que la table user_roles existe
CREATE TABLE IF NOT EXISTS user_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    role_type_id INT NOT NULL,
    scope VARCHAR(50) DEFAULT '*' COMMENT 'Scope: * = tous, ou document_type_code',
    max_amount DECIMAL(15,2) NULL COMMENT 'Montant max autorisé pour ce rôle',
    valid_from DATE NULL,
    valid_to DATE NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, role_type_id, scope)
);

-- Insérer les rôles de base
INSERT IGNORE INTO role_types (code, label, description, level, permissions) VALUES
('VIEWER', 'Lecteur', 'Peut consulter les documents sans modification', 0,
 '["documents.view", "tags.view", "correspondents.view"]'),

('CONTRIBUTOR', 'Contributeur', 'Peut ajouter et modifier ses propres documents', 1,
 '["documents.view", "documents.create", "documents.edit_own", "tags.view", "tags.create", "correspondents.view", "correspondents.create"]'),

('VALIDATOR_L1', 'Validateur Niveau 1', 'Peut valider les documents jusqu''à un certain montant', 2,
 '["documents.view", "documents.validate", "documents.comment"]'),

('VALIDATOR_L2', 'Validateur Niveau 2', 'Peut valider les documents de montant moyen', 3,
 '["documents.view", "documents.validate", "documents.comment", "documents.escalate"]'),

('APPROVER', 'Approbateur', 'Peut approuver tous les documents sans limite', 4,
 '["documents.view", "documents.validate", "documents.approve", "documents.reject", "documents.comment"]'),

('ADMIN', 'Administrateur', 'Accès complet au système', 5,
 '["*"]');

-- ============================================
-- 3. TABLE HISTORIQUE DES VALIDATIONS
-- ============================================

CREATE TABLE IF NOT EXISTS document_validation_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    action ENUM('submitted', 'approved', 'rejected', 'escalated', 'returned', 'commented') NOT NULL,
    from_status VARCHAR(20),
    to_status VARCHAR(20),
    performed_by INT NOT NULL,
    role_code VARCHAR(50),
    comment TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_validation_history_document (document_id),
    INDEX idx_validation_history_user (performed_by),
    INDEX idx_validation_history_date (created_at)
);

-- ============================================
-- 4. RÈGLES D'APPROBATION AUTOMATIQUE
-- ============================================

CREATE TABLE IF NOT EXISTS approval_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    priority INT DEFAULT 0 COMMENT 'Ordre d''évaluation (plus bas = prioritaire)',

    -- Conditions de déclenchement
    document_type_id INT DEFAULT NULL,
    document_type_code VARCHAR(50) DEFAULT NULL,
    correspondent_id INT DEFAULT NULL,
    min_amount DECIMAL(15,2) DEFAULT NULL,
    max_amount DECIMAL(15,2) DEFAULT NULL,
    tag_ids JSON DEFAULT NULL COMMENT 'Liste des tag IDs requis',

    -- Configuration d'approbation
    required_role VARCHAR(50) NOT NULL COMMENT 'Rôle requis (VALIDATOR_L1, APPROVER, etc.)',
    required_group_id INT DEFAULT NULL,
    approval_type ENUM('single', 'sequential', 'parallel') DEFAULT 'single',
    approval_count INT DEFAULT 1 COMMENT 'Nombre d''approbations requises',

    -- Paramètres
    timeout_hours INT DEFAULT 72,
    auto_escalate BOOLEAN DEFAULT FALSE,
    escalate_to_role VARCHAR(50) DEFAULT NULL,
    escalate_after_hours INT DEFAULT NULL,

    -- Notifications
    notify_on_submit BOOLEAN DEFAULT TRUE,
    notify_on_approve BOOLEAN DEFAULT TRUE,
    notify_on_reject BOOLEAN DEFAULT TRUE,
    reminder_hours INT DEFAULT NULL COMMENT 'Envoyer rappel X heures avant expiration',

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_approval_rules_active (is_active, priority),
    INDEX idx_approval_rules_doctype (document_type_id)
);

-- Règles d'exemple
INSERT IGNORE INTO approval_rules (name, description, document_type_code, min_amount, required_role, timeout_hours) VALUES
('Petites factures', 'Factures < 1000 CHF - validation niveau 1', 'FACTURE', NULL, 'VALIDATOR_L1', 48),
('Factures moyennes', 'Factures 1000-5000 CHF - validation niveau 2', 'FACTURE', 1000, 'VALIDATOR_L2', 72),
('Grosses factures', 'Factures > 5000 CHF - approbation direction', 'FACTURE', 5000, 'APPROVER', 96);

UPDATE approval_rules SET max_amount = 999.99 WHERE name = 'Petites factures';
UPDATE approval_rules SET max_amount = 4999.99 WHERE name = 'Factures moyennes';

-- ============================================
-- 5. VUE POUR DOCUMENTS EN ATTENTE
-- ============================================

CREATE OR REPLACE VIEW v_documents_pending_approval AS
SELECT
    d.id,
    d.title,
    d.original_filename,
    d.amount,
    d.currency,
    d.doc_date,
    d.validation_status,
    d.requires_approval,
    d.approval_deadline,
    d.created_at,
    dt.code as document_type_code,
    dt.label as document_type_label,
    c.name as correspondent_name,
    u.username as created_by_username,
    DATEDIFF(d.approval_deadline, NOW()) as days_until_deadline
FROM documents d
LEFT JOIN document_types dt ON d.document_type_id = dt.id
LEFT JOIN correspondents c ON d.correspondent_id = c.id
LEFT JOIN users u ON d.created_by = u.id
WHERE d.requires_approval = TRUE
  AND d.validation_status IN ('pending', NULL)
ORDER BY d.approval_deadline ASC, d.amount DESC;

-- Migration Mail Accounts & Rules pour K-Docs
-- Date: 2026-01-21

-- Table des comptes email
CREATE TABLE IF NOT EXISTS mail_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'Nom du compte',
    imap_server VARCHAR(255) NOT NULL COMMENT 'Serveur IMAP',
    imap_port INT NOT NULL DEFAULT 993 COMMENT 'Port IMAP',
    imap_security ENUM('none', 'ssl', 'tls') DEFAULT 'ssl' COMMENT 'Sécurité IMAP',
    username VARCHAR(255) NOT NULL COMMENT 'Nom d''utilisateur',
    password_encrypted TEXT NOT NULL COMMENT 'Mot de passe chiffré',
    character_set VARCHAR(50) DEFAULT 'UTF-8' COMMENT 'Encodage des emails',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Compte actif',
    last_checked_at DATETIME NULL COMMENT 'Dernière vérification',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_last_checked (last_checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table des règles de traitement email
CREATE TABLE IF NOT EXISTS mail_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mail_account_id INT NOT NULL COMMENT 'Compte email associé',
    name VARCHAR(255) NOT NULL COMMENT 'Nom de la règle',
    order_index INT DEFAULT 0 COMMENT 'Ordre d''exécution',
    filter_from VARCHAR(255) NULL COMMENT 'Filtre expéditeur',
    filter_subject VARCHAR(255) NULL COMMENT 'Filtre objet',
    filter_body TEXT NULL COMMENT 'Filtre corps du message',
    filter_attachment_filename VARCHAR(255) NULL COMMENT 'Filtre nom pièce jointe',
    maximum_age INT NULL COMMENT 'Âge maximum en jours',
    action VARCHAR(50) NOT NULL DEFAULT 'tag' COMMENT 'Action (tag, delete, mark_read, move)',
    action_parameter TEXT NULL COMMENT 'Paramètre de l''action (tag_id, folder, etc.)',
    assign_title_from ENUM('subject', 'filename', 'none') DEFAULT 'subject' COMMENT 'Assigner titre depuis',
    assign_correspondent_from ENUM('from', 'to', 'none') DEFAULT 'from' COMMENT 'Assigner correspondant depuis',
    assign_document_type_id INT NULL COMMENT 'Type de document à assigner',
    assign_storage_path_id INT NULL COMMENT 'Chemin de stockage',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Règle active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_account (mail_account_id),
    INDEX idx_active (is_active),
    INDEX idx_order (order_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ajouter les clés étrangères après création de la table
ALTER TABLE mail_rules 
    ADD CONSTRAINT fk_mail_rules_account FOREIGN KEY (mail_account_id) REFERENCES mail_accounts(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_mail_rules_document_type FOREIGN KEY (assign_document_type_id) REFERENCES document_types(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_mail_rules_storage_path FOREIGN KEY (assign_storage_path_id) REFERENCES storage_paths(id) ON DELETE SET NULL;

-- Table des tags assignés par règle
CREATE TABLE IF NOT EXISTS mail_rule_tags (
    mail_rule_id INT NOT NULL,
    tag_id INT NOT NULL,
    PRIMARY KEY (mail_rule_id, tag_id),
    FOREIGN KEY (mail_rule_id) REFERENCES mail_rules(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table des logs de traitement email
CREATE TABLE IF NOT EXISTS mail_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mail_account_id INT NOT NULL,
    mail_rule_id INT NULL COMMENT 'Règle appliquée',
    message_id VARCHAR(255) NOT NULL COMMENT 'ID du message email',
    subject VARCHAR(500) NULL COMMENT 'Objet de l''email',
    from_address VARCHAR(255) NULL COMMENT 'Expéditeur',
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Date de traitement',
    status ENUM('success', 'error', 'skipped') DEFAULT 'success' COMMENT 'Statut',
    error_message TEXT NULL COMMENT 'Message d''erreur',
    document_id INT NULL COMMENT 'Document créé',
    FOREIGN KEY (mail_account_id) REFERENCES mail_accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (mail_rule_id) REFERENCES mail_rules(id) ON DELETE SET NULL,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE SET NULL,
    INDEX idx_account (mail_account_id),
    INDEX idx_status (status),
    INDEX idx_processed_at (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

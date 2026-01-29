-- Migration 024: Système unifié d'extraction de données
-- Remplace/unifie: classification_fields, attribution_rules, classification_training_data
-- Inspiré de Rossum (feedback loop) + Paperless-ngx (simplicité)

-- ============================================================
-- TABLE: extraction_templates
-- Définit QUOI extraire et COMMENT
-- ============================================================
CREATE TABLE IF NOT EXISTS `extraction_templates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL COMMENT 'Nom affiché: "Compte comptable"',
    `field_code` VARCHAR(50) NOT NULL COMMENT 'Code unique: compte_comptable',
    `field_type` ENUM('text', 'number', 'date', 'money', 'select', 'multi_select') DEFAULT 'text',
    `description` TEXT NULL COMMENT 'Description pour l''utilisateur',

    -- Options pour type select/multi_select
    `options` JSON NULL COMMENT '["4000", "4100", "4200"] ou [{"value": "4000", "label": "Achats"}]',

    -- Conditions d'application (NULL = tous)
    `applies_to_types` JSON NULL COMMENT '[1, 2, 5] IDs document_types',
    `applies_to_correspondents` JSON NULL COMMENT '[3, 7] IDs correspondents',

    -- Méthodes d'extraction (ordre de priorité)
    `use_history` BOOLEAN DEFAULT TRUE COMMENT 'Chercher dans historique (même correspondant)',
    `use_rules` BOOLEAN DEFAULT FALSE COMMENT 'Utiliser règles manuelles',
    `rules` JSON NULL COMMENT '[{"if": {"correspondent_id": 5}, "then": "4000"}]',
    `use_ai` BOOLEAN DEFAULT TRUE COMMENT 'Utiliser IA si pas trouvé',
    `ai_prompt` TEXT NULL COMMENT 'Prompt personnalisé pour Claude',
    `use_regex` BOOLEAN DEFAULT FALSE COMMENT 'Extraction par regex',
    `regex_pattern` VARCHAR(500) NULL COMMENT 'Pattern regex avec groupe de capture',

    -- Apprentissage
    `learn_from_corrections` BOOLEAN DEFAULT TRUE COMMENT 'Mémoriser les corrections',
    `min_confidence_for_auto` DECIMAL(3,2) DEFAULT 0.85 COMMENT 'Seuil pour application auto',
    `show_confidence` BOOLEAN DEFAULT TRUE COMMENT 'Afficher le score de confiance',

    -- Actions post-extraction
    `post_action` ENUM('none', 'create_tag', 'create_correspondent', 'trigger_workflow') DEFAULT 'none',
    `post_action_config` JSON NULL COMMENT 'Config de l''action post',

    -- Metadata
    `is_active` BOOLEAN DEFAULT TRUE,
    `is_required` BOOLEAN DEFAULT FALSE COMMENT 'Champ obligatoire pour validation',
    `display_order` INT DEFAULT 0 COMMENT 'Ordre d''affichage',
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY `uk_field_code` (`field_code`),
    INDEX `idx_active` (`is_active`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: extraction_history
-- Historique des extractions pour apprentissage
-- ============================================================
CREATE TABLE IF NOT EXISTS `extraction_history` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `template_id` INT UNSIGNED NOT NULL,

    -- Clés de mémorisation (pour retrouver la bonne valeur)
    `correspondent_id` INT UNSIGNED NULL COMMENT 'Fournisseur/Client associé',
    `document_type_id` INT UNSIGNED NULL COMMENT 'Type de document',
    `context_hash` VARCHAR(64) NULL COMMENT 'Hash du contexte (pour matching avancé)',

    -- Valeur extraite/corrigée
    `extracted_value` VARCHAR(500) NOT NULL,
    `normalized_value` VARCHAR(500) NULL COMMENT 'Valeur normalisée (ex: trim, lowercase)',

    -- Statistiques d'usage
    `times_used` INT UNSIGNED DEFAULT 1 COMMENT 'Nombre de fois utilisée',
    `times_confirmed` INT UNSIGNED DEFAULT 0 COMMENT 'Confirmations utilisateur',
    `times_corrected` INT UNSIGNED DEFAULT 0 COMMENT 'Corrections utilisateur',

    -- Confiance calculée
    `confidence` DECIMAL(3,2) DEFAULT 0.50 COMMENT 'Confiance actuelle (0-1)',
    `source` ENUM('manual', 'ai', 'rules', 'regex', 'import') DEFAULT 'manual',

    -- Timestamps
    `first_used_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `last_used_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (`template_id`) REFERENCES `extraction_templates`(`id`) ON DELETE CASCADE,

    -- Index pour recherche rapide
    UNIQUE KEY `uk_template_correspondent_type_value` (`template_id`, `correspondent_id`, `document_type_id`, `normalized_value`(100)),
    INDEX `idx_lookup` (`template_id`, `correspondent_id`, `confidence` DESC),
    INDEX `idx_context` (`template_id`, `context_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: document_extracted_data
-- Valeurs extraites par document
-- ============================================================
CREATE TABLE IF NOT EXISTS `document_extracted_data` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `document_id` INT UNSIGNED NOT NULL,
    `template_id` INT UNSIGNED NOT NULL,

    -- Valeur
    `value` VARCHAR(500) NULL,
    `confidence` DECIMAL(3,2) NULL COMMENT 'Confiance au moment de l''extraction',
    `source` ENUM('history', 'rules', 'ai', 'regex', 'manual') NOT NULL,

    -- Statut
    `is_confirmed` BOOLEAN DEFAULT FALSE COMMENT 'Confirmé par utilisateur',
    `is_corrected` BOOLEAN DEFAULT FALSE COMMENT 'Corrigé par utilisateur',
    `original_value` VARCHAR(500) NULL COMMENT 'Valeur avant correction',

    -- Timestamps
    `extracted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `confirmed_at` DATETIME NULL,

    FOREIGN KEY (`template_id`) REFERENCES `extraction_templates`(`id`) ON DELETE CASCADE,
    INDEX `idx_document` (`document_id`),
    UNIQUE KEY `uk_document_template` (`document_id`, `template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: extraction_audit_log
-- Audit complet des extractions
-- ============================================================
CREATE TABLE IF NOT EXISTS `extraction_audit_log` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `document_id` INT UNSIGNED NOT NULL,
    `template_id` INT UNSIGNED NOT NULL,
    `field_code` VARCHAR(50) NOT NULL,

    `action` ENUM('extracted', 'confirmed', 'corrected', 'cleared') NOT NULL,
    `old_value` VARCHAR(500) NULL,
    `new_value` VARCHAR(500) NULL,
    `confidence` DECIMAL(3,2) NULL,
    `source` VARCHAR(20) NULL,

    `user_id` INT UNSIGNED NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX `idx_document` (`document_id`),
    INDEX `idx_template` (`template_id`),
    INDEX `idx_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TEMPLATES PAR DÉFAUT
-- ============================================================
INSERT INTO `extraction_templates` (`name`, `field_code`, `field_type`, `description`, `use_history`, `use_ai`, `ai_prompt`, `display_order`) VALUES
('Compte comptable', 'compte_comptable', 'select', 'Numéro de compte pour la comptabilité', TRUE, TRUE, 'Quel compte comptable (numéro à 4 chiffres) correspond à ce document ? Réponds uniquement le numéro.', 1),
('Centre de coût', 'centre_cout', 'select', 'Centre de coût ou département', TRUE, TRUE, 'Quel centre de coût ou département est concerné par ce document ?', 2),
('Référence externe', 'reference_externe', 'text', 'Numéro de référence, commande, contrat', TRUE, FALSE, NULL, 3),
('Montant HT', 'montant_ht', 'money', 'Montant hors taxes', FALSE, TRUE, 'Quel est le montant hors taxes (HT) de ce document ? Réponds uniquement le nombre.', 4),
('Montant TTC', 'montant_ttc', 'money', 'Montant toutes taxes comprises', FALSE, TRUE, 'Quel est le montant TTC de ce document ? Réponds uniquement le nombre.', 5);

-- Pour les templates de type select, on peut ajouter des options plus tard via l'interface
-- Exemple: UPDATE extraction_templates SET options = '["4000","4100","4200","6000","6100"]' WHERE field_code = 'compte_comptable';

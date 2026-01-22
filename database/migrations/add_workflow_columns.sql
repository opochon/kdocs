-- Migration pour ajouter les colonnes manquantes aux workflows (Parité Paperless-ngx)
-- Ajout des colonnes pour les triggers et actions avancées

-- Ajout des colonnes manquantes pour les triggers
ALTER TABLE workflow_triggers 
    ADD COLUMN IF NOT EXISTS sources TEXT DEFAULT NULL COMMENT 'JSON: consume_folder, api_upload, mail_fetch',
    ADD COLUMN IF NOT EXISTS filter_filename VARCHAR(500) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS filter_path VARCHAR(500) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS filter_has_tags TEXT DEFAULT NULL COMMENT 'JSON array of tag IDs',
    ADD COLUMN IF NOT EXISTS filter_has_all_tags TEXT DEFAULT NULL COMMENT 'JSON array of tag IDs (ALL must match)',
    ADD COLUMN IF NOT EXISTS filter_has_not_tags TEXT DEFAULT NULL COMMENT 'JSON array of tag IDs to exclude',
    ADD COLUMN IF NOT EXISTS filter_has_correspondent INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS filter_has_not_correspondents TEXT DEFAULT NULL COMMENT 'JSON array',
    ADD COLUMN IF NOT EXISTS filter_has_document_type INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS filter_has_not_document_types TEXT DEFAULT NULL COMMENT 'JSON array',
    ADD COLUMN IF NOT EXISTS filter_has_storage_path INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS filter_has_not_storage_paths TEXT DEFAULT NULL COMMENT 'JSON array',
    ADD COLUMN IF NOT EXISTS match_text VARCHAR(500) DEFAULT NULL COMMENT 'Text to match in content',
    ADD COLUMN IF NOT EXISTS matching_algorithm ENUM('any', 'all', 'exact', 'regex', 'fuzzy') DEFAULT 'any',
    ADD COLUMN IF NOT EXISTS is_insensitive BOOLEAN DEFAULT TRUE,
    ADD COLUMN IF NOT EXISTS schedule_date_field ENUM('created', 'added', 'modified', 'custom_field') DEFAULT 'created',
    ADD COLUMN IF NOT EXISTS schedule_date_custom_field INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS schedule_offset_days INT DEFAULT 0,
    ADD COLUMN IF NOT EXISTS schedule_is_recurring BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS schedule_recurring_interval_days INT DEFAULT 30;

-- Mettre à jour le trigger_type pour inclure les nouveaux types
ALTER TABLE workflow_triggers 
    MODIFY COLUMN trigger_type ENUM('consumption', 'document_added', 'document_updated', 'scheduled', 'document_modified', 'tag_added', 'correspondent_added', 'type_added') NOT NULL;

-- Ajout des colonnes manquantes pour les actions
ALTER TABLE workflow_actions
    ADD COLUMN IF NOT EXISTS assign_title VARCHAR(500) DEFAULT NULL COMMENT 'Title template with placeholders',
    ADD COLUMN IF NOT EXISTS assign_tags TEXT DEFAULT NULL COMMENT 'JSON array of tag IDs',
    ADD COLUMN IF NOT EXISTS assign_document_type INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS assign_correspondent INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS assign_storage_path INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS assign_owner INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS assign_view_users TEXT DEFAULT NULL COMMENT 'JSON array',
    ADD COLUMN IF NOT EXISTS assign_view_groups TEXT DEFAULT NULL COMMENT 'JSON array',
    ADD COLUMN IF NOT EXISTS assign_change_users TEXT DEFAULT NULL COMMENT 'JSON array',
    ADD COLUMN IF NOT EXISTS assign_change_groups TEXT DEFAULT NULL COMMENT 'JSON array',
    ADD COLUMN IF NOT EXISTS assign_custom_fields TEXT DEFAULT NULL COMMENT 'JSON array of field IDs',
    ADD COLUMN IF NOT EXISTS assign_custom_fields_values TEXT DEFAULT NULL COMMENT 'JSON object {field_id: value}',
    ADD COLUMN IF NOT EXISTS remove_tags TEXT DEFAULT NULL COMMENT 'JSON array',
    ADD COLUMN IF NOT EXISTS remove_all_tags BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS remove_correspondents TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS remove_all_correspondents BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS remove_document_types TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS remove_all_document_types BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS remove_storage_paths TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS remove_all_storage_paths BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS remove_custom_fields TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS remove_all_custom_fields BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS remove_owners TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS remove_all_owners BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS remove_view_users TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS remove_view_groups TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS remove_change_users TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS remove_change_groups TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS remove_all_permissions BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS email_subject VARCHAR(500) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS email_body TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS email_to VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS email_include_document BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS webhook_url VARCHAR(500) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS webhook_use_params BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS webhook_as_json BOOLEAN DEFAULT TRUE,
    ADD COLUMN IF NOT EXISTS webhook_params TEXT DEFAULT NULL COMMENT 'JSON object',
    ADD COLUMN IF NOT EXISTS webhook_body TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS webhook_headers TEXT DEFAULT NULL COMMENT 'JSON object',
    ADD COLUMN IF NOT EXISTS webhook_include_document BOOLEAN DEFAULT FALSE;

-- Mettre à jour le action_type pour utiliser des entiers (1=Assignment, 2=Removal, 3=Email, 4=Webhook)
ALTER TABLE workflow_actions 
    MODIFY COLUMN action_type INT NOT NULL COMMENT '1=Assignment, 2=Removal, 3=Email, 4=Webhook';

-- Garder compatibilité avec ancien système (action_value peut être NULL maintenant)
ALTER TABLE workflow_actions 
    MODIFY COLUMN action_value TEXT NULL COMMENT 'Valeur de l\'action (deprecated, utiliser les colonnes spécifiques)';

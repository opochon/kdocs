-- Correction des types de triggers pour correspondre à Paperless-ngx
-- Types: Consumption (1), DocumentAdded (2), DocumentUpdated (3), Scheduled (4)

-- Modifier la colonne trigger_type pour utiliser des valeurs numériques comme Paperless
ALTER TABLE workflow_triggers 
MODIFY COLUMN trigger_type ENUM('consumption', 'document_added', 'document_updated', 'scheduled') NOT NULL;

-- Mettre à jour les valeurs existantes
UPDATE workflow_triggers SET trigger_type = 'consumption' WHERE trigger_type = 'document_added';
UPDATE workflow_triggers SET trigger_type = 'document_added' WHERE trigger_type = 'document_created';
UPDATE workflow_triggers SET trigger_type = 'document_updated' WHERE trigger_type = 'document_modified';

-- Ajouter des colonnes pour les filtres comme Paperless
ALTER TABLE workflow_triggers
ADD COLUMN filter_filename VARCHAR(255) NULL COMMENT 'Filtre sur le nom de fichier',
ADD COLUMN filter_path VARCHAR(255) NULL COMMENT 'Filtre sur le chemin',
ADD COLUMN filter_has_tags TEXT NULL COMMENT 'IDs de tags (JSON array)',
ADD COLUMN filter_has_all_tags TEXT NULL COMMENT 'IDs de tags requis (JSON array)',
ADD COLUMN filter_has_not_tags TEXT NULL COMMENT 'IDs de tags exclus (JSON array)',
ADD COLUMN filter_has_correspondent INT NULL COMMENT 'ID du correspondant',
ADD COLUMN filter_has_not_correspondents TEXT NULL COMMENT 'IDs de correspondants exclus (JSON array)',
ADD COLUMN filter_has_document_type INT NULL COMMENT 'ID du type de document',
ADD COLUMN filter_has_not_document_types TEXT NULL COMMENT 'IDs de types exclus (JSON array)',
ADD COLUMN filter_has_storage_path INT NULL COMMENT 'ID du chemin de stockage',
ADD COLUMN filter_has_not_storage_paths TEXT NULL COMMENT 'IDs de chemins exclus (JSON array)',
ADD COLUMN match VARCHAR(255) NULL COMMENT 'Expression de correspondance',
ADD COLUMN matching_algorithm INT DEFAULT 0 COMMENT 'Algorithme de correspondance',
ADD COLUMN is_insensitive BOOLEAN DEFAULT FALSE COMMENT 'Correspondance insensible à la casse',
ADD COLUMN schedule_offset_days INT NULL COMMENT 'Décalage en jours pour trigger Scheduled',
ADD COLUMN schedule_is_recurring BOOLEAN DEFAULT FALSE COMMENT 'Récurrence pour trigger Scheduled',
ADD COLUMN schedule_recurring_interval_days INT NULL COMMENT 'Intervalle de récurrence en jours',
ADD COLUMN schedule_date_field VARCHAR(50) NULL COMMENT 'Champ de date pour trigger Scheduled (added, created, modified, custom_field)',
ADD COLUMN schedule_date_custom_field INT NULL COMMENT 'ID du champ personnalisé pour schedule_date_field';

-- Supprimer les anciennes colonnes condition_type et condition_value si elles existent
-- (on garde pour compatibilité mais on utilisera les nouveaux champs)

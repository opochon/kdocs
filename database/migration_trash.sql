-- Migration pour système de trash
-- Les documents ne sont jamais supprimés définitivement

ALTER TABLE documents 
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS deleted_by INT NULL;

CREATE INDEX IF NOT EXISTS idx_documents_deleted ON documents(deleted_at);

-- Ajouter colonne pour détection de modifications
ALTER TABLE documents 
    ADD COLUMN IF NOT EXISTS file_modified_at INT NULL COMMENT 'Timestamp de dernière modification du fichier';

CREATE INDEX IF NOT EXISTS idx_documents_file_modified ON documents(file_modified_at);

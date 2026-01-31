-- K-Docs - Migration: Support des pièces jointes liées (parent_id)
-- À exécuter pour permettre le lien mail -> pièces jointes
-- Version: 024

-- Ajouter la colonne parent_id si elle n'existe pas
SET @dbname = DATABASE();
SET @tablename = 'documents';
SET @columnname = 'parent_id';

SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE documents ADD COLUMN parent_id INT NULL AFTER correspondent_id'
));

PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ajouter la colonne metadata si elle n'existe pas
SET @columnname = 'metadata';

SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE documents ADD COLUMN metadata JSON NULL'
));

PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ajouter l'index sur parent_id
CREATE INDEX IF NOT EXISTS idx_documents_parent ON documents(parent_id);

-- Ajouter la colonne email dans correspondents si elle n'existe pas
SET @tablename = 'correspondents';
SET @columnname = 'email';

SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE correspondents ADD COLUMN email VARCHAR(255) NULL AFTER name'
));

PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Vérification
SELECT 'Migration 024 completed - MSG Import support added' AS status;

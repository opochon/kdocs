-- Migration pour ajouter les permissions aux types de documents (comme Paperless-ngx)

-- Ajouter les colonnes de permissions
ALTER TABLE document_types
ADD COLUMN owner_id INT NULL COMMENT 'Propriétaire du type de document',
ADD COLUMN view_users TEXT NULL COMMENT 'IDs des utilisateurs autorisés à voir (JSON array)',
ADD COLUMN view_groups TEXT NULL COMMENT 'IDs des groupes autorisés à voir (JSON array)',
ADD COLUMN modify_users TEXT NULL COMMENT 'IDs des utilisateurs autorisés à modifier (JSON array)',
ADD COLUMN modify_groups TEXT NULL COMMENT 'IDs des groupes autorisés à modifier (JSON array)',
ADD COLUMN is_insensitive BOOLEAN DEFAULT TRUE COMMENT 'Correspondance insensible à la casse',
ADD FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL;

-- Modifier matching_algorithm pour utiliser des valeurs numériques comme Paperless (0-6)
ALTER TABLE document_types
MODIFY COLUMN matching_algorithm INT DEFAULT 6 COMMENT '0=None, 1=Any, 2=All, 3=Literal, 4=Regex, 5=Fuzzy, 6=Auto';

-- Mettre à jour les valeurs existantes
UPDATE document_types SET matching_algorithm = 6 WHERE matching_algorithm = 'auto' OR matching_algorithm IS NULL;
UPDATE document_types SET matching_algorithm = 0 WHERE matching_algorithm = 'none';
UPDATE document_types SET matching_algorithm = 1 WHERE matching_algorithm = 'any';
UPDATE document_types SET matching_algorithm = 2 WHERE matching_algorithm = 'all';
UPDATE document_types SET matching_algorithm = 3 WHERE matching_algorithm = 'exact' OR matching_algorithm = 'literal';
UPDATE document_types SET matching_algorithm = 4 WHERE matching_algorithm = 'regex';
UPDATE document_types SET matching_algorithm = 5 WHERE matching_algorithm = 'fuzzy';

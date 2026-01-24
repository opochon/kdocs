-- Migration 012: Colonnes pour la séparation de PDF multi-pages
-- Permet de gérer les relations parent/enfant et les pages séparées

ALTER TABLE `documents` 
ADD COLUMN IF NOT EXISTS `parent_document_id` INT UNSIGNED NULL COMMENT 'ID du document parent si ce document provient d\'un split',
ADD COLUMN IF NOT EXISTS `split_pages` JSON NULL COMMENT 'Numéros de pages (0-indexed) si ce document provient d\'un split',
ADD COLUMN IF NOT EXISTS `split_into_count` INT UNSIGNED NULL DEFAULT 0 COMMENT 'Nombre de documents créés par séparation';

-- Index pour les relations parent/enfant
CREATE INDEX IF NOT EXISTS `idx_parent_document` ON `documents` (`parent_document_id`);

-- Contrainte de clé étrangère (optionnel, peut être ajouté plus tard)
-- ALTER TABLE `documents` ADD CONSTRAINT `fk_parent_document` FOREIGN KEY (`parent_document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL;

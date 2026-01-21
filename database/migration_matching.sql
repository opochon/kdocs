-- Migration pour Matching Algorithms (Phase 3.1)
-- Ajoute les colonnes match et matching_algorithm aux tables existantes

-- Tags
ALTER TABLE tags 
ADD COLUMN match VARCHAR(500) NULL COMMENT 'Texte de correspondance pour matching automatique' AFTER color,
ADD COLUMN matching_algorithm ENUM('none', 'any', 'all', 'exact', 'regex', 'fuzzy', 'auto') DEFAULT 'none' AFTER match;

-- Correspondants
ALTER TABLE correspondents 
ADD COLUMN matching_algorithm ENUM('none', 'any', 'all', 'exact', 'regex', 'fuzzy', 'auto') DEFAULT 'none' AFTER match;

-- Document Types
ALTER TABLE document_types 
ADD COLUMN match VARCHAR(500) NULL COMMENT 'Texte de correspondance pour matching automatique' AFTER label,
ADD COLUMN matching_algorithm ENUM('none', 'any', 'all', 'exact', 'regex', 'fuzzy', 'auto') DEFAULT 'none' AFTER match;

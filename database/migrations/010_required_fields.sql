-- Migration 010: Champs obligatoires et vérification d'utilisation
-- Ajouter colonne is_required pour protéger les champs essentiels

ALTER TABLE classification_fields
ADD COLUMN IF NOT EXISTS is_required BOOLEAN DEFAULT FALSE COMMENT 'Champ obligatoire, ne peut pas être supprimé';

-- Marquer date et type comme obligatoires
UPDATE classification_fields 
SET is_required = TRUE 
WHERE field_code IN ('date', 'type');

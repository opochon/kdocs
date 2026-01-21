-- Migration pour Nested Tags (Phase 3.2)
-- Permet de créer une hiérarchie de tags avec parent/enfant

ALTER TABLE tags 
ADD COLUMN parent_id INT NULL AFTER id,
ADD FOREIGN KEY (parent_id) REFERENCES tags(id) ON DELETE SET NULL,
ADD INDEX idx_parent (parent_id);

-- Fonction pour obtenir tous les tags enfants récursivement
-- (sera implémentée dans le modèle Tag)

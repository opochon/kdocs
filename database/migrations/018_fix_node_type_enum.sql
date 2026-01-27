-- Migration: 018_fix_node_type_enum.sql
-- Convertit node_type de ENUM vers VARCHAR pour plus de flexibilité
-- Date: 2026-01-25

-- Convertir la colonne node_type en VARCHAR
ALTER TABLE workflow_nodes MODIFY COLUMN node_type VARCHAR(64) NOT NULL;

-- Ajouter un index si pas déjà présent
-- ALTER TABLE workflow_nodes ADD INDEX idx_node_type (node_type);

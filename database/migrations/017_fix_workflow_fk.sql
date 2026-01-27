-- Migration: 017_fix_workflow_fk.sql
-- Supprime la contrainte FK problématique sur workflow_definitions.created_by
-- Date: 2026-01-25

-- Supprimer la contrainte FK existante
ALTER TABLE workflow_definitions DROP FOREIGN KEY IF EXISTS fk_workflow_creator;

-- Alternative: recréer sans contrainte stricte (juste l'index)
-- La colonne reste, mais sans FK
ALTER TABLE workflow_definitions MODIFY COLUMN created_by INT DEFAULT NULL;

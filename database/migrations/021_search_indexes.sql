-- Migration: 021_search_indexes.sql
-- Indexes pour améliorer les performances de recherche
-- Date: 2026-01-28

-- Index pour filtres fréquents
-- Utilisation de CREATE INDEX IF NOT EXISTS (MariaDB 10.1.4+)

CREATE INDEX IF NOT EXISTS idx_status ON documents (status);
CREATE INDEX IF NOT EXISTS idx_ocr_status ON documents (ocr_status);
CREATE INDEX IF NOT EXISTS idx_document_date ON documents (document_date);
CREATE INDEX IF NOT EXISTS idx_correspondent ON documents (correspondent_id);
CREATE INDEX IF NOT EXISTS idx_document_type ON documents (document_type_id);
CREATE INDEX IF NOT EXISTS idx_created_by ON documents (created_by);
CREATE INDEX IF NOT EXISTS idx_validation_status ON documents (validation_status);
CREATE INDEX IF NOT EXISTS idx_search_common ON documents (deleted_at, status, created_at);

-- Index FULLTEXT pour recherche texte (optionnel, peut échouer si colonnes trop grandes)
-- ALTER TABLE documents ADD FULLTEXT INDEX idx_fulltext_search (title) IF NOT EXISTS;

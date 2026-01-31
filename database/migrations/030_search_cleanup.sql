-- K-DOCS Migration 030: Nettoyage recherche vectorielle (optionnel)
-- Rend Qdrant/embeddings optionnel, nettoie les tables si inutilisées
-- Date: 2026-01-31

-- ============================================
-- NOTE: Cette migration ne SUPPRIME PAS les tables
-- Elle les rend optionnelles et nettoie les erreurs
-- ============================================

-- ÉTAPE 1: Réinitialiser les statuts d'embedding en erreur
-- (permet de réessayer si Ollama redevient disponible)
UPDATE documents 
SET embedding_status = NULL, embedding_error = NULL 
WHERE embedding_status = 'failed';

-- ÉTAPE 2: Marquer les documents sans embedding comme "pending" (pas "failed")
UPDATE documents 
SET embedding_status = 'pending' 
WHERE embedding_status IS NULL 
AND (ocr_text IS NOT NULL OR content IS NOT NULL);

-- ÉTAPE 3: Créer une vue pour la recherche hybride (FULLTEXT + optionnel vector)
DROP VIEW IF EXISTS v_searchable_documents;
CREATE VIEW v_searchable_documents AS
SELECT 
    d.id,
    d.title,
    d.original_filename,
    d.filename,
    d.ocr_text,
    d.content,
    d.mime_type,
    d.file_size,
    d.correspondent_id,
    d.document_type_id,
    d.document_date,
    d.created_at,
    d.updated_at,
    d.deleted_at,
    d.status,
    d.validation_status,
    d.embedding_status,
    c.name AS correspondent_name,
    dt.label AS document_type_label
FROM documents d
LEFT JOIN correspondents c ON d.correspondent_id = c.id
LEFT JOIN document_types dt ON d.document_type_id = dt.id
WHERE d.deleted_at IS NULL;

-- ÉTAPE 4: Ajouter un index pour la performance de la vue
CREATE INDEX IF NOT EXISTS idx_documents_search_perf 
ON documents (deleted_at, created_at DESC, id);

-- ============================================
-- TABLES EMBEDDING - ON LES GARDE (optionnelles)
-- ============================================

-- embedding_logs: Garde pour audit
-- Si la table n'existe pas, pas grave (optionnel)

-- ============================================
-- CONFIGURATION
-- ============================================

-- Insérer setting pour activer/désactiver la recherche vectorielle
INSERT INTO settings (`key`, `value`, `type`, `description`) 
VALUES ('search_vector_enabled', '0', 'boolean', 'Activer la recherche vectorielle (nécessite Qdrant)')
ON DUPLICATE KEY UPDATE `value` = '0';

INSERT INTO settings (`key`, `value`, `type`, `description`) 
VALUES ('search_provider', 'mysql_fulltext', 'string', 'Provider de recherche: mysql_fulltext, qdrant_vector, hybrid')
ON DUPLICATE KEY UPDATE `value` = 'mysql_fulltext';

-- ============================================
-- VÉRIFICATION
-- ============================================

-- Tester la vue
-- SELECT * FROM v_searchable_documents LIMIT 5;

-- Vérifier les settings
-- SELECT * FROM settings WHERE `key` LIKE 'search%';

-- ============================================================================
-- Migration: 028_fulltext_search.sql
-- Date: 2026-01-31
-- Objectif: Implémenter recherche FULLTEXT native MySQL (remplace Qdrant)
-- ============================================================================

-- NOTE: Cette migration est idempotente (peut être rejouée sans erreur)

-- ============================================================================
-- 1. PRÉPARATION: S'assurer que les colonnes existent et sont compatibles
-- ============================================================================

-- Vérifier/modifier le type des colonnes pour FULLTEXT
-- FULLTEXT requiert des colonnes CHAR, VARCHAR, ou TEXT

-- title: déjà VARCHAR(255) - OK
-- ocr_text: doit être TEXT ou LONGTEXT
-- content: doit être TEXT ou LONGTEXT

-- ============================================================================
-- 2. SUPPRESSION DES ANCIENS INDEX FULLTEXT (si existent)
-- ============================================================================

-- MySQL n'a pas DROP INDEX IF EXISTS, on utilise une procédure
DELIMITER //
CREATE PROCEDURE drop_index_if_exists(IN table_name VARCHAR(64), IN index_name VARCHAR(64))
BEGIN
    DECLARE index_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO index_exists 
    FROM information_schema.statistics 
    WHERE table_schema = DATABASE() 
      AND table_name = table_name 
      AND index_name = index_name;
    
    IF index_exists > 0 THEN
        SET @sql = CONCAT('DROP INDEX ', index_name, ' ON ', table_name);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- Supprimer anciens index
CALL drop_index_if_exists('documents', 'idx_ft_documents');
CALL drop_index_if_exists('documents', 'idx_fulltext_search');
CALL drop_index_if_exists('correspondents', 'idx_ft_correspondents');
CALL drop_index_if_exists('tags', 'idx_ft_tags');

-- Nettoyer la procédure
DROP PROCEDURE IF EXISTS drop_index_if_exists;

-- ============================================================================
-- 3. CRÉATION DES INDEX FULLTEXT
-- ============================================================================

-- Index principal sur documents (titre + contenu OCR + contenu extrait)
ALTER TABLE documents 
ADD FULLTEXT INDEX idx_ft_documents (title, ocr_text, content);

-- Index sur correspondants (pour recherche par nom)
ALTER TABLE correspondents
ADD FULLTEXT INDEX idx_ft_correspondents (name);

-- Index sur tags (pour recherche par nom de tag)
ALTER TABLE tags
ADD FULLTEXT INDEX idx_ft_tags (name);

-- ============================================================================
-- 4. INDEX SUPPLÉMENTAIRES POUR PERFORMANCE
-- ============================================================================

-- Index sur deleted_at pour filtrer rapidement les documents actifs
CREATE INDEX idx_documents_deleted_at ON documents(deleted_at);

-- Index sur status pour filtrer par statut
CREATE INDEX idx_documents_status ON documents(status);

-- Index sur created_at pour tri chronologique
CREATE INDEX idx_documents_created_at ON documents(created_at);

-- Index composite pour les requêtes fréquentes
CREATE INDEX idx_documents_type_status ON documents(document_type_id, status);
CREATE INDEX idx_documents_correspondent_status ON documents(correspondent_id, status);

-- ============================================================================
-- 5. VÉRIFICATION
-- ============================================================================

-- Cette requête doit retourner les index FULLTEXT créés
-- SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME 
-- FROM information_schema.statistics 
-- WHERE table_schema = DATABASE() AND index_type = 'FULLTEXT';

-- ============================================================================
-- FIN MIGRATION
-- ============================================================================

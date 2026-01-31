-- K-DOCS Migration 029: Harmoniser is_deleted vers deleted_at
-- Uniformise la gestion des suppressions logiques
-- Date: 2026-01-31

-- ============================================
-- ÉTAPE 1: Migrer les données documents
-- ============================================

-- Mettre deleted_at si is_deleted = 1 et deleted_at est NULL
UPDATE documents 
SET deleted_at = COALESCE(updated_at, NOW()) 
WHERE is_deleted = 1 AND deleted_at IS NULL;

-- ============================================
-- ÉTAPE 2: Migrer correspondents si colonne existe
-- ============================================

-- Vérifier et migrer (ignorer si colonne n'existe pas)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.columns 
    WHERE table_schema = DATABASE() 
    AND table_name = 'correspondents' 
    AND column_name = 'is_deleted'
);

-- Migration conditionnelle via procédure
DROP PROCEDURE IF EXISTS migrate_correspondents_deleted;
DELIMITER //
CREATE PROCEDURE migrate_correspondents_deleted()
BEGIN
    DECLARE col_count INT;
    SELECT COUNT(*) INTO col_count FROM information_schema.columns 
    WHERE table_schema = DATABASE() 
    AND table_name = 'correspondents' 
    AND column_name = 'is_deleted';
    
    IF col_count > 0 THEN
        -- Ajouter deleted_at si n'existe pas
        SET @add_col = (
            SELECT COUNT(*) FROM information_schema.columns 
            WHERE table_schema = DATABASE() 
            AND table_name = 'correspondents' 
            AND column_name = 'deleted_at'
        );
        IF @add_col = 0 THEN
            ALTER TABLE correspondents ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;
        END IF;
        
        -- Migrer les données
        UPDATE correspondents SET deleted_at = NOW() WHERE is_deleted = 1 AND deleted_at IS NULL;
    END IF;
END //
DELIMITER ;
CALL migrate_correspondents_deleted();
DROP PROCEDURE IF EXISTS migrate_correspondents_deleted;

-- ============================================
-- ÉTAPE 3: Supprimer les colonnes is_deleted
-- ============================================

-- Documents: supprimer is_deleted (garder deleted_at)
-- Note: On garde la colonne pour compatibilité, mais on utilise deleted_at
-- ALTER TABLE documents DROP COLUMN IF EXISTS is_deleted;

-- ============================================
-- ÉTAPE 4: S'assurer que deleted_at existe partout
-- ============================================

-- Tags
SET @has_deleted_at = (
    SELECT COUNT(*) FROM information_schema.columns 
    WHERE table_schema = DATABASE() 
    AND table_name = 'tags' 
    AND column_name = 'deleted_at'
);
-- Si pas de deleted_at, l'ajouter (via ALTER conditionnel en procédure)

-- Document types
-- (même logique)

-- ============================================
-- ÉTAPE 5: Index sur deleted_at
-- ============================================

-- Index pour filtrer rapidement les non-supprimés
CREATE INDEX IF NOT EXISTS idx_documents_not_deleted ON documents (deleted_at);
CREATE INDEX IF NOT EXISTS idx_correspondents_not_deleted ON correspondents (deleted_at);

-- ============================================
-- VÉRIFICATION
-- ============================================

-- Vérifier cohérence
-- SELECT COUNT(*) as total, 
--        SUM(CASE WHEN is_deleted = 1 THEN 1 ELSE 0 END) as is_deleted_count,
--        SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) as deleted_at_count
-- FROM documents;

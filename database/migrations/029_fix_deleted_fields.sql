-- ============================================================================
-- Migration: 029_fix_deleted_fields.sql
-- Date: 2026-01-31
-- Objectif: Harmoniser is_deleted et deleted_at dans la table documents
-- ============================================================================

-- ============================================================================
-- 1. DIAGNOSTIC (à exécuter manuellement pour voir l'état)
-- ============================================================================

-- Compter les incohérences avant correction:
-- SELECT 
--     SUM(CASE WHEN is_deleted = 1 AND deleted_at IS NULL THEN 1 ELSE 0 END) as deleted_without_date,
--     SUM(CASE WHEN is_deleted = 0 AND deleted_at IS NOT NULL THEN 1 ELSE 0 END) as date_without_flag,
--     SUM(CASE WHEN is_deleted = 1 AND deleted_at IS NOT NULL THEN 1 ELSE 0 END) as correctly_deleted,
--     SUM(CASE WHEN is_deleted = 0 AND deleted_at IS NULL THEN 1 ELSE 0 END) as correctly_active
-- FROM documents;

-- ============================================================================
-- 2. CORRECTION DES INCOHÉRENCES
-- ============================================================================

-- Cas 1: is_deleted = 1 mais deleted_at est NULL
-- → Mettre deleted_at à la date de modification (ou maintenant)
UPDATE documents 
SET deleted_at = COALESCE(updated_at, NOW())
WHERE is_deleted = 1 AND deleted_at IS NULL;

-- Cas 2: deleted_at est défini mais is_deleted = 0
-- → Mettre is_deleted à 1
UPDATE documents 
SET is_deleted = 1 
WHERE deleted_at IS NOT NULL AND is_deleted = 0;

-- ============================================================================
-- 3. VÉRIFICATION POST-MIGRATION
-- ============================================================================

-- Cette requête doit retourner 0:
-- SELECT COUNT(*) as inconsistencies
-- FROM documents 
-- WHERE (is_deleted = 1 AND deleted_at IS NULL) 
--    OR (is_deleted = 0 AND deleted_at IS NOT NULL);

-- ============================================================================
-- 4. STANDARDISATION: deleted_at EST LA SOURCE DE VÉRITÉ
-- ============================================================================

-- Convention adoptée:
-- - deleted_at IS NULL → document actif
-- - deleted_at IS NOT NULL → document supprimé (soft delete)
-- - is_deleted est conservé pour compatibilité mais deleted_at fait foi

-- Les requêtes doivent utiliser: WHERE deleted_at IS NULL
-- Pas: WHERE is_deleted = 0

-- ============================================================================
-- 5. OPTIONNEL: TRIGGER POUR MAINTENIR LA COHÉRENCE
-- ============================================================================

-- Décommenter si vous voulez un trigger automatique:

-- DROP TRIGGER IF EXISTS trg_documents_sync_deleted;

-- DELIMITER //
-- CREATE TRIGGER trg_documents_sync_deleted
-- BEFORE UPDATE ON documents
-- FOR EACH ROW
-- BEGIN
--     -- Si deleted_at change, synchroniser is_deleted
--     IF NEW.deleted_at IS NOT NULL AND OLD.deleted_at IS NULL THEN
--         SET NEW.is_deleted = 1;
--     ELSEIF NEW.deleted_at IS NULL AND OLD.deleted_at IS NOT NULL THEN
--         SET NEW.is_deleted = 0;
--     END IF;
--     
--     -- Si is_deleted change, synchroniser deleted_at
--     IF NEW.is_deleted = 1 AND OLD.is_deleted = 0 AND NEW.deleted_at IS NULL THEN
--         SET NEW.deleted_at = NOW();
--     ELSEIF NEW.is_deleted = 0 AND OLD.is_deleted = 1 THEN
--         SET NEW.deleted_at = NULL;
--     END IF;
-- END //
-- DELIMITER ;

-- ============================================================================
-- FIN MIGRATION
-- ============================================================================

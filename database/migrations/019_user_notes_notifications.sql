-- Migration: 019_user_notes_notifications.sql
-- Système de notes inter-utilisateurs et améliorations notifications
-- Date: 2026-01-25

-- ============================================
-- 1. TABLE NOTES INTER-UTILISATEURS
-- ============================================

CREATE TABLE IF NOT EXISTS `user_notes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `document_id` INT NULL,
    `from_user_id` INT NOT NULL,
    `to_user_id` INT NOT NULL,
    `subject` VARCHAR(255) NULL,
    `message` TEXT NOT NULL,
    `parent_note_id` INT NULL,
    `is_read` BOOLEAN DEFAULT FALSE,
    `action_required` BOOLEAN DEFAULT FALSE,
    `action_type` VARCHAR(50) NULL,
    `action_completed_at` DATETIME NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_notes_to` (`to_user_id`, `is_read`),
    INDEX `idx_user_notes_from` (`from_user_id`),
    INDEX `idx_user_notes_document` (`document_id`),
    INDEX `idx_user_notes_parent` (`parent_note_id`),
    INDEX `idx_user_notes_action` (`to_user_id`, `action_required`, `action_completed_at`)
);

-- ============================================
-- 2. COLONNES SUPPLÉMENTAIRES SUR NOTIFICATIONS
-- ============================================

-- Ajouter les colonnes en ignorant les erreurs si elles existent déjà
-- MariaDB/MySQL ne supporte pas IF NOT EXISTS pour ADD COLUMN directement,
-- donc on utilise des procédures ou on ignore les erreurs

-- Note: Exécuter ces commandes séparément ou utiliser un script PHP pour les conditions

-- Si les colonnes n'existent pas, les ajouter manuellement:
-- ALTER TABLE `notifications` ADD COLUMN `document_id` INT NULL;
-- ALTER TABLE `notifications` ADD COLUMN `related_user_id` INT NULL;
-- ALTER TABLE `notifications` ADD COLUMN `priority` ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal';
-- ALTER TABLE `notifications` ADD COLUMN `action_url` VARCHAR(255) NULL;

-- Procédure pour ajouter les colonnes de manière sécurisée
DELIMITER //

DROP PROCEDURE IF EXISTS AddNotificationColumns//

CREATE PROCEDURE AddNotificationColumns()
BEGIN
    -- Ajouter document_id si non existant
    IF NOT EXISTS (
        SELECT * FROM INFORMATION_SCHEMA.COLUMNS
        WHERE table_schema = DATABASE()
        AND table_name = 'notifications'
        AND column_name = 'document_id'
    ) THEN
        ALTER TABLE `notifications` ADD COLUMN `document_id` INT NULL;
    END IF;

    -- Ajouter related_user_id si non existant
    IF NOT EXISTS (
        SELECT * FROM INFORMATION_SCHEMA.COLUMNS
        WHERE table_schema = DATABASE()
        AND table_name = 'notifications'
        AND column_name = 'related_user_id'
    ) THEN
        ALTER TABLE `notifications` ADD COLUMN `related_user_id` INT NULL;
    END IF;

    -- Ajouter priority si non existant
    IF NOT EXISTS (
        SELECT * FROM INFORMATION_SCHEMA.COLUMNS
        WHERE table_schema = DATABASE()
        AND table_name = 'notifications'
        AND column_name = 'priority'
    ) THEN
        ALTER TABLE `notifications` ADD COLUMN `priority` ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal';
    END IF;

    -- Ajouter action_url si non existant
    IF NOT EXISTS (
        SELECT * FROM INFORMATION_SCHEMA.COLUMNS
        WHERE table_schema = DATABASE()
        AND table_name = 'notifications'
        AND column_name = 'action_url'
    ) THEN
        ALTER TABLE `notifications` ADD COLUMN `action_url` VARCHAR(255) NULL;
    END IF;
END//

DELIMITER ;

-- Exécuter la procédure
CALL AddNotificationColumns();

-- Supprimer la procédure
DROP PROCEDURE IF EXISTS AddNotificationColumns;

-- Index supplémentaires pour notifications (ignorés si existent)
CREATE INDEX IF NOT EXISTS idx_notifications_document ON notifications(document_id);
CREATE INDEX IF NOT EXISTS idx_notifications_priority ON notifications(user_id, priority, is_read);
CREATE INDEX IF NOT EXISTS idx_notifications_unread ON notifications(user_id, is_read, created_at);

-- ============================================
-- 3. VUE POUR LES TÂCHES UNIFIÉES
-- ============================================

DROP VIEW IF EXISTS v_user_tasks;

CREATE VIEW v_user_tasks AS
-- Documents à valider
SELECT
    CONCAT('validation_', d.id) as task_id,
    'validation' as task_type,
    d.id as document_id,
    NULL as note_id,
    NULL as workflow_task_id,
    CONCAT('Valider: ', COALESCE(d.title, d.original_filename)) as title,
    CONCAT('Document en attente de validation depuis le ', DATE_FORMAT(d.created_at, '%d/%m/%Y')) as description,
    'pending' as status,
    d.approval_deadline as deadline,
    d.created_at as created_at,
    d.created_by as assigned_to,
    CASE
        WHEN d.approval_deadline < NOW() THEN 'urgent'
        WHEN d.approval_deadline < DATE_ADD(NOW(), INTERVAL 24 HOUR) THEN 'high'
        ELSE 'normal'
    END as priority
FROM documents d
WHERE d.requires_approval = TRUE
  AND d.validation_status = 'pending'
  AND d.deleted_at IS NULL

UNION ALL

-- Notes avec action requise
SELECT
    CONCAT('note_', un.id) as task_id,
    'note' as task_type,
    un.document_id,
    un.id as note_id,
    NULL as workflow_task_id,
    COALESCE(un.subject, CONCAT('Note de ', (SELECT username FROM users WHERE id = un.from_user_id))) as title,
    un.message as description,
    CASE WHEN un.action_completed_at IS NOT NULL THEN 'completed' ELSE 'pending' END as status,
    NULL as deadline,
    un.created_at,
    un.to_user_id as assigned_to,
    'normal' as priority
FROM user_notes un
WHERE un.action_required = TRUE;

-- ============================================
-- 4. TYPES DE NOTIFICATIONS
-- ============================================

CREATE TABLE IF NOT EXISTS `notification_types` (
    `code` VARCHAR(50) PRIMARY KEY,
    `label` VARCHAR(100) NOT NULL,
    `description` TEXT,
    `icon` VARCHAR(50) DEFAULT 'bell',
    `default_priority` ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Insérer les types de base
INSERT IGNORE INTO notification_types (code, label, description, icon, default_priority) VALUES
('validation_pending', 'Document a valider', 'Un document necessite votre validation', 'clipboard-check', 'high'),
('validation_approved', 'Document approuve', 'Votre document a ete approuve', 'check-circle', 'normal'),
('validation_rejected', 'Document rejete', 'Votre document a ete rejete', 'x-circle', 'high'),
('note_received', 'Note recue', 'Vous avez recu une note', 'mail', 'normal'),
('note_action_required', 'Action requise', 'Une note necessite une action de votre part', 'exclamation-circle', 'high'),
('task_assigned', 'Tache assignee', 'Une tache vous a ete assignee', 'clipboard-list', 'normal'),
('task_completed', 'Tache terminee', 'Une tache a ete completee', 'check', 'low'),
('document_shared', 'Document partage', 'Un document a ete partage avec vous', 'share', 'normal'),
('workflow_step', 'Etape de workflow', 'Une etape de workflow necessite votre attention', 'cog', 'normal'),
('system', 'Systeme', 'Notification systeme', 'information-circle', 'low');

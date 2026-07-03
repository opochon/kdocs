<?php
/**
 * Migration PHP : table folder_permissions (GAP-040 — ACL document fine).
 *
 * Crée la table d'ACL par dossier logique si elle n'existe pas déjà (idempotente).
 * Ne PAS exécuter automatiquement — appeler manuellement :
 *   php database/migrations/add_folder_permissions_table.php
 *
 * Colonnes :
 *  - folder_id    : ID du dossier logique (logical_folders.id)
 *  - subject_type : 'user' | 'group'
 *  - subject_id   : ID utilisateur ou groupe
 *  - can_read / can_write / can_delete : droits booléens
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Core/Config.php';

use KDocs\Core\Database;

echo "Migration: Création de la table folder_permissions (GAP-040)...\n\n";

try {
    $db = Database::getInstance();

    $db->exec("CREATE TABLE IF NOT EXISTS `folder_permissions` (
        `id`           INT          NOT NULL AUTO_INCREMENT,
        `folder_id`    INT          NOT NULL COMMENT 'ID dossier logique (logical_folders.id)',
        `subject_type` VARCHAR(10)  NOT NULL COMMENT 'user | group',
        `subject_id`   INT          NOT NULL COMMENT 'ID utilisateur ou groupe',
        `can_read`     TINYINT(1)   NOT NULL DEFAULT 0,
        `can_write`    TINYINT(1)   NOT NULL DEFAULT 0,
        `can_delete`   TINYINT(1)   NOT NULL DEFAULT 0,
        `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        INDEX `idx_folder_subject` (`folder_id`, `subject_type`, `subject_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT='ACL par dossier logique — GAP-040'");

    echo "   OK Table folder_permissions creee (ou deja existante)\n\n";
    echo "OK Migration terminee avec succes!\n";
} catch (\Exception $e) {
    echo "ERREUR de migration: " . $e->getMessage() . "\n";
    exit(1);
}

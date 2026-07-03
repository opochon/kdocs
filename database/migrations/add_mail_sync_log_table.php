<?php
/**
 * Migration idempotente : table mail_sync_log pour déduplication IMAP (GAP-034).
 *
 * La contrainte UNIQUE(account_id, message_uid) garantit qu'un message
 * importé une fois ne sera jamais réimporté, même si la boîte est re-scannée.
 *
 * NE PAS EXÉCUTER directement — fichier de référence pour les déploiements.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Core/Config.php';

use KDocs\Core\Database;

echo "Migration: Création de la table mail_sync_log (GAP-034)...\n\n";

try {
    $db = Database::getInstance();

    // Vérifier si la table existe déjà
    try {
        $db->query("SELECT 1 FROM mail_sync_log LIMIT 1");
        echo "   ℹ️  Table mail_sync_log existe déjà\n";
    } catch (\PDOException $e) {
        $db->exec("
            CREATE TABLE mail_sync_log (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                account_id  INT          NOT NULL COMMENT 'ID dans mail_accounts',
                message_uid VARCHAR(255) NOT NULL COMMENT 'UID IMAP du message',
                document_id INT          NULL     COMMENT 'ID du document créé dans documents',
                synced_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_account_uid (account_id, message_uid),
                INDEX idx_account_id (account_id),
                INDEX idx_document_id (document_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Déduplication IMAP : trace des UIDs déjà importés (GAP-034)'
        ");
        echo "   ✅ Table mail_sync_log créée\n";
    }

    echo "\n✅ Migration terminée avec succès!\n";
} catch (\Exception $e) {
    echo "❌ Erreur de migration: " . $e->getMessage() . "\n";
    exit(1);
}

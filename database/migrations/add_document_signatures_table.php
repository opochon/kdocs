<?php
/**
 * Migration idempotente : table document_signatures pour e-signature (GAP-043).
 *
 * La contrainte UNIQUE(document_id, user_id) rend le scellement idempotent :
 * un utilisateur ne peut signer un document qu'une fois.
 *
 * NE PAS EXÉCUTER directement — fichier de référence pour les déploiements.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Core/Config.php';

use KDocs\Core\Database;

echo "Migration: Création de la table document_signatures (GAP-043)...\n\n";

try {
    $db = Database::getInstance();

    // Vérifier si la table existe déjà
    try {
        $db->query("SELECT 1 FROM document_signatures LIMIT 1");
        echo "   ℹ️  Table document_signatures existe déjà\n";
    } catch (\PDOException $e) {
        $db->exec("
            CREATE TABLE document_signatures (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                document_id  INT          NOT NULL COMMENT 'ID dans documents',
                user_id      INT          NOT NULL COMMENT 'ID dans users',
                content_hash VARCHAR(64)  NOT NULL COMMENT 'SHA-256 du contenu signé (title+content+id)',
                signature    TEXT         NOT NULL COMMENT 'HMAC-SHA256 encodé en hex',
                signed_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_doc_user (document_id, user_id),
                INDEX idx_document_id (document_id),
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Signatures électroniques des documents (GAP-043)'
        ");
        echo "   ✅ Table document_signatures créée\n";
    }

    echo "\n✅ Migration terminée avec succès!\n";
} catch (\Exception $e) {
    echo "❌ Erreur de migration: " . $e->getMessage() . "\n";
    exit(1);
}

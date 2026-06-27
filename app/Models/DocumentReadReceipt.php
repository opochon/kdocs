<?php
/**
 * K-Docs - Modèle DocumentReadReceipt (quittances de lecture SMQ / C.3)
 * Accusé de lecture d'une version de document par un utilisateur.
 */

namespace KDocs\Models;

use KDocs\Core\Database;

class DocumentReadReceipt
{
    /**
     * Enregistre une quittance (idempotent : conserve la première lecture).
     */
    public static function record(int $documentId, int $versionNumber, int $userId): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            "INSERT INTO document_read_receipts (document_id, version_number, user_id)
             VALUES (:doc, :ver, :user)
             ON DUPLICATE KEY UPDATE read_at = read_at"
        );
        $stmt->execute(['doc' => $documentId, 'ver' => $versionNumber, 'user' => $userId]);
    }

    public static function hasRead(int $documentId, int $versionNumber, int $userId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT 1 FROM document_read_receipts
             WHERE document_id = :doc AND version_number = :ver AND user_id = :user LIMIT 1"
        );
        $stmt->execute(['doc' => $documentId, 'ver' => $versionNumber, 'user' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function readAt(int $documentId, int $versionNumber, int $userId): ?string
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT read_at FROM document_read_receipts
             WHERE document_id = :doc AND version_number = :ver AND user_id = :user LIMIT 1"
        );
        $stmt->execute(['doc' => $documentId, 'ver' => $versionNumber, 'user' => $userId]);
        $value = $stmt->fetchColumn();
        return $value !== false ? (string) $value : null;
    }

    /**
     * Lecteurs d'une version : [{user_id, username, read_at}], du plus récent au plus ancien.
     */
    public static function readersForVersion(int $documentId, int $versionNumber): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT r.user_id, r.read_at, u.username
             FROM document_read_receipts r
             LEFT JOIN users u ON u.id = r.user_id
             WHERE r.document_id = :doc AND r.version_number = :ver
             ORDER BY r.read_at DESC"
        );
        $stmt->execute(['doc' => $documentId, 'ver' => $versionNumber]);
        return $stmt->fetchAll();
    }
}

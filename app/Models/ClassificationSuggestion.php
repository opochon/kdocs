<?php
/**
 * K-Docs - Modèle Classification Suggestion
 * Suggestions de classification ML/AI en attente d'approbation
 */

namespace KDocs\Models;

use KDocs\Core\Database;
use PDO;

class ClassificationSuggestion
{
    /**
     * Récupère toutes les suggestions pendantes
     */
    public static function getPending(int $limit = 100): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT cs.*, d.title as document_title
            FROM classification_suggestions cs
            LEFT JOIN documents d ON cs.document_id = d.id
            WHERE cs.status = 'pending'
            ORDER BY cs.confidence DESC, cs.created_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Récupère les suggestions pour un document
     */
    public static function getForDocument(int $documentId, string $status = 'pending'): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT * FROM classification_suggestions
            WHERE document_id = ? AND status = ?
            ORDER BY confidence DESC
        ");
        $stmt->execute([$documentId, $status]);
        return $stmt->fetchAll();
    }

    /**
     * Récupère une suggestion par ID
     */
    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM classification_suggestions WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Crée une nouvelle suggestion
     */
    public static function create(array $data): int
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            INSERT INTO classification_suggestions
            (document_id, field_code, suggested_value, confidence, source, similar_documents)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['document_id'],
            $data['field_code'],
            $data['suggested_value'],
            $data['confidence'],
            $data['source'] ?? 'ml',
            isset($data['similar_documents']) ? json_encode($data['similar_documents']) : null
        ]);

        return (int)$db->lastInsertId();
    }

    /**
     * Marque une suggestion comme appliquée
     */
    public static function apply(int $id, int $userId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            UPDATE classification_suggestions
            SET status = 'applied', applied_at = NOW(), applied_by = ?
            WHERE id = ?
        ");
        return $stmt->execute([$userId, $id]);
    }

    /**
     * Marque une suggestion comme ignorée
     */
    public static function ignore(int $id): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            UPDATE classification_suggestions
            SET status = 'ignored'
            WHERE id = ?
        ");
        return $stmt->execute([$id]);
    }

    /**
     * Supprime les suggestions d'un document
     */
    public static function deleteForDocument(int $documentId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM classification_suggestions WHERE document_id = ?");
        return $stmt->execute([$documentId]);
    }

    /**
     * Supprime les suggestions pendantes pour un champ donné d'un document
     */
    public static function deletePendingForField(int $documentId, string $fieldCode): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            DELETE FROM classification_suggestions
            WHERE document_id = ? AND field_code = ? AND status = 'pending'
        ");
        return $stmt->execute([$documentId, $fieldCode]);
    }

    /**
     * Compte les suggestions pendantes
     */
    public static function countPending(): int
    {
        $db = Database::getInstance();
        return (int)$db->query("SELECT COUNT(*) FROM classification_suggestions WHERE status = 'pending'")->fetchColumn();
    }

    /**
     * Compte les suggestions pendantes pour un document
     */
    public static function countPendingForDocument(int $documentId): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT COUNT(*) FROM classification_suggestions WHERE document_id = ? AND status = 'pending'");
        $stmt->execute([$documentId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Récupère les statistiques des suggestions
     */
    public static function getStats(): array
    {
        $db = Database::getInstance();

        $stats = [
            'pending' => 0,
            'applied' => 0,
            'ignored' => 0,
            'by_source' => [],
            'by_field' => []
        ];

        // Par statut
        $result = $db->query("
            SELECT status, COUNT(*) as count
            FROM classification_suggestions
            GROUP BY status
        ")->fetchAll();

        foreach ($result as $row) {
            $stats[$row['status']] = (int)$row['count'];
        }

        // Par source
        $result = $db->query("
            SELECT source, COUNT(*) as count
            FROM classification_suggestions
            GROUP BY source
        ")->fetchAll();

        foreach ($result as $row) {
            $stats['by_source'][$row['source']] = (int)$row['count'];
        }

        // Par champ
        $result = $db->query("
            SELECT field_code, COUNT(*) as count
            FROM classification_suggestions
            WHERE status = 'pending'
            GROUP BY field_code
        ")->fetchAll();

        foreach ($result as $row) {
            $stats['by_field'][$row['field_code']] = (int)$row['count'];
        }

        return $stats;
    }
}

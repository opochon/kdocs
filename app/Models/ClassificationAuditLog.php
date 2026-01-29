<?php
/**
 * K-Docs - Modèle Classification Audit Log
 * Journal d'audit des modifications de classification
 */

namespace KDocs\Models;

use KDocs\Core\Database;
use PDO;

class ClassificationAuditLog
{
    /**
     * Récupère l'historique d'un document
     */
    public static function getForDocument(int $documentId, int $limit = 100): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT cal.*,
                   u.username as user_name,
                   ar.name as rule_name
            FROM classification_audit_log cal
            LEFT JOIN users u ON cal.user_id = u.id
            LEFT JOIN attribution_rules ar ON cal.rule_id = ar.id
            WHERE cal.document_id = ?
            ORDER BY cal.created_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $documentId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Récupère l'historique global avec pagination
     */
    public static function getAll(int $page = 1, int $perPage = 50, array $filters = []): array
    {
        $db = Database::getInstance();
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        if (!empty($filters['document_id'])) {
            $where[] = "cal.document_id = ?";
            $params[] = $filters['document_id'];
        }

        if (!empty($filters['field_code'])) {
            $where[] = "cal.field_code = ?";
            $params[] = $filters['field_code'];
        }

        if (!empty($filters['change_source'])) {
            $where[] = "cal.change_source = ?";
            $params[] = $filters['change_source'];
        }

        if (!empty($filters['user_id'])) {
            $where[] = "cal.user_id = ?";
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = "cal.created_at >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = "cal.created_at <= ?";
            $params[] = $filters['date_to'];
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "
            SELECT cal.*,
                   d.title as document_title,
                   u.username as user_name,
                   ar.name as rule_name
            FROM classification_audit_log cal
            LEFT JOIN documents d ON cal.document_id = d.id
            LEFT JOIN users u ON cal.user_id = u.id
            LEFT JOIN attribution_rules ar ON cal.rule_id = ar.id
            $whereClause
            ORDER BY cal.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $params[] = $perPage;
        $params[] = $offset;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Compte le nombre total d'entrées (avec filtres)
     */
    public static function count(array $filters = []): int
    {
        $db = Database::getInstance();

        $where = [];
        $params = [];

        if (!empty($filters['document_id'])) {
            $where[] = "document_id = ?";
            $params[] = $filters['document_id'];
        }

        if (!empty($filters['field_code'])) {
            $where[] = "field_code = ?";
            $params[] = $filters['field_code'];
        }

        if (!empty($filters['change_source'])) {
            $where[] = "change_source = ?";
            $params[] = $filters['change_source'];
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $db->prepare("SELECT COUNT(*) FROM classification_audit_log $whereClause");
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Enregistre une modification
     */
    public static function log(array $data): int
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            INSERT INTO classification_audit_log
            (document_id, field_code, old_value, new_value, change_source,
             change_reason, rule_id, suggestion_id, user_id, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['document_id'],
            $data['field_code'],
            $data['old_value'] ?? null,
            $data['new_value'] ?? null,
            $data['change_source'],
            $data['change_reason'] ?? null,
            $data['rule_id'] ?? null,
            $data['suggestion_id'] ?? null,
            $data['user_id'] ?? null,
            $data['ip_address'] ?? null
        ]);

        return (int)$db->lastInsertId();
    }

    /**
     * Récupère une entrée par ID
     */
    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT cal.*,
                   d.title as document_title,
                   u.username as user_name
            FROM classification_audit_log cal
            LEFT JOIN documents d ON cal.document_id = d.id
            LEFT JOIN users u ON cal.user_id = u.id
            WHERE cal.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Récupère les statistiques d'audit
     */
    public static function getStats(int $days = 30): array
    {
        $db = Database::getInstance();

        $stats = [
            'total' => 0,
            'by_source' => [],
            'by_field' => [],
            'by_day' => [],
            'top_users' => []
        ];

        // Total sur la période
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM classification_audit_log
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$days]);
        $stats['total'] = (int)$stmt->fetchColumn();

        // Par source
        $stmt = $db->prepare("
            SELECT change_source, COUNT(*) as count
            FROM classification_audit_log
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY change_source
            ORDER BY count DESC
        ");
        $stmt->execute([$days]);
        foreach ($stmt->fetchAll() as $row) {
            $stats['by_source'][$row['change_source']] = (int)$row['count'];
        }

        // Par champ
        $stmt = $db->prepare("
            SELECT field_code, COUNT(*) as count
            FROM classification_audit_log
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY field_code
            ORDER BY count DESC
        ");
        $stmt->execute([$days]);
        foreach ($stmt->fetchAll() as $row) {
            $stats['by_field'][$row['field_code']] = (int)$row['count'];
        }

        // Par jour (pour graphique)
        $stmt = $db->prepare("
            SELECT DATE(created_at) as date, COUNT(*) as count
            FROM classification_audit_log
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(created_at)
            ORDER BY date
        ");
        $stmt->execute([$days]);
        foreach ($stmt->fetchAll() as $row) {
            $stats['by_day'][$row['date']] = (int)$row['count'];
        }

        // Top utilisateurs
        $stmt = $db->prepare("
            SELECT u.username, COUNT(*) as count
            FROM classification_audit_log cal
            JOIN users u ON cal.user_id = u.id
            WHERE cal.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY cal.user_id
            ORDER BY count DESC
            LIMIT 10
        ");
        $stmt->execute([$days]);
        $stats['top_users'] = $stmt->fetchAll();

        return $stats;
    }

    /**
     * Exporte l'historique au format CSV
     */
    public static function exportCsv(array $filters = []): string
    {
        $data = self::getAll(1, 10000, $filters);

        $csv = "ID,Document ID,Document Title,Field,Old Value,New Value,Source,Reason,User,Date\n";

        foreach ($data as $row) {
            $csv .= sprintf(
                "%d,%d,\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\"\n",
                $row['id'],
                $row['document_id'],
                str_replace('"', '""', $row['document_title'] ?? ''),
                $row['field_code'],
                str_replace('"', '""', $row['old_value'] ?? ''),
                str_replace('"', '""', $row['new_value'] ?? ''),
                $row['change_source'],
                str_replace('"', '""', $row['change_reason'] ?? ''),
                $row['user_name'] ?? 'Système',
                $row['created_at']
            );
        }

        return $csv;
    }
}

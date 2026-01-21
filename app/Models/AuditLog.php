<?php
/**
 * K-Docs - Modèle AuditLog
 */

namespace KDocs\Models;

use KDocs\Core\Database;
use PDO;

class AuditLog
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Enregistre une action dans l'audit log
     */
    public static function log(
        string $action,
        string $objectType,
        ?int $objectId = null,
        ?string $objectName = null,
        ?array $changes = null,
        ?int $userId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): int {
        $db = Database::getInstance();
        
        $stmt = $db->prepare("
            INSERT INTO audit_logs 
            (user_id, action, object_type, object_id, object_name, changes, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $userId,
            $action,
            $objectType,
            $objectId,
            $objectName,
            $changes ? json_encode($changes) : null,
            $ipAddress,
            $userAgent
        ]);
        
        return (int)$db->lastInsertId();
    }

    /**
     * Récupère les logs avec filtres
     */
    public function getLogs(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $where = [];
        $params = [];
        
        if (!empty($filters['user_id'])) {
            $where[] = 'al.user_id = ?';
            $params[] = (int)$filters['user_id'];
        }
        
        if (!empty($filters['action'])) {
            $where[] = 'al.action = ?';
            $params[] = $filters['action'];
        }
        
        if (!empty($filters['object_type'])) {
            $where[] = 'al.object_type = ?';
            $params[] = $filters['object_type'];
        }
        
        if (!empty($filters['object_id'])) {
            $where[] = 'al.object_id = ?';
            $params[] = (int)$filters['object_id'];
        }
        
        if (!empty($filters['date_from'])) {
            $where[] = 'al.created_at >= ?';
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = 'al.created_at <= ?';
            $params[] = $filters['date_to'];
        }
        
        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $offset = ($page - 1) * $perPage;
        
        $sql = "
            SELECT al.*, u.username as user_username
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            $whereClause
            ORDER BY al.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $bindIndex = 1;
        foreach ($params as $value) {
            $stmt->bindValue($bindIndex++, $value);
        }
        $stmt->bindValue($bindIndex++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($bindIndex++, $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Décoder les changements JSON
        foreach ($logs as &$log) {
            if ($log['changes']) {
                $log['changes'] = json_decode($log['changes'], true);
            }
        }
        
        return $logs;
    }

    /**
     * Compte le total de logs avec filtres
     */
    public function countLogs(array $filters = []): int
    {
        $where = [];
        $params = [];
        
        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = ?';
            $params[] = (int)$filters['user_id'];
        }
        
        if (!empty($filters['action'])) {
            $where[] = 'action = ?';
            $params[] = $filters['action'];
        }
        
        if (!empty($filters['object_type'])) {
            $where[] = 'object_type = ?';
            $params[] = $filters['object_type'];
        }
        
        if (!empty($filters['object_id'])) {
            $where[] = 'object_id = ?';
            $params[] = (int)$filters['object_id'];
        }
        
        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= ?';
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= ?';
            $params[] = $filters['date_to'];
        }
        
        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $sql = "SELECT COUNT(*) FROM audit_logs $whereClause";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return (int)$stmt->fetchColumn();
    }

    /**
     * Récupère les statistiques d'audit
     */
    public function getStats(int $days = 30): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                action,
                COUNT(*) as count
            FROM audit_logs
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY action
            ORDER BY count DESC
        ");
        $stmt->execute([$days]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

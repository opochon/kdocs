<?php
/**
 * K-Docs - Modèle Task
 */

namespace KDocs\Models;

use KDocs\Core\Database;
use PDO;

class Task
{
    /**
     * Récupère toutes les tâches avec pagination
     */
    public static function getAll(int $limit = 20, int $offset = 0, ?int $userId = null, ?string $status = null): array
    {
        $db = Database::getInstance();
        
        $where = [];
        $params = [];
        $paramIndex = 1;
        
        if ($userId) {
            $where[] = "t.assigned_to = ?";
            $params[] = $userId;
        }
        
        if ($status) {
            $where[] = "t.status = ?";
            $params[] = $status;
        }
        
        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $sql = "
            SELECT t.*, 
                   d.title as document_title,
                   d.original_filename as document_filename,
                   u2.username as assigned_to_username
            FROM tasks t
            LEFT JOIN workflow_instances wi ON t.workflow_instance_id = wi.id
            LEFT JOIN documents d ON wi.document_id = d.id
            LEFT JOIN users u2 ON t.assigned_to = u2.id
            $whereClause
            ORDER BY t.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $db->prepare($sql);
        foreach ($params as $value) {
            $stmt->bindValue($paramIndex++, $value);
        }
        $stmt->bindValue($paramIndex++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }

    /**
     * Compte le nombre total de tâches
     */
    public static function count(?int $userId = null, ?string $status = null): int
    {
        $db = Database::getInstance();
        
        $where = [];
        $params = [];
        $paramIndex = 1;
        
        if ($userId) {
            $where[] = "assigned_to = ?";
            $params[] = $userId;
        }
        
        if ($status) {
            $where[] = "status = ?";
            $params[] = $status;
        }
        
        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $sql = "SELECT COUNT(*) FROM tasks $whereClause";
        $stmt = $db->prepare($sql);
        foreach ($params as $value) {
            $stmt->bindValue($paramIndex++, $value);
        }
        $stmt->execute();
        
        return (int)$stmt->fetchColumn();
    }

    /**
     * Récupère une tâche par ID
     */
    public static function findById(int $id): ?array
    {
        $db = Database::getInstance();
        
        $stmt = $db->prepare("
            SELECT t.*, 
                   d.title as document_title,
                   d.original_filename as document_filename,
                   u2.username as assigned_to_username
            FROM tasks t
            LEFT JOIN workflow_instances wi ON t.workflow_instance_id = wi.id
            LEFT JOIN documents d ON wi.document_id = d.id
            LEFT JOIN users u2 ON t.assigned_to = u2.id
            WHERE t.id = ?
        ");
        
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        
        return $result ?: null;
    }

    /**
     * Crée une nouvelle tâche
     */
    public static function create(array $data): int
    {
        $db = Database::getInstance();
        
        // Pour simplifier, créons une tâche basique sans workflow_instance pour l'instant
        // TODO: Implémenter la création de workflow_instance si nécessaire
        $stmt = $db->prepare("
            INSERT INTO tasks (
                workflow_instance_id, step_id, assigned_to, due_date, status
            ) VALUES (
                :workflow_instance_id, :step_id, :assigned_to, :due_date, :status
            )
        ");
        
        // Créer un workflow_instance minimal si document_id fourni
        $workflowInstanceId = null;
        if (!empty($data['document_id'])) {
            // Pour l'instant, on ne crée pas de workflow_instance
            // On utilisera une valeur par défaut ou NULL
        }
        
        $stmt->execute([
            'workflow_instance_id' => $workflowInstanceId ?? 1, // Valeur temporaire
            'step_id' => 1, // Valeur temporaire
            'assigned_to' => $data['assigned_to'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'status' => 'pending',
        ]);
        
        return (int)$db->lastInsertId();
    }

    /**
     * Met à jour le statut d'une tâche
     */
    public static function updateStatus(int $id, string $status, ?int $userId = null): bool
    {
        $db = Database::getInstance();
        
        $sql = "UPDATE tasks SET status = ?, updated_at = NOW()";
        $params = [$status];
        
        if ($userId) {
            $sql .= ", completed_by = ?";
            $params[] = $userId;
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $id;
        
        $stmt = $db->prepare($sql);
        return $stmt->execute($params);
    }
}

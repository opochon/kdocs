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
     *
     * Supporte deux profils :
     *  - tâche autonome (UI /tasks/create) : title, description, priority, document_id,
     *    workflow_type_id, assigned_to, due_date, created_by. Aucun workflow_instance.
     *  - tâche de workflow (programmatique) : workflow_instance_id + step_id fournis.
     */
    public static function create(array $data): int
    {
        $db = Database::getInstance();

        $hasWorkflow = !empty($data['workflow_instance_id']);
        $workflowInstanceId = $hasWorkflow ? (int)$data['workflow_instance_id'] : null;
        $stepId = $hasWorkflow ? (int)($data['step_id'] ?? 1) : null;

        $stmt = $db->prepare("
            INSERT INTO tasks (
                workflow_instance_id, step_id, assigned_to,
                title, description, priority,
                document_id, workflow_type_id, created_by,
                due_date, status
            ) VALUES (
                :workflow_instance_id, :step_id, :assigned_to,
                :title, :description, :priority,
                :document_id, :workflow_type_id, :created_by,
                :due_date, :status
            )
        ");

        $stmt->execute([
            'workflow_instance_id' => $workflowInstanceId,
            'step_id'              => $stepId,
            'assigned_to'          => $data['assigned_to'] ?? null,
            'title'                => $data['title'] ?? null,
            'description'          => $data['description'] ?? null,
            'priority'             => $data['priority'] ?? 'medium',
            'document_id'          => !empty($data['document_id']) ? (int)$data['document_id'] : null,
            'workflow_type_id'     => !empty($data['workflow_type_id']) ? (int)$data['workflow_type_id'] : null,
            'created_by'           => !empty($data['created_by']) ? (int)$data['created_by'] : null,
            'due_date'             => $data['due_date'] ?? null,
            'status'               => $data['status'] ?? 'pending',
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

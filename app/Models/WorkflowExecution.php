<?php
/**
 * K-Docs - Modèle WorkflowExecution
 */

namespace KDocs\Models;

use KDocs\Core\Database;

class WorkflowExecution
{
    public static function findById(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM workflow_executions WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        if ($result && isset($result['context'])) {
            $result['context'] = json_decode($result['context'], true) ?: [];
        }
        return $result ?: null;
    }
    
    public static function findByWorkflow(int $workflowId, ?string $status = null): array
    {
        $db = Database::getInstance();
        $sql = "SELECT * FROM workflow_executions WHERE workflow_id = ?";
        $params = [$workflowId];
        
        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY started_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll() ?: [];
        foreach ($results as &$result) {
            if (isset($result['context'])) {
                $result['context'] = json_decode($result['context'], true) ?: [];
            }
        }
        return $results;
    }
    
    public static function findByStatus(string $status): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM workflow_executions WHERE status = ? ORDER BY started_at");
        $stmt->execute([$status]);
        $results = $stmt->fetchAll() ?: [];
        foreach ($results as &$result) {
            if (isset($result['context'])) {
                $result['context'] = json_decode($result['context'], true) ?: [];
            }
        }
        return $results;
    }
    
    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO workflow_executions 
            (workflow_id, document_id, status, current_node_id, context, waiting_until, waiting_for)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['workflow_id'],
            $data['document_id'] ?? null,
            $data['status'] ?? 'pending',
            $data['current_node_id'] ?? null,
            json_encode($data['context'] ?? []),
            $data['waiting_until'] ?? null,
            $data['waiting_for'] ?? null,
        ]);
        return (int)$db->lastInsertId();
    }
    
    public static function update(int $id, array $data): bool
    {
        $db = Database::getInstance();
        $fields = [];
        $values = [];
        
        if (isset($data['status'])) {
            $fields[] = "status = ?";
            $values[] = $data['status'];
        }
        if (isset($data['current_node_id'])) {
            $fields[] = "current_node_id = ?";
            $values[] = $data['current_node_id'];
        }
        if (isset($data['context'])) {
            $fields[] = "context = ?";
            $values[] = json_encode($data['context']);
        }
        if (isset($data['error_message'])) {
            $fields[] = "error_message = ?";
            $values[] = $data['error_message'];
        }
        if (isset($data['completed_at'])) {
            $fields[] = "completed_at = ?";
            $values[] = $data['completed_at'];
        }
        if (isset($data['waiting_until'])) {
            $fields[] = "waiting_until = ?";
            $values[] = $data['waiting_until'];
        }
        if (isset($data['waiting_for'])) {
            $fields[] = "waiting_for = ?";
            $values[] = $data['waiting_for'];
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $values[] = $id;
        $sql = "UPDATE workflow_executions SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        return $stmt->execute($values);
    }
}

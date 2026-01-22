<?php
/**
 * K-Docs - Modèle WorkflowConnection
 */

namespace KDocs\Models;

use KDocs\Core\Database;

class WorkflowConnection
{
    public static function findByWorkflow(int $workflowId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT * FROM workflow_connections 
            WHERE workflow_id = ? 
            ORDER BY `order`
        ");
        $stmt->execute([$workflowId]);
        return $stmt->fetchAll() ?: [];
    }
    
    public static function findByFromNode(int $fromNodeId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM workflow_connections WHERE from_node_id = ? ORDER BY `order`");
        $stmt->execute([$fromNodeId]);
        return $stmt->fetchAll() ?: [];
    }
    
    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO workflow_connections (workflow_id, from_node_id, to_node_id, output_name, label, `order`)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['workflow_id'],
            $data['from_node_id'],
            $data['to_node_id'],
            $data['output_name'] ?? 'default',
            $data['label'] ?? null,
            $data['order'] ?? 0,
        ]);
        return (int)$db->lastInsertId();
    }
    
    public static function delete(int $id): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM workflow_connections WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    public static function deleteByWorkflow(int $workflowId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM workflow_connections WHERE workflow_id = ?");
        return $stmt->execute([$workflowId]);
    }
}

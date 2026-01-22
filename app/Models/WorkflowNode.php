<?php
/**
 * K-Docs - Modèle WorkflowNode
 */

namespace KDocs\Models;

use KDocs\Core\Database;

class WorkflowNode
{
    public static function findById(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM workflow_nodes WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        if ($result && isset($result['config'])) {
            $result['config'] = json_decode($result['config'], true) ?: [];
        }
        return $result ?: null;
    }
    
    public static function findByWorkflow(int $workflowId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM workflow_nodes WHERE workflow_id = ? ORDER BY position_y, position_x");
        $stmt->execute([$workflowId]);
        $results = $stmt->fetchAll() ?: [];
        foreach ($results as &$result) {
            if (isset($result['config'])) {
                $result['config'] = json_decode($result['config'], true) ?: [];
            }
        }
        return $results;
    }
    
    public static function findEntryPoints(int $workflowId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM workflow_nodes WHERE workflow_id = ? AND is_entry_point = 1");
        $stmt->execute([$workflowId]);
        $results = $stmt->fetchAll() ?: [];
        foreach ($results as &$result) {
            if (isset($result['config'])) {
                $result['config'] = json_decode($result['config'], true) ?: [];
            }
        }
        return $results;
    }
    
    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO workflow_nodes (workflow_id, node_type, name, config, position_x, position_y, is_entry_point)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['workflow_id'],
            $data['node_type'],
            $data['name'],
            json_encode($data['config'] ?? []),
            $data['position_x'] ?? 0,
            $data['position_y'] ?? 0,
            $data['is_entry_point'] ?? 0,
        ]);
        return (int)$db->lastInsertId();
    }
    
    public static function update(int $id, array $data): bool
    {
        $db = Database::getInstance();
        $fields = [];
        $values = [];
        
        if (isset($data['name'])) {
            $fields[] = "name = ?";
            $values[] = $data['name'];
        }
        if (isset($data['config'])) {
            $fields[] = "config = ?";
            $values[] = json_encode($data['config']);
        }
        if (isset($data['position_x'])) {
            $fields[] = "position_x = ?";
            $values[] = $data['position_x'];
        }
        if (isset($data['position_y'])) {
            $fields[] = "position_y = ?";
            $values[] = $data['position_y'];
        }
        if (isset($data['is_entry_point'])) {
            $fields[] = "is_entry_point = ?";
            $values[] = $data['is_entry_point'];
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $values[] = $id;
        $sql = "UPDATE workflow_nodes SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        return $stmt->execute($values);
    }
    
    public static function delete(int $id): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM workflow_nodes WHERE id = ?");
        return $stmt->execute([$id]);
    }
}

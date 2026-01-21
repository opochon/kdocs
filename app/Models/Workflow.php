<?php
/**
 * K-Docs - Modèle Workflow (Phase 3.3)
 */

namespace KDocs\Models;

use KDocs\Core\Database;

class Workflow
{
    public static function all(): array
    {
        $db = Database::getInstance();
        return $db->query("SELECT * FROM workflows ORDER BY order_index, name")->fetchAll();
    }
    
    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM workflows WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO workflows (name, enabled, order_index)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([
            $data['name'],
            $data['enabled'] ?? true,
            $data['order_index'] ?? 0
        ]);
        return $db->lastInsertId();
    }
    
    public static function update(int $id, array $data): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            UPDATE workflows 
            SET name = ?, enabled = ?, order_index = ?
            WHERE id = ?
        ");
        return $stmt->execute([
            $data['name'],
            $data['enabled'] ?? true,
            $data['order_index'] ?? 0,
            $id
        ]);
    }
    
    public static function delete(int $id): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM workflows WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    public static function getTriggers(int $workflowId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM workflow_triggers WHERE workflow_id = ? ORDER BY id");
        $stmt->execute([$workflowId]);
        return $stmt->fetchAll();
    }
    
    public static function getActions(int $workflowId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM workflow_actions WHERE workflow_id = ? ORDER BY order_index");
        $stmt->execute([$workflowId]);
        return $stmt->fetchAll();
    }
    
    public static function addTrigger(int $workflowId, array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO workflow_triggers (workflow_id, trigger_type, condition_type, condition_value)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $workflowId,
            $data['trigger_type'],
            $data['condition_type'] ?? 'always',
            $data['condition_value'] ?? null
        ]);
        return $db->lastInsertId();
    }
    
    public static function addAction(int $workflowId, array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO workflow_actions (workflow_id, action_type, action_value, order_index)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $workflowId,
            $data['action_type'],
            $data['action_value'],
            $data['order_index'] ?? 0
        ]);
        return $db->lastInsertId();
    }
    
    public static function logExecution(int $workflowId, int $documentId, string $status, ?string $message = null): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO workflow_logs (workflow_id, document_id, status, message)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$workflowId, $documentId, $status, $message]);
    }
}

<?php
/**
 * K-Docs - Modèle WorkflowDefinition
 */

namespace KDocs\Models;

use KDocs\Core\Database;

class WorkflowDefinition
{
    public static function findById(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM workflow_definitions WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }
    
    public static function findAll(bool $enabledOnly = false): array
    {
        $db = Database::getInstance();
        $sql = "SELECT * FROM workflow_definitions";
        if ($enabledOnly) {
            $sql .= " WHERE enabled = 1";
        }
        $sql .= " ORDER BY name";
        $stmt = $db->query($sql);
        return $stmt->fetchAll() ?: [];
    }
    
    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO workflow_definitions (name, description, enabled, version, canvas_data, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            ($data['enabled'] ?? true) ? 1 : 0, // Convertir bool en int
            $data['version'] ?? 1,
            isset($data['canvas_data']) ? json_encode($data['canvas_data']) : null,
            $data['created_by'] ?? null,
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
        if (isset($data['description'])) {
            $fields[] = "description = ?";
            $values[] = $data['description'];
        }
        if (isset($data['enabled'])) {
            $fields[] = "enabled = ?";
            $values[] = $data['enabled'];
        }
        if (isset($data['version'])) {
            $fields[] = "version = ?";
            $values[] = $data['version'];
        }
        if (isset($data['canvas_data'])) {
            $fields[] = "canvas_data = ?";
            $values[] = json_encode($data['canvas_data']);
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $values[] = $id;
        $sql = "UPDATE workflow_definitions SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        return $stmt->execute($values);
    }
    
    public static function delete(int $id): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM workflow_definitions WHERE id = ?");
        return $stmt->execute([$id]);
    }
}

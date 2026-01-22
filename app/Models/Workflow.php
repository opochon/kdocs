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
        
        // Construire la requête avec tous les champs possibles
        $fields = ['workflow_id', 'trigger_type'];
        $values = [$workflowId, $data['trigger_type']];
        $placeholders = ['?', '?'];
        
        // Ajouter les champs de filtres s'ils existent
        $filterFields = [
            'filter_filename', 'filter_path', 'filter_has_tags', 'filter_has_all_tags',
            'filter_has_not_tags', 'filter_has_correspondent', 'filter_has_not_correspondents',
            'filter_has_document_type', 'filter_has_not_document_types',
            'filter_has_storage_path', 'filter_has_not_storage_paths',
            'match', 'matching_algorithm', 'is_insensitive',
            'schedule_offset_days', 'schedule_is_recurring', 'schedule_recurring_interval_days',
            'schedule_date_field', 'schedule_date_custom_field'
        ];
        
        foreach ($filterFields as $field) {
            if (isset($data[$field])) {
                $fields[] = $field;
                $placeholders[] = '?';
                
                // Convertir les tableaux en JSON
                if (is_array($data[$field])) {
                    $values[] = json_encode($data[$field]);
                } else {
                    $values[] = $data[$field];
                }
            }
        }
        
        // Garder compatibilité avec anciens champs
        if (isset($data['condition_type'])) {
            $fields[] = 'condition_type';
            $placeholders[] = '?';
            $values[] = $data['condition_type'];
        }
        if (isset($data['condition_value'])) {
            $fields[] = 'condition_value';
            $placeholders[] = '?';
            $values[] = $data['condition_value'];
        }
        
        $sql = "INSERT INTO workflow_triggers (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $db->prepare($sql);
        $stmt->execute($values);
        
        return $db->lastInsertId();
    }
    
    public static function addAction(int $workflowId, array $data): int
    {
        $db = Database::getInstance();
        
        // Construire la requête avec tous les champs possibles
        $fields = ['workflow_id', 'action_type', 'order_index'];
        $values = [$workflowId, (int)$data['action_type'], $data['order_index'] ?? 0];
        $placeholders = ['?', '?', '?'];
        
        // Champs pour Assignment (1)
        $assignmentFields = [
            'assign_title', 'assign_tags', 'assign_document_type', 'assign_correspondent',
            'assign_storage_path', 'assign_custom_fields', 'assign_custom_fields_values',
            'assign_owner', 'assign_view_users', 'assign_view_groups',
            'assign_change_users', 'assign_change_groups'
        ];
        
        // Champs pour Removal (2)
        $removalFields = [
            'remove_tags', 'remove_all_tags', 'remove_correspondents', 'remove_all_correspondents',
            'remove_document_types', 'remove_all_document_types', 'remove_storage_paths',
            'remove_all_storage_paths', 'remove_custom_fields', 'remove_all_custom_fields',
            'remove_owners', 'remove_all_owners', 'remove_view_users', 'remove_view_groups',
            'remove_change_users', 'remove_change_groups', 'remove_all_permissions'
        ];
        
        // Champs pour Email (3)
        $emailFields = ['email_subject', 'email_body', 'email_to', 'email_include_document'];
        
        // Champs pour Webhook (4)
        $webhookFields = [
            'webhook_url', 'webhook_use_params', 'webhook_as_json', 'webhook_params',
            'webhook_body', 'webhook_headers', 'webhook_include_document'
        ];
        
        $allFields = array_merge($assignmentFields, $removalFields, $emailFields, $webhookFields);
        
        foreach ($allFields as $field) {
            if (isset($data[$field])) {
                $fields[] = $field;
                $placeholders[] = '?';
                
                // Convertir les tableaux en JSON
                if (is_array($data[$field])) {
                    $values[] = json_encode($data[$field]);
                } elseif (is_bool($data[$field])) {
                    $values[] = $data[$field] ? 1 : 0;
                } else {
                    $values[] = $data[$field];
                }
            }
        }
        
        $sql = "INSERT INTO workflow_actions (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $db->prepare($sql);
        $stmt->execute($values);
        
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

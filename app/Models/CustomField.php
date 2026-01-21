<?php
/**
 * K-Docs - Modèle CustomField
 * Champs personnalisés pour documents
 */

namespace KDocs\Models;

use KDocs\Core\Database;

class CustomField
{
    public static function all(): array
    {
        $db = Database::getInstance();
        return $db->query("SELECT * FROM custom_fields ORDER BY name")->fetchAll();
    }
    
    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM custom_fields WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO custom_fields (name, field_type, options, required)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['name'],
            $data['field_type'] ?? 'text',
            $data['options'] ?? null,
            $data['required'] ?? false
        ]);
        return $db->lastInsertId();
    }
    
    public static function update(int $id, array $data): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            UPDATE custom_fields 
            SET name = ?, field_type = ?, options = ?, required = ?
            WHERE id = ?
        ");
        return $stmt->execute([
            $data['name'],
            $data['field_type'] ?? 'text',
            $data['options'] ?? null,
            $data['required'] ?? false,
            $id
        ]);
    }
    
    public static function delete(int $id): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM custom_fields WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    public static function getValuesForDocument(int $documentId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT cfv.*, cf.name as field_name, cf.field_type
            FROM document_custom_field_values cfv
            INNER JOIN custom_fields cf ON cfv.custom_field_id = cf.id
            WHERE cfv.document_id = ?
        ");
        $stmt->execute([$documentId]);
        return $stmt->fetchAll();
    }
    
    public static function setValue(int $documentId, int $fieldId, $value): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO document_custom_field_values (document_id, custom_field_id, value)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE value = ?
        ");
        return $stmt->execute([$documentId, $fieldId, $value, $value]);
    }
    
    public static function deleteValue(int $documentId, int $fieldId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM document_custom_field_values WHERE document_id = ? AND custom_field_id = ?");
        return $stmt->execute([$documentId, $fieldId]);
    }
}

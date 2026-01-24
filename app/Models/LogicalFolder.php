<?php
namespace KDocs\Models;
use KDocs\Core\Database;
use PDO;

class LogicalFolder
{
    public static function getAll(): array
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM logical_folders ORDER BY sort_order ASC, name ASC");
        return $stmt->fetchAll();
    }
    
    public static function findById(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM logical_folders WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    public static function getDocuments(int $folderId, int $limit = 20, int $offset = 0): array
    {
        $folder = self::findById($folderId);
        if (!$folder) return [];
        
        $db = Database::getInstance();
        $filterConfig = json_decode($folder['filter_config'] ?? '{}', true);
        $where = [];
        $params = [];
        $paramIndex = 1;
        
        // Toujours exclure les documents supprimés et en attente de validation
        $where[] = "d.deleted_at IS NULL";
        $where[] = "(d.status IS NULL OR d.status != 'pending')";
        
        switch ($folder['filter_type']) {
            case 'filesystem':
                // Tous les documents - pas de filtre supplémentaire
                break;
            case 'document_type':
                if (!empty($filterConfig['document_type_id'])) {
                    $where[] = "d.document_type_id = ?";
                    $params[] = $filterConfig['document_type_id'];
                } elseif (!empty($filterConfig['document_type_code'])) {
                    // Chercher par code
                    $typeStmt = $db->prepare("SELECT id FROM document_types WHERE code = ? LIMIT 1");
                    $typeStmt->execute([$filterConfig['document_type_code']]);
                    if ($type = $typeStmt->fetch()) {
                        $where[] = "d.document_type_id = ?";
                        $params[] = $type['id'];
                    }
                }
                break;
            case 'correspondent':
                if (!empty($filterConfig['correspondent_id'])) {
                    $where[] = "d.correspondent_id = ?";
                    $params[] = $filterConfig['correspondent_id'];
                }
                break;
            case 'tag':
                if (!empty($filterConfig['tag_id'])) {
                    $where[] = "EXISTS (SELECT 1 FROM document_tags dt WHERE dt.document_id = d.id AND dt.tag_id = ?)";
                    $params[] = $filterConfig['tag_id'];
                }
                break;
        }
        
        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT d.*, dt.label as document_type_label, c.name as correspondent_name FROM documents d LEFT JOIN document_types dt ON d.document_type_id = dt.id LEFT JOIN correspondents c ON d.correspondent_id = c.id $whereClause ORDER BY d.created_at DESC LIMIT ? OFFSET ?";
        $stmt = $db->prepare($sql);
        foreach ($params as $value) {
            $stmt->bindValue($paramIndex++, $value);
        }
        $stmt->bindValue($paramIndex++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public static function countDocuments(int $folderId): int
    {
        $folder = self::findById($folderId);
        if (!$folder) return 0;
        
        $db = Database::getInstance();
        $filterConfig = json_decode($folder['filter_config'] ?? '{}', true);
        $where = [];
        $params = [];
        $paramIndex = 1;
        
        // Toujours exclure les documents supprimés et en attente de validation
        $where[] = "d.deleted_at IS NULL";
        $where[] = "(d.status IS NULL OR d.status != 'pending')";
        
        switch ($folder['filter_type']) {
            case 'filesystem':
                // Tous les documents - pas de filtre supplémentaire
                break;
            case 'document_type':
                if (!empty($filterConfig['document_type_id'])) {
                    $where[] = "d.document_type_id = ?";
                    $params[] = $filterConfig['document_type_id'];
                } elseif (!empty($filterConfig['document_type_code'])) {
                    // Chercher par code
                    $typeStmt = $db->prepare("SELECT id FROM document_types WHERE code = ? LIMIT 1");
                    $typeStmt->execute([$filterConfig['document_type_code']]);
                    if ($type = $typeStmt->fetch()) {
                        $where[] = "d.document_type_id = ?";
                        $params[] = $type['id'];
                    }
                }
                break;
            case 'correspondent':
                if (!empty($filterConfig['correspondent_id'])) {
                    $where[] = "d.correspondent_id = ?";
                    $params[] = $filterConfig['correspondent_id'];
                }
                break;
            case 'tag':
                if (!empty($filterConfig['tag_id'])) {
                    $where[] = "EXISTS (SELECT 1 FROM document_tags dt WHERE dt.document_id = d.id AND dt.tag_id = ?)";
                    $params[] = $filterConfig['tag_id'];
                }
                break;
        }
        
        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT COUNT(*) FROM documents d $whereClause";
        $stmt = $db->prepare($sql);
        foreach ($params as $value) {
            $stmt->bindValue($paramIndex++, $value);
        }
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }
}
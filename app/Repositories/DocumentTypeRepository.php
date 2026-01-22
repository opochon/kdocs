<?php
/**
 * K-Docs - DocumentTypeRepository
 * Repository pour l'accès aux données document types
 */

namespace KDocs\Repositories;

use KDocs\Core\Database;
use PDO;

class DocumentTypeRepository
{
    private Database $db;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    public function findAll(): array
    {
        $stmt = $this->db->query(
            "SELECT dt.*, COUNT(d.id) as document_count
             FROM document_types dt
             LEFT JOIN documents d ON dt.id = d.document_type_id AND d.deleted_at IS NULL
             GROUP BY dt.id
             ORDER BY dt.label"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT dt.*, COUNT(d.id) as document_count
             FROM document_types dt
             LEFT JOIN documents d ON dt.id = d.document_type_id AND d.deleted_at IS NULL
             WHERE dt.id = :id
             GROUP BY dt.id"
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO document_types (label, `match`, matching_algorithm, is_insensitive, owner_id)
             VALUES (:label, :match, :matching_algorithm, :is_insensitive, :owner_id)"
        );
        $stmt->execute([
            'label' => $data['label'] ?? $data['name'],
            'match' => $data['match'] ?? '',
            'matching_algorithm' => $data['matching_algorithm'] ?? 1,
            'is_insensitive' => ($data['is_insensitive'] ?? true) ? 1 : 0,
            'owner_id' => $data['owner_id'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }
    
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];
        $allowed = ['label', 'match', 'matching_algorithm', 'is_insensitive'];
        
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $dbField = $field === 'match' ? '`match`' : $field;
                $fields[] = "{$dbField} = :{$field}";
                $params[$field] = $data[$field];
            }
        }
        
        if (empty($fields)) return false;
        
        $sql = "UPDATE document_types SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM document_types WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }
    
    public function search(string $query, int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM document_types WHERE label LIKE :query ORDER BY label LIMIT :limit"
        );
        $stmt->bindValue(':query', "%{$query}%");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function findAllWithMatch(): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM document_types WHERE `match` IS NOT NULL AND `match` != '' ORDER BY label"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

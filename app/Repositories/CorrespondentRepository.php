<?php
/**
 * K-Docs - CorrespondentRepository
 * Repository pour l'accès aux données correspondents
 */

namespace KDocs\Repositories;

use KDocs\Core\Database;
use PDO;

class CorrespondentRepository
{
    private Database $db;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    public function findAll(): array
    {
        $stmt = $this->db->query(
            "SELECT c.*, COUNT(d.id) as document_count
             FROM correspondents c
             LEFT JOIN documents d ON c.id = d.correspondent_id AND d.deleted_at IS NULL
             GROUP BY c.id
             ORDER BY c.name"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, COUNT(d.id) as document_count
             FROM correspondents c
             LEFT JOIN documents d ON c.id = d.correspondent_id AND d.deleted_at IS NULL
             WHERE c.id = :id
             GROUP BY c.id"
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    public function create(array $data): int
    {
        $slug = $data['slug'] ?? $this->generateSlug($data['name']);
        $stmt = $this->db->prepare(
            "INSERT INTO correspondents (name, slug, `match`, matching_algorithm, is_insensitive, owner_id)
             VALUES (:name, :slug, :match, :matching_algorithm, :is_insensitive, :owner_id)"
        );
        $stmt->execute([
            'name' => $data['name'],
            'slug' => $slug,
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
        $allowed = ['name', 'slug', 'match', 'matching_algorithm', 'is_insensitive'];
        
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $dbField = $field === 'match' ? '`match`' : $field;
                $fields[] = "{$dbField} = :{$field}";
                $params[$field] = $data[$field];
            }
        }
        
        if (empty($fields)) return false;
        
        $sql = "UPDATE correspondents SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM correspondents WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }
    
    public function search(string $query, int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM correspondents WHERE name LIKE :query ORDER BY name LIMIT :limit"
        );
        $stmt->bindValue(':query', "%{$query}%");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getMostUsed(int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, COUNT(d.id) as document_count
             FROM correspondents c
             LEFT JOIN documents d ON c.id = d.correspondent_id AND d.deleted_at IS NULL
             GROUP BY c.id
             ORDER BY document_count DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function findAllWithMatch(): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM correspondents WHERE `match` IS NOT NULL AND `match` != '' ORDER BY name"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function generateSlug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }
}

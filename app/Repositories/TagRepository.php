<?php
/**
 * K-Docs - TagRepository
 * Repository pour l'accès aux données tags
 */

namespace KDocs\Repositories;

use KDocs\Core\Database;
use PDO;

class TagRepository
{
    private Database $db;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    public function findAll(): array
    {
        $stmt = $this->db->query(
            "SELECT t.*, COUNT(dt.document_id) as document_count
             FROM tags t
             LEFT JOIN document_tags dt ON t.id = dt.tag_id
             LEFT JOIN documents d ON dt.document_id = d.id AND d.deleted_at IS NULL
             GROUP BY t.id
             ORDER BY t.name"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT t.*, COUNT(dt.document_id) as document_count
             FROM tags t
             LEFT JOIN document_tags dt ON t.id = dt.tag_id
             LEFT JOIN documents d ON dt.document_id = d.id AND d.deleted_at IS NULL
             WHERE t.id = :id
             GROUP BY t.id"
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    public function create(array $data): int
    {
        $slug = $data['slug'] ?? $this->generateSlug($data['name']);
        $stmt = $this->db->prepare(
            "INSERT INTO tags (name, slug, color, `match`, matching_algorithm, is_insensitive, is_inbox_tag, parent_id, owner_id)
             VALUES (:name, :slug, :color, :match, :matching_algorithm, :is_insensitive, :is_inbox_tag, :parent_id, :owner_id)"
        );
        $stmt->execute([
            'name' => $data['name'],
            'slug' => $slug,
            'color' => $data['color'] ?? '#a6cee3',
            'match' => $data['match'] ?? '',
            'matching_algorithm' => $data['matching_algorithm'] ?? 1,
            'is_insensitive' => ($data['is_insensitive'] ?? true) ? 1 : 0,
            'is_inbox_tag' => ($data['is_inbox_tag'] ?? false) ? 1 : 0,
            'parent_id' => $data['parent_id'] ?? null,
            'owner_id' => $data['owner_id'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }
    
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];
        $allowed = ['name', 'slug', 'color', 'match', 'matching_algorithm', 'is_insensitive', 'is_inbox_tag', 'parent_id'];
        
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $dbField = $field === 'match' ? '`match`' : $field;
                $fields[] = "{$dbField} = :{$field}";
                $params[$field] = $data[$field];
            }
        }
        
        if (empty($fields)) return false;
        
        $sql = "UPDATE tags SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM tags WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }
    
    public function getTree(): array
    {
        $all = $this->findAll();
        $indexed = [];
        foreach ($all as $tag) {
            $indexed[$tag['id']] = $tag;
        }
        
        $tree = [];
        foreach ($all as $tag) {
            if (empty($tag['parent_id'])) {
                $tree[] = $this->buildSubtree($tag, $indexed);
            }
        }
        return $tree;
    }
    
    private function buildSubtree(array $tag, array &$indexed): array
    {
        $children = [];
        foreach ($indexed as $t) {
            if ($t['parent_id'] == $tag['id']) {
                $children[] = $this->buildSubtree($t, $indexed);
            }
        }
        $tag['children'] = $children;
        return $tag;
    }
    
    public function search(string $query, int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM tags WHERE name LIKE :query ORDER BY name LIMIT :limit"
        );
        $stmt->bindValue(':query', "%{$query}%");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getInboxTags(): array
    {
        $stmt = $this->db->query("SELECT * FROM tags WHERE is_inbox_tag = 1");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getPopular(int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            "SELECT t.*, COUNT(dt.document_id) as document_count
             FROM tags t
             LEFT JOIN document_tags dt ON t.id = dt.tag_id
             LEFT JOIN documents d ON dt.document_id = d.id AND d.deleted_at IS NULL
             GROUP BY t.id
             ORDER BY document_count DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function generateSlug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }
}

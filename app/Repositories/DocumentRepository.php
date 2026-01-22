<?php
/**
 * K-Docs - DocumentRepository
 * Repository pour l'accès aux données documents
 */

namespace KDocs\Repositories;

use KDocs\Core\Database;
use KDocs\Models\Document;
use PDO;

class DocumentRepository
{
    private Database $db;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    /**
     * Recherche avec filtres et pagination
     */
    public function search(array $filters, int $page = 1, int $perPage = 25): array
    {
        $where = ['d.deleted_at IS NULL'];
        $params = [];
        
        // Filtre recherche texte
        if (!empty($filters['query'])) {
            $where[] = '(d.title LIKE :query OR d.content LIKE :query OR d.ocr_text LIKE :query)';
            $params['query'] = '%' . $filters['query'] . '%';
        }
        
        // Filtre correspondant
        if (!empty($filters['correspondent_id'])) {
            $where[] = 'd.correspondent_id = :correspondent_id';
            $params['correspondent_id'] = $filters['correspondent_id'];
        }
        
        // Filtre type de document
        if (!empty($filters['document_type_id'])) {
            $where[] = 'd.document_type_id = :document_type_id';
            $params['document_type_id'] = $filters['document_type_id'];
        }
        
        // Filtre date depuis
        if (!empty($filters['date_from'])) {
            $where[] = 'd.created_at >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        
        // Filtre date jusqu'à
        if (!empty($filters['date_to'])) {
            $where[] = 'd.created_at <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }
        
        // Filtre documents supprimés
        if (!empty($filters['is_deleted'])) {
            $where = ['d.deleted_at IS NOT NULL'];
        }
        
        // Filtre par tags
        if (!empty($filters['tags']) && is_array($filters['tags'])) {
            $tagPlaceholders = [];
            foreach ($filters['tags'] as $i => $tagId) {
                $key = 'tag_' . $i;
                $tagPlaceholders[] = ':' . $key;
                $params[$key] = $tagId;
            }
            $where[] = 'd.id IN (SELECT document_id FROM document_tags WHERE tag_id IN (' . implode(',', $tagPlaceholders) . '))';
        }
        
        $whereClause = implode(' AND ', $where);
        
        // Tri
        $sortField = $filters['sort'] ?? 'created_at';
        $sortOrder = strtoupper($filters['order'] ?? 'DESC');
        if (!in_array($sortField, ['created_at', 'added_at', 'title', 'archive_serial_number'])) {
            $sortField = 'created_at';
        }
        if (!in_array($sortOrder, ['ASC', 'DESC'])) {
            $sortOrder = 'DESC';
        }
        $orderBy = "d.{$sortField} {$sortOrder}";
        
        // Compter le total
        $countSql = "SELECT COUNT(*) FROM documents d WHERE {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        
        // Pagination
        $offset = ($page - 1) * $perPage;
        
        // Récupérer les documents
        $sql = "SELECT d.*,
                       c.name as correspondent_name,
                       dt.label as document_type_name
                FROM documents d
                LEFT JOIN correspondents c ON d.correspondent_id = c.id
                LEFT JOIN document_types dt ON d.document_type_id = dt.id
                WHERE {$whereClause}
                ORDER BY {$orderBy}
                LIMIT :limit OFFSET :offset";
        
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $documents = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $documents[] = $row;
        }
        
        return [
            'documents' => $documents,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage)
        ];
    }
    
    /**
     * Trouve un document par son ID
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT d.*,
                   c.name as correspondent_name,
                   dt.label as document_type_name
             FROM documents d
             LEFT JOIN correspondents c ON d.correspondent_id = c.id
             LEFT JOIN document_types dt ON d.document_type_id = dt.id
             WHERE d.id = :id
        ");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Trouve un document par son checksum
     */
    public function findByChecksum(string $checksum): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM documents WHERE checksum = :checksum');
        $stmt->execute(['checksum' => $checksum]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Récupère les documents récents
     */
    public function getRecent(int $limit = 10): array
    {
        $stmt = $this->db->prepare("
            SELECT d.*,
                   c.name as correspondent_name,
                   dt.label as document_type_name
             FROM documents d
             LEFT JOIN correspondents c ON d.correspondent_id = c.id
             LEFT JOIN document_types dt ON d.document_type_id = dt.id
             WHERE d.deleted_at IS NULL
             ORDER BY d.created_at DESC
             LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Récupère les tags d'un document
     */
    public function getDocumentTags(int $documentId): array
    {
        $stmt = $this->db->prepare("
            SELECT t.id, t.name, t.color
             FROM tags t
             INNER JOIN document_tags dt ON t.id = dt.tag_id
             WHERE dt.document_id = :document_id
        ");
        $stmt->execute(['document_id' => $documentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Crée un nouveau document
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO documents
             (title, mime_type, checksum, filename, original_filename, owner_id, created_at, created_by)
             VALUES
             (:title, :mime_type, :checksum, :filename, :original_filename, :owner_id, :created_at, :created_by)
        ");
        
        $stmt->execute([
            'title' => $data['title'] ?? '',
            'mime_type' => $data['mime_type'],
            'checksum' => $data['checksum'],
            'filename' => $data['filename'],
            'original_filename' => $data['original_filename'],
            'owner_id' => $data['owner_id'] ?? null,
            'created_at' => $data['created_at'] ?? date('Y-m-d H:i:s'),
            'created_by' => $data['created_by'] ?? null,
        ]);
        
        return (int) $this->db->lastInsertId();
    }
    
    /**
     * Met à jour un document
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];
        
        $allowedFields = [
            'title', 'content', 'ocr_text', 'correspondent_id', 'document_type_id',
            'storage_path_id', 'asn', 'created_at', 'amount', 'file_path'
        ];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $sql = "UPDATE documents SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * Soft delete
     */
    public function softDelete(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE documents SET deleted_at = NOW() WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }
    
    /**
     * Restaure un document
     */
    public function restore(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE documents SET deleted_at = NULL WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }
    
    /**
     * Suppression définitive
     */
    public function forceDelete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM documents WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }
    
    /**
     * Met à jour les tags d'un document
     */
    public function syncTags(int $documentId, array $tagIds): void
    {
        // Supprimer les tags existants
        $stmt = $this->db->prepare('DELETE FROM document_tags WHERE document_id = :document_id');
        $stmt->execute(['document_id' => $documentId]);
        
        // Ajouter les nouveaux tags
        if (!empty($tagIds)) {
            $stmt = $this->db->prepare(
                'INSERT INTO document_tags (document_id, tag_id) VALUES (:document_id, :tag_id)'
            );
            foreach ($tagIds as $tagId) {
                $stmt->execute(['document_id' => $documentId, 'tag_id' => $tagId]);
            }
        }
    }
    
    /**
     * Get document tag IDs only
     */
    public function getDocumentTagIds(int $documentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT tag_id FROM document_tags WHERE document_id = :document_id"
        );
        $stmt->execute(['document_id' => $documentId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    /**
     * Add a single tag to a document
     */
    public function addTag(int $documentId, int $tagId): void
    {
        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (:document_id, :tag_id)"
        );
        $stmt->execute(['document_id' => $documentId, 'tag_id' => $tagId]);
    }
    
    /**
     * Remove a single tag from a document
     */
    public function removeTag(int $documentId, int $tagId): void
    {
        $stmt = $this->db->prepare(
            "DELETE FROM document_tags WHERE document_id = :document_id AND tag_id = :tag_id"
        );
        $stmt->execute(['document_id' => $documentId, 'tag_id' => $tagId]);
    }
}

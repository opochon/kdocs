<?php
/**
 * K-Docs - SearchService
 * Service de recherche avancée utilisant SearchQueryBuilder
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Search\SearchQuery;
use KDocs\Search\SearchResult;
use PDO;

class SearchService
{
    private \PDO $db;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    /**
     * Simple full-text search
     */
    public function search(string $query, int $limit = 25): SearchResult
    {
        $searchQuery = new SearchQuery();
        $searchQuery->text = $query;
        $searchQuery->perPage = $limit;
        
        return $this->advancedSearch($searchQuery);
    }
    
    /**
     * Advanced search with filters
     */
    public function advancedSearch(SearchQuery $query): SearchResult
    {
        $startTime = microtime(true);
        $result = new SearchResult();
        $result->query = $query->text;
        $result->page = $query->page;
        $result->perPage = $query->perPage;
        
        try {
            // Build the SQL query
            [$sql, $countSql, $params] = $this->buildSearchSql($query);
            
            // Get total count
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($params);
            $result->total = (int) $countStmt->fetchColumn();
            $result->totalPages = (int) ceil($result->total / $query->perPage);
            
            // Get documents
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $result->documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get facets if requested
            if ($query->withFacets) {
                $result->correspondentFacets = $this->getCorrespondentFacets($query);
                $result->documentTypeFacets = $this->getDocumentTypeFacets($query);
                $result->tagFacets = $this->getTagFacets($query);
                $result->yearFacets = $this->getYearFacets($query);
            }
            
            // Get aggregations if requested
            if ($query->withAggregations) {
                $aggregations = $this->getAggregations($query);
                $result->totalAmount = $aggregations['total_amount'] ?? null;
                $result->avgAmount = $aggregations['avg_amount'] ?? null;
                $result->documentCount = $aggregations['count'] ?? null;
            }
        } catch (\Exception $e) {
            error_log('Search failed: ' . $e->getMessage());
        }
        
        $result->searchTime = microtime(true) - $startTime;
        
        return $result;
    }
    
    /**
     * Get search suggestions (autocomplete)
     */
    public function suggest(string $partial, int $limit = 10): array
    {
        $suggestions = [];
        
        if (strlen($partial) < 2) {
            return $suggestions;
        }
        
        // Search in document titles
        $stmt = $this->db->prepare("
            SELECT DISTINCT title, 'document' as type
            FROM documents
            WHERE title LIKE :query
            AND deleted_at IS NULL
            LIMIT :limit
        ");
        $stmt->bindValue(':query', '%' . $partial . '%');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        foreach ($stmt->fetchAll() as $row) {
            $suggestions[] = [
                'text' => $row['title'],
                'type' => 'document',
            ];
        }
        
        // Search in correspondents
        $stmt = $this->db->prepare("
            SELECT name, 'correspondent' as type
            FROM correspondents
            WHERE name LIKE :query
            LIMIT :limit
        ");
        $stmt->bindValue(':query', '%' . $partial . '%');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        foreach ($stmt->fetchAll() as $row) {
            $suggestions[] = [
                'text' => $row['name'],
                'type' => 'correspondent',
            ];
        }
        
        // Search in tags
        $stmt = $this->db->prepare("
            SELECT name, 'tag' as type
            FROM tags
            WHERE name LIKE :query
            LIMIT :limit
        ");
        $stmt->bindValue(':query', '%' . $partial . '%');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        foreach ($stmt->fetchAll() as $row) {
            $suggestions[] = [
                'text' => $row['name'],
                'type' => 'tag',
            ];
        }
        
        return array_slice($suggestions, 0, $limit);
    }
    
    /**
     * Build SQL query for search
     */
    private function buildSearchSql(SearchQuery $query): array
    {
        $select = "
            SELECT d.*,
                   c.name as correspondent_name,
                   dt.label as document_type_name
        ";
        
        $from = "
            FROM documents d
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
        ";
        
        $where = ["d.deleted_at IS NULL"];
        // Exclure les documents en attente de validation (pending) de la recherche
        // Ces documents sont visibles uniquement dans /admin/consume
        $where[] = "(d.status IS NULL OR d.status != 'pending')";
        $params = [];
        $joins = [];
        
        // Full-text search
        if (!empty($query->text)) {
            $where[] = "(d.title LIKE :search_like OR d.content LIKE :search_like OR d.ocr_text LIKE :search_like)";
            $params['search_like'] = '%' . $query->text . '%';
        }
        
        // Correspondent filter
        if ($query->correspondentId) {
            $where[] = "d.correspondent_id = :correspondent_id";
            $params['correspondent_id'] = $query->correspondentId;
        }
        if ($query->correspondentName) {
            $where[] = "c.name LIKE :correspondent_name";
            $params['correspondent_name'] = '%' . $query->correspondentName . '%';
        }
        
        // Document type filter
        if ($query->documentTypeId) {
            $where[] = "d.document_type_id = :document_type_id";
            $params['document_type_id'] = $query->documentTypeId;
        }
        if ($query->documentTypeName) {
            $where[] = "dt.label LIKE :document_type_name";
            $params['document_type_name'] = '%' . $query->documentTypeName . '%';
        }
        
        // Tag filters
        if (!empty($query->tagIds)) {
            if ($query->tagsMatchAll) {
                // All tags must match
                foreach ($query->tagIds as $i => $tagId) {
                    $joins[] = "INNER JOIN document_tags dt{$i} ON d.id = dt{$i}.document_id AND dt{$i}.tag_id = :tag_id_{$i}";
                    $params["tag_id_{$i}"] = $tagId;
                }
            } else {
                // Any tag matches
                $joins[] = "INNER JOIN document_tags dtag ON d.id = dtag.document_id";
                $placeholders = [];
                foreach ($query->tagIds as $i => $tagId) {
                    $placeholders[] = ":tag_id_{$i}";
                    $params["tag_id_{$i}"] = $tagId;
                }
                $where[] = "dtag.tag_id IN (" . implode(',', $placeholders) . ")";
            }
        }
        
        // Date filters
        if ($query->createdAfter) {
            $where[] = "d.created_at >= :created_after";
            $params['created_after'] = $query->createdAfter;
        }
        if ($query->createdBefore) {
            $where[] = "d.created_at <= :created_before";
            $params['created_before'] = $query->createdBefore;
        }
        if ($query->addedAfter) {
            $where[] = "d.created_at >= :added_after";
            $params['added_after'] = $query->addedAfter;
        }
        if ($query->addedBefore) {
            $where[] = "d.created_at <= :added_before";
            $params['added_before'] = $query->addedBefore;
        }
        
        // Owner filter
        if ($query->ownerId) {
            $where[] = "d.owner_id = :owner_id";
            $params['owner_id'] = $query->ownerId;
        }
        
        // Has content filter
        if ($query->hasContent) {
            $where[] = "d.content IS NOT NULL AND d.content != ''";
        }
        
        // Mime type filter
        if ($query->mimeType) {
            $where[] = "d.mime_type = :mime_type";
            $params['mime_type'] = $query->mimeType;
        }
        
        // Build final SQL
        $joinsSql = implode(' ', $joins);
        $whereSql = implode(' AND ', $where);
        
        // Order by
        $orderBy = match ($query->orderBy) {
            'created_at' => 'd.created_at',
            'added_at' => 'd.created_at',
            'modified_at' => 'd.updated_at',
            'title' => 'd.title',
            'asn' => 'd.asn',
            'relevance' => 'd.created_at',
            default => 'd.created_at',
        };
        
        $sql = "{$select} {$from} {$joinsSql} WHERE {$whereSql}
                GROUP BY d.id
                ORDER BY {$orderBy} {$query->orderDir}
                LIMIT {$query->perPage} OFFSET {$query->offset}";
        
        $countSql = "SELECT COUNT(DISTINCT d.id) {$from} {$joinsSql} WHERE {$whereSql}";
        
        return [$sql, $countSql, $params];
    }
    
    /**
     * Get correspondent facets
     */
    private function getCorrespondentFacets(SearchQuery $query): array
    {
        $sql = "
            SELECT c.id, c.name, COUNT(d.id) as count
            FROM documents d
            INNER JOIN correspondents c ON d.correspondent_id = c.id
            WHERE d.deleted_at IS NULL
            GROUP BY c.id, c.name
            ORDER BY count DESC
            LIMIT 20
        ";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get document type facets
     */
    private function getDocumentTypeFacets(SearchQuery $query): array
    {
        $sql = "
            SELECT dt.id, dt.label as name, COUNT(d.id) as count
            FROM documents d
            INNER JOIN document_types dt ON d.document_type_id = dt.id
            WHERE d.deleted_at IS NULL
            GROUP BY dt.id, dt.label
            ORDER BY count DESC
            LIMIT 20
        ";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get tag facets
     */
    private function getTagFacets(SearchQuery $query): array
    {
        $sql = "
            SELECT t.id, t.name, t.color, COUNT(dt.document_id) as count
            FROM tags t
            INNER JOIN document_tags dt ON t.id = dt.tag_id
            INNER JOIN documents d ON dt.document_id = d.id
            WHERE d.deleted_at IS NULL
            GROUP BY t.id, t.name, t.color
            ORDER BY count DESC
            LIMIT 30
        ";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get year facets
     */
    private function getYearFacets(SearchQuery $query): array
    {
        $sql = "
            SELECT YEAR(d.created_at) as year, COUNT(*) as count
            FROM documents d
            WHERE d.deleted_at IS NULL AND d.created_at IS NOT NULL
            GROUP BY YEAR(d.created_at)
            ORDER BY year DESC
            LIMIT 10
        ";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get aggregations
     */
    private function getAggregations(SearchQuery $query): array
    {
        // For now, return document count
        // Amount aggregation would require storing amounts in documents table
        return [
            'count' => 0,
            'total_amount' => null,
            'avg_amount' => null,
        ];
    }
}

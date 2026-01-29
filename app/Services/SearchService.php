<?php
/**
 * K-Docs - SearchService
 * Service de recherche avancée utilisant SearchQueryBuilder
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Search\SearchQuery;
use KDocs\Search\SearchResult;
use KDocs\Search\AdvancedSearchParser;
use PDO;

class SearchService
{
    private \PDO $db;
    private AdvancedSearchParser $parser;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->parser = new AdvancedSearchParser();
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
            
            // Debug log
            error_log("Search SQL: " . $sql);
            error_log("Search params: " . json_encode($params));
            
            // Get total count
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($params);
            $result->total = (int) $countStmt->fetchColumn();
            $result->totalPages = (int) ceil($result->total / $query->perPage);
            
            // Get documents
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Add relevance score and excerpts if searching
            if (!empty($query->text)) {
                $documents = $this->enrichDocumentsWithRelevance($documents, $query->text);
            }

            $result->documents = $documents;

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
                   dt.label as document_type_name,
                   df.path as folder_path
        ";

        $from = "
            FROM documents d
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            LEFT JOIN document_folders df ON d.folder_id = df.id
        ";

        $where = ["d.deleted_at IS NULL"];
        $params = [];
        $joins = [];

        // Advanced full-text search with operators
        if (!empty($query->text)) {
            $parsed = $this->parser->parse($query->text, $query->searchScope);
            $where[] = $parsed['sql'];
            $params = array_merge($params, $parsed['params']);
        }

        // Folder filter
        if ($query->folderId) {
            $where[] = "(d.folder_id = :folder_id OR df.path LIKE :folder_path_prefix)";
            $params['folder_id'] = $query->folderId;
            // Get folder path for subfolder matching
            $folderStmt = $this->db->prepare("SELECT path FROM document_folders WHERE id = ?");
            $folderStmt->execute([$query->folderId]);
            $folderPath = $folderStmt->fetchColumn();
            $params['folder_path_prefix'] = ($folderPath ?: '') . '/%';
        }

        // Date range filter
        if ($query->dateFrom) {
            $where[] = "DATE(d.created_at) >= :date_from";
            $params['date_from'] = $query->dateFrom;
        }
        if ($query->dateTo) {
            $where[] = "DATE(d.created_at) <= :date_to";
            $params['date_to'] = $query->dateTo;
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
        
        // Tag filters by ID
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

        // Tag filters by name
        if (!empty($query->tagNames)) {
            $joins[] = "INNER JOIN document_tags dtag_name ON d.id = dtag_name.document_id";
            $joins[] = "INNER JOIN tags t_name ON dtag_name.tag_id = t_name.id";
            $tagNameConditions = [];
            foreach ($query->tagNames as $i => $tagName) {
                $tagNameConditions[] = "t_name.name LIKE :tag_name_{$i}";
                $params["tag_name_{$i}"] = '%' . $tagName . '%';
            }
            $where[] = "(" . implode(' OR ', $tagNameConditions) . ")";
        }

        // Category filter (search in correspondent or document type)
        if (!empty($query->category)) {
            $where[] = "(c.name LIKE :category_c OR dt.label LIKE :category_dt)";
            $params['category_c'] = '%' . $query->category . '%';
            $params['category_dt'] = '%' . $query->category . '%';
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

    /**
     * Enrich documents with relevance score and excerpts
     */
    private function enrichDocumentsWithRelevance(array $documents, string $searchText): array
    {
        // Use parser to get clean terms for highlighting
        $searchTerms = $this->parser->getHighlightTerms($searchText);
        $searchTerms = array_map('mb_strtolower', $searchTerms);
        $searchTerms = array_filter($searchTerms, fn($t) => mb_strlen($t) >= 2);

        foreach ($documents as &$doc) {
            $score = 0;
            $excerpts = [];
            $title = mb_strtolower($doc['title'] ?? '');
            $content = $doc['content'] ?? '';
            $ocrText = $doc['ocr_text'] ?? '';
            $fullText = $content ?: $ocrText;
            $fullTextLower = mb_strtolower($fullText);

            foreach ($searchTerms as $term) {
                // Score: title match = 30 points, content match = 10 points per occurrence (max 50)
                if (mb_strpos($title, $term) !== false) {
                    $score += 30;
                }
                $contentMatches = mb_substr_count($fullTextLower, $term);
                $score += min(50, $contentMatches * 10);
            }

            // Normalize score to percentage (0-100)
            $maxPossibleScore = count($searchTerms) * 80; // 30 title + 50 content per term
            $doc['relevance_score'] = $maxPossibleScore > 0 ? min(100, round(($score / $maxPossibleScore) * 100)) : 0;

            // Extract excerpts with context
            $doc['excerpts'] = $this->extractExcerpts($fullText, $searchTerms, 3);
        }

        // Sort by relevance score descending
        usort($documents, fn($a, $b) => $b['relevance_score'] <=> $a['relevance_score']);

        return $documents;
    }

    /**
     * Extract relevant excerpts with highlighted search terms
     */
    private function extractExcerpts(string $text, array $searchTerms, int $maxExcerpts = 3): array
    {
        if (empty($text) || empty($searchTerms)) {
            return [];
        }

        $excerpts = [];
        $textLower = mb_strtolower($text);
        $contextLength = 80; // Characters before and after match

        foreach ($searchTerms as $term) {
            $pos = 0;
            while (($pos = mb_strpos($textLower, $term, $pos)) !== false && count($excerpts) < $maxExcerpts) {
                // Get context around the match
                $start = max(0, $pos - $contextLength);
                $end = min(mb_strlen($text), $pos + mb_strlen($term) + $contextLength);

                // Adjust to word boundaries
                if ($start > 0) {
                    $spacePos = mb_strpos($text, ' ', $start);
                    if ($spacePos !== false && $spacePos < $pos) {
                        $start = $spacePos + 1;
                    }
                }
                if ($end < mb_strlen($text)) {
                    $spacePos = mb_strrpos(mb_substr($text, 0, $end), ' ');
                    if ($spacePos !== false && $spacePos > $pos + mb_strlen($term)) {
                        $end = $spacePos;
                    }
                }

                $excerpt = mb_substr($text, $start, $end - $start);

                // Clean up whitespace
                $excerpt = preg_replace('/\s+/', ' ', trim($excerpt));

                // Highlight the search term
                $excerpt = preg_replace(
                    '/(' . preg_quote($term, '/') . ')/iu',
                    '<mark>$1</mark>',
                    $excerpt
                );

                // Add ellipsis
                if ($start > 0) {
                    $excerpt = '...' . $excerpt;
                }
                if ($end < mb_strlen($text)) {
                    $excerpt = $excerpt . '...';
                }

                $excerpts[] = $excerpt;
                $pos += mb_strlen($term) + $contextLength; // Skip ahead to avoid overlapping excerpts
            }

            if (count($excerpts) >= $maxExcerpts) {
                break;
            }
        }

        return $excerpts;
    }
}

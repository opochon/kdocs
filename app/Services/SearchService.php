<?php
/**
 * K-Docs - SearchService Hybride
 * FULLTEXT + Ollama (pas de Qdrant)
 */

namespace KDocs\Services;

use KDocs\Contracts\SearchServiceInterface;
use KDocs\Core\Database;
use KDocs\Core\Config;
use KDocs\Search\SearchQuery;
use KDocs\Search\SearchResult;
use PDO;

class SearchService implements SearchServiceInterface
{
    private PDO $db;
    private ?EmbeddingService $embeddings = null;
    private const SEMANTIC_THRESHOLD = 3;

    public function __construct()
    {
        $this->db = Database::getInstance();
        if (Config::get('embeddings.enabled', false)) {
            try {
                $this->embeddings = new EmbeddingService();
            } catch (\Exception $e) {
                error_log('EmbeddingService init failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Simple search
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
     * Strategy: FULLTEXT first, semantic fallback if few results
     */
    public function advancedSearch(SearchQuery $query): SearchResult
    {
        $startTime = microtime(true);
        $result = new SearchResult();
        $result->query = $query->text;
        $result->page = $query->page;
        $result->perPage = $query->perPage;

        try {
            // 1. FULLTEXT search
            $documents = $this->fulltextSearch($query);
            $result->total = count($documents);

            // 2. Semantic fallback if few results and embeddings available
            if ($result->total < self::SEMANTIC_THRESHOLD && !empty($query->text) && $this->embeddings?->isAvailable()) {
                $semanticDocs = $this->semanticSearch($query->text, $query->perPage);
                $documents = $this->mergeResults($documents, $semanticDocs);
                $result->total = count($documents);
                $result->semanticUsed = true;
            }

            // Paginate
            $offset = ($query->page - 1) * $query->perPage;
            $result->documents = array_slice($documents, $offset, $query->perPage);
            $result->totalPages = (int) ceil($result->total / $query->perPage);

            // Facets if requested
            if ($query->withFacets) {
                $result->correspondentFacets = $this->getCorrespondentFacets($query);
                $result->documentTypeFacets = $this->getDocumentTypeFacets($query);
            }

        } catch (\Exception $e) {
            error_log('Search failed: ' . $e->getMessage());
            $result->error = $e->getMessage();
        }

        $result->searchTime = microtime(true) - $startTime;
        return $result;
    }

    /**
     * FULLTEXT search in MySQL
     */
    private function fulltextSearch(SearchQuery $query): array
    {
        $params = [];
        $where = ["d.deleted_at IS NULL"];
        $select = "SELECT d.*, c.name as correspondent_name, dt.label as document_type_name, df.path as folder_path";

        // FULLTEXT scoring
        if (!empty($query->text)) {
            $ftQuery = $this->buildFulltextQuery($query->text);
            $select .= ", MATCH(d.title, d.ocr_text, d.content) AGAINST(:ft_query IN BOOLEAN MODE) AS ft_score";
            $where[] = "MATCH(d.title, d.ocr_text, d.content) AGAINST(:ft_query_where IN BOOLEAN MODE)";
            $params['ft_query'] = $ftQuery;
            $params['ft_query_where'] = $ftQuery;
        }

        $from = "FROM documents d
                 LEFT JOIN correspondents c ON d.correspondent_id = c.id
                 LEFT JOIN document_types dt ON d.document_type_id = dt.id
                 LEFT JOIN document_folders df ON d.folder_id = df.id";

        // Filters
        if ($query->folderId) {
            $where[] = "(d.folder_id = :folder_id OR df.path LIKE :folder_path_prefix)";
            $params['folder_id'] = $query->folderId;
            $folderStmt = $this->db->prepare("SELECT path FROM document_folders WHERE id = ?");
            $folderStmt->execute([$query->folderId]);
            $folderPath = $folderStmt->fetchColumn();
            $params['folder_path_prefix'] = ($folderPath ?: '') . '/%';
        }

        if ($query->correspondentId) {
            $where[] = "d.correspondent_id = :correspondent_id";
            $params['correspondent_id'] = $query->correspondentId;
        }

        if ($query->documentTypeId) {
            $where[] = "d.document_type_id = :document_type_id";
            $params['document_type_id'] = $query->documentTypeId;
        }

        if ($query->dateFrom) {
            $where[] = "DATE(d.created_at) >= :date_from";
            $params['date_from'] = $query->dateFrom;
        }

        if ($query->dateTo) {
            $where[] = "DATE(d.created_at) <= :date_to";
            $params['date_to'] = $query->dateTo;
        }

        if (!empty($query->tagIds)) {
            $placeholders = [];
            foreach ($query->tagIds as $i => $tagId) {
                $placeholders[] = ":tag_id_$i";
                $params["tag_id_$i"] = $tagId;
            }
            $where[] = "EXISTS (SELECT 1 FROM document_tags dt2 WHERE dt2.document_id = d.id AND dt2.tag_id IN (" . implode(',', $placeholders) . "))";
        }

        $whereSql = implode(' AND ', $where);
        $orderBy = !empty($query->text) ? "ft_score DESC, d.created_at DESC" : "d.created_at DESC";
        $sql = "$select $from WHERE $whereSql ORDER BY $orderBy LIMIT 200";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Add excerpts with highlighting
        if (!empty($query->text)) {
            $documents = $this->addExcerpts($documents, $query->text);
        }

        return $documents;
    }

    /**
     * Semantic search using embeddings stored in MySQL
     */
    private function semanticSearch(string $query, int $limit = 10): array
    {
        if (!$this->embeddings) return [];

        // Get query embedding from Ollama
        $queryVector = $this->embeddings->embed($query);
        if (!$queryVector) return [];

        // Load all embeddings and compute similarity
        $stmt = $this->db->query("
            SELECT id, title, embedding
            FROM documents
            WHERE deleted_at IS NULL AND embedding IS NOT NULL
        ");

        $scores = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (empty($row['embedding'])) continue;

            $docVector = unpack('f*', $row['embedding']);
            if (!$docVector) continue;

            $similarity = $this->cosineSimilarity($queryVector, array_values($docVector));
            if ($similarity > 0.3) { // Threshold
                $scores[$row['id']] = [
                    'id' => $row['id'],
                    'title' => $row['title'],
                    'semantic_score' => $similarity
                ];
            }
        }

        if (empty($scores)) return [];

        // Sort by similarity
        uasort($scores, fn($a, $b) => $b['semantic_score'] <=> $a['semantic_score']);
        $topIds = array_slice(array_keys($scores), 0, $limit);

        if (empty($topIds)) return [];

        // Fetch full documents
        $placeholders = implode(',', array_fill(0, count($topIds), '?'));
        $stmt = $this->db->prepare("
            SELECT d.*, c.name as correspondent_name, dt.label as document_type_name
            FROM documents d
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            WHERE d.id IN ($placeholders)
        ");
        $stmt->execute($topIds);

        $documents = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['semantic_score'] = $scores[$row['id']]['semantic_score'];
            $row['source'] = 'semantic';
            $documents[] = $row;
        }

        // Re-sort by score
        usort($documents, fn($a, $b) => ($b['semantic_score'] ?? 0) <=> ($a['semantic_score'] ?? 0));

        return $documents;
    }

    /**
     * Merge FULLTEXT and semantic results
     */
    private function mergeResults(array $fulltext, array $semantic): array
    {
        $seen = [];
        $merged = [];

        foreach ($fulltext as $doc) {
            $seen[$doc['id']] = true;
            $doc['source'] = 'fulltext';
            $merged[] = $doc;
        }

        foreach ($semantic as $doc) {
            if (!isset($seen[$doc['id']])) {
                $doc['source'] = 'semantic';
                $merged[] = $doc;
            }
        }

        return $merged;
    }

    /**
     * Build FULLTEXT boolean query
     */
    private function buildFulltextQuery(string $userQuery): string
    {
        $userQuery = trim($userQuery);

        // If already formatted for BOOLEAN MODE, return as-is
        if (preg_match('/[+\-"]/', $userQuery)) {
            return $userQuery;
        }

        // Convert natural operators
        $userQuery = preg_replace('/\bAND\b/i', '', $userQuery);
        $userQuery = preg_replace('/\bOR\b/i', ' ', $userQuery);
        $userQuery = preg_replace('/\bNOT\b/i', '-', $userQuery);

        // Tokenize and add prefixes
        $terms = preg_split('/\s+/', $userQuery, -1, PREG_SPLIT_NO_EMPTY);
        $result = [];

        foreach ($terms as $term) {
            $term = trim($term);
            if (empty($term) || mb_strlen($term) < 2) continue;

            if (str_starts_with($term, '-')) {
                $result[] = '-' . substr($term, 1) . '*';
            } else {
                $result[] = '+' . $term . '*';
            }
        }

        return implode(' ', $result);
    }

    /**
     * Cosine similarity between two vectors
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b) || count($a) === 0) return 0.0;

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < count($a); $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        return ($normA == 0 || $normB == 0) ? 0.0 : $dot / ($normA * $normB);
    }

    /**
     * Add excerpts with search term highlighting
     */
    private function addExcerpts(array $documents, string $searchText): array
    {
        $terms = array_filter(
            preg_split('/\s+/', mb_strtolower($searchText)),
            fn($t) => mb_strlen(trim($t, '+-*')) >= 2
        );

        foreach ($documents as &$doc) {
            $content = $doc['content'] ?? $doc['ocr_text'] ?? '';
            $doc['excerpts'] = $this->extractExcerpts($content, $terms);
        }

        return $documents;
    }

    /**
     * Extract text excerpts around matching terms
     */
    private function extractExcerpts(string $text, array $terms, int $max = 3): array
    {
        if (empty($text) || empty($terms)) return [];

        $excerpts = [];
        $textLower = mb_strtolower($text);

        foreach ($terms as $term) {
            $term = trim($term, '+-*');
            if (empty($term)) continue;

            $pos = mb_strpos($textLower, $term);
            if ($pos === false) continue;

            $start = max(0, $pos - 80);
            $end = min(mb_strlen($text), $pos + mb_strlen($term) + 80);

            $excerpt = mb_substr($text, $start, $end - $start);
            $excerpt = preg_replace('/\s+/', ' ', trim($excerpt));
            $excerpt = preg_replace('/(' . preg_quote($term, '/') . ')/iu', '<mark>$1</mark>', $excerpt);

            if ($start > 0) $excerpt = '...' . $excerpt;
            if ($end < mb_strlen($text)) $excerpt .= '...';

            $excerpts[] = $excerpt;
            if (count($excerpts) >= $max) break;
        }

        return $excerpts;
    }

    /**
     * Get correspondent facets
     */
    private function getCorrespondentFacets(SearchQuery $query): array
    {
        $sql = "SELECT c.id, c.name, COUNT(*) as count
                FROM documents d
                JOIN correspondents c ON d.correspondent_id = c.id
                WHERE d.deleted_at IS NULL
                GROUP BY c.id, c.name
                ORDER BY count DESC
                LIMIT 20";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get document type facets
     */
    private function getDocumentTypeFacets(SearchQuery $query): array
    {
        $sql = "SELECT dt.id, dt.label as name, COUNT(*) as count
                FROM documents d
                JOIN document_types dt ON d.document_type_id = dt.id
                WHERE d.deleted_at IS NULL
                GROUP BY dt.id, dt.label
                ORDER BY count DESC
                LIMIT 20";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Search suggestions (autocomplete)
     */
    public function suggest(string $partial, int $limit = 10): array
    {
        if (mb_strlen($partial) < 2) return [];

        $suggestions = [];

        // Document titles
        $stmt = $this->db->prepare("
            SELECT DISTINCT title, 'document' as type
            FROM documents
            WHERE title LIKE :q AND deleted_at IS NULL
            LIMIT :l
        ");
        $stmt->bindValue(':q', '%' . $partial . '%');
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $suggestions = array_merge($suggestions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Correspondents
        $stmt = $this->db->prepare("
            SELECT name as title, 'correspondent' as type
            FROM correspondents
            WHERE name LIKE :q
            LIMIT :l
        ");
        $stmt->bindValue(':q', '%' . $partial . '%');
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $suggestions = array_merge($suggestions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Tags
        $stmt = $this->db->prepare("
            SELECT name as title, 'tag' as type
            FROM tags
            WHERE name LIKE :q
            LIMIT :l
        ");
        $stmt->bindValue(':q', '%' . $partial . '%');
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $suggestions = array_merge($suggestions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        return array_slice($suggestions, 0, $limit);
    }
}

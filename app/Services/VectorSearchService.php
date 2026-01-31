<?php
/**
 * K-Docs - Vector Search Service
 * Manages Qdrant vector database for semantic search
 */

namespace KDocs\Services;

use KDocs\Core\Config;
use KDocs\Core\Database;

class VectorSearchService
{
    private string $host;
    private int $port;
    private string $collection;
    private ?string $apiKey;
    private int $dimensions;
    private EmbeddingService $embeddingService;

    public function __construct()
    {
        $config = Config::get('qdrant', []);
        $embeddingConfig = Config::get('embeddings', []);

        $this->host = $config['host'] ?? 'localhost';
        $this->port = $config['port'] ?? 6333;
        $this->collection = $config['collection'] ?? 'kdocs_documents';
        $this->apiKey = $config['api_key'] ?? null;
        $this->dimensions = $embeddingConfig['dimensions'] ?? 1536;

        $this->embeddingService = new EmbeddingService();
    }

    /**
     * Check if Qdrant is available
     */
    public function isAvailable(): bool
    {
        try {
            // Qdrant root endpoint returns version info
            $response = $this->request('GET', '/');
            return $response !== null && isset($response['version']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Initialize collection if it doesn't exist
     */
    public function initializeCollection(): bool
    {
        // Check if collection exists
        $response = $this->request('GET', "/collections/{$this->collection}");

        if ($response && isset($response['result'])) {
            return true; // Collection already exists
        }

        // Create collection
        $payload = [
            'vectors' => [
                'size' => $this->dimensions,
                'distance' => 'Cosine'
            ],
            'optimizers_config' => [
                'default_segment_number' => 2,
            ],
            'replication_factor' => 1,
        ];

        $response = $this->request('PUT', "/collections/{$this->collection}", $payload);

        return $response && ($response['result'] ?? false);
    }

    /**
     * Upsert a document vector
     */
    public function upsertDocument(int $documentId, array $vector, array $metadata = []): bool
    {
        $payload = [
            'points' => [
                [
                    'id' => $documentId,
                    'vector' => $vector,
                    'payload' => array_merge($metadata, [
                        'document_id' => $documentId,
                        'indexed_at' => date('c'),
                    ]),
                ]
            ]
        ];

        $response = $this->request('PUT', "/collections/{$this->collection}/points", $payload);

        // Qdrant returns 'acknowledged' for async operations, 'completed' for sync
        $status = $response['result']['status'] ?? '';
        return $response && in_array($status, ['acknowledged', 'completed']);
    }

    /**
     * Upsert multiple document vectors in batch
     */
    public function upsertBatch(array $points): bool
    {
        if (empty($points)) {
            return true;
        }

        $formattedPoints = [];
        foreach ($points as $point) {
            $formattedPoints[] = [
                'id' => $point['id'],
                'vector' => $point['vector'],
                'payload' => array_merge($point['metadata'] ?? [], [
                    'document_id' => $point['id'],
                    'indexed_at' => date('c'),
                ]),
            ];
        }

        $payload = ['points' => $formattedPoints];
        $response = $this->request('PUT', "/collections/{$this->collection}/points", $payload);

        // Qdrant returns 'acknowledged' for async operations, 'completed' for sync
        $status = $response['result']['status'] ?? '';
        return $response && in_array($status, ['acknowledged', 'completed']);
    }

    /**
     * Delete a document vector
     */
    public function deleteDocument(int $documentId): bool
    {
        $payload = [
            'points' => [$documentId]
        ];

        $response = $this->request('POST', "/collections/{$this->collection}/points/delete", $payload);

        // Qdrant returns 'acknowledged' for async operations, 'completed' for sync
        $status = $response['result']['status'] ?? '';
        return $response && in_array($status, ['acknowledged', 'completed']);
    }

    /**
     * Semantic search with a query string
     */
    public function search(string $query, int $limit = 10, array $filters = []): array
    {
        // Generate embedding for query
        $queryVector = $this->embeddingService->embed($query);

        if (!$queryVector) {
            return [];
        }

        return $this->searchByVector($queryVector, $limit, $filters);
    }

    /**
     * Search by vector
     */
    public function searchByVector(array $vector, int $limit = 10, array $filters = []): array
    {
        $payload = [
            'vector' => $vector,
            'limit' => $limit,
            'with_payload' => true,
        ];

        // Add filters if provided
        if (!empty($filters)) {
            $payload['filter'] = $this->buildFilter($filters);
        }

        $response = $this->request('POST', "/collections/{$this->collection}/points/search", $payload);

        if (!$response || !isset($response['result'])) {
            return [];
        }

        return array_map(function ($hit) {
            return [
                'document_id' => $hit['id'],
                'score' => $hit['score'],
                'payload' => $hit['payload'] ?? [],
            ];
        }, $response['result']);
    }

    /**
     * Find similar documents
     */
    public function findSimilar(int $documentId, int $limit = 5): array
    {
        // Get the vector for this document from Qdrant
        $response = $this->request('GET', "/collections/{$this->collection}/points/{$documentId}");

        if (!$response || !isset($response['result']['vector'])) {
            // Try to get from database and generate
            $embedding = $this->embeddingService->embedDocument($documentId);
            if (!$embedding) {
                return [];
            }
            $vector = $embedding;
        } else {
            $vector = $response['result']['vector'];
        }

        // Search for similar, excluding the source document
        $payload = [
            'vector' => $vector,
            'limit' => $limit + 1, // +1 to account for self-match
            'with_payload' => true,
            'filter' => [
                'must_not' => [
                    ['has_id' => [$documentId]]
                ]
            ]
        ];

        $response = $this->request('POST', "/collections/{$this->collection}/points/search", $payload);

        if (!$response || !isset($response['result'])) {
            return [];
        }

        // Get document details
        $db = Database::getInstance();
        $results = [];

        foreach (array_slice($response['result'], 0, $limit) as $hit) {
            $docId = $hit['id'];

            $stmt = $db->prepare("
                SELECT id, title, original_filename, document_type_id, correspondent_id,
                       dt.label as document_type_label, c.name as correspondent_name
                FROM documents d
                LEFT JOIN document_types dt ON d.document_type_id = dt.id
                LEFT JOIN correspondents c ON d.correspondent_id = c.id
                WHERE d.id = ? AND d.deleted_at IS NULL
            ");
            $stmt->execute([$docId]);
            $doc = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($doc) {
                $results[] = [
                    'document' => $doc,
                    'score' => $hit['score'],
                    'similarity' => round($hit['score'] * 100, 1) . '%',
                ];
            }
        }

        return $results;
    }

    /**
     * Hybrid search combining semantic and keyword search
     */
    public function hybridSearch(
        string $query,
        int $limit = 10,
        array $filters = [],
        float $semanticWeight = 0.7
    ): array {
        // 1. Semantic search
        $semanticResults = $this->search($query, $limit * 2, $filters);

        // 2. Keyword search
        $db = Database::getInstance();
        $keywordResults = $this->keywordSearch($db, $query, $limit * 2, $filters);

        // 3. Combine results using RRF (Reciprocal Rank Fusion)
        $combined = $this->reciprocalRankFusion(
            $semanticResults,
            $keywordResults,
            $semanticWeight
        );

        // 4. Get full document info for top results
        $finalResults = [];
        foreach (array_slice($combined, 0, $limit) as $item) {
            $stmt = $db->prepare("
                SELECT d.*, dt.label as document_type_label, c.name as correspondent_name
                FROM documents d
                LEFT JOIN document_types dt ON d.document_type_id = dt.id
                LEFT JOIN correspondents c ON d.correspondent_id = c.id
                WHERE d.id = ? AND d.deleted_at IS NULL
            ");
            $stmt->execute([$item['document_id']]);
            $doc = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($doc) {
                $finalResults[] = [
                    'document' => $doc,
                    'score' => $item['score'],
                    'semantic_score' => $item['semantic_score'] ?? 0,
                    'keyword_score' => $item['keyword_score'] ?? 0,
                ];
            }
        }

        return $finalResults;
    }

    /**
     * Keyword search in database
     */
    private function keywordSearch(\PDO $db, string $query, int $limit, array $filters): array
    {
        $where = ['d.deleted_at IS NULL'];
        $params = [];

        // Full-text search
        $searchTerms = '%' . $query . '%';
        $where[] = "(d.title LIKE ? OR d.original_filename LIKE ? OR d.ocr_text LIKE ? OR d.content LIKE ?)";
        $params = array_merge($params, [$searchTerms, $searchTerms, $searchTerms, $searchTerms]);

        // Apply filters
        if (!empty($filters['document_type_id'])) {
            $where[] = "d.document_type_id = ?";
            $params[] = $filters['document_type_id'];
        }
        if (!empty($filters['correspondent_id'])) {
            $where[] = "d.correspondent_id = ?";
            $params[] = $filters['correspondent_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = "d.document_date >= ?";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "d.document_date <= ?";
            $params[] = $filters['date_to'];
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $sql = "
            SELECT d.id,
                   CASE
                       WHEN d.title LIKE ? THEN 1.0
                       WHEN d.original_filename LIKE ? THEN 0.8
                       ELSE 0.5
                   END as relevance
            FROM documents d
            $whereClause
            ORDER BY relevance DESC
            LIMIT ?
        ";

        $params = array_merge([$searchTerms, $searchTerms], $params, [$limit]);

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $results = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $results[] = [
                'document_id' => (int)$row['id'],
                'score' => (float)$row['relevance'],
            ];
        }

        return $results;
    }

    /**
     * Reciprocal Rank Fusion to combine results
     */
    private function reciprocalRankFusion(array $semantic, array $keyword, float $semanticWeight): array
    {
        $k = 60; // RRF constant
        $scores = [];

        // Add semantic scores
        foreach ($semantic as $rank => $item) {
            $docId = $item['document_id'];
            if (!isset($scores[$docId])) {
                $scores[$docId] = ['document_id' => $docId, 'semantic_score' => 0, 'keyword_score' => 0];
            }
            $scores[$docId]['semantic_score'] = $semanticWeight / ($k + $rank + 1);
        }

        // Add keyword scores
        foreach ($keyword as $rank => $item) {
            $docId = $item['document_id'];
            if (!isset($scores[$docId])) {
                $scores[$docId] = ['document_id' => $docId, 'semantic_score' => 0, 'keyword_score' => 0];
            }
            $scores[$docId]['keyword_score'] = (1 - $semanticWeight) / ($k + $rank + 1);
        }

        // Calculate combined score
        foreach ($scores as &$item) {
            $item['score'] = $item['semantic_score'] + $item['keyword_score'];
        }

        // Sort by combined score
        usort($scores, fn($a, $b) => $b['score'] <=> $a['score']);

        return $scores;
    }

    /**
     * Build Qdrant filter from array
     */
    private function buildFilter(array $filters): array
    {
        $must = [];

        if (!empty($filters['document_type_id'])) {
            $must[] = [
                'key' => 'document_type_id',
                'match' => ['value' => $filters['document_type_id']]
            ];
        }

        if (!empty($filters['correspondent_id'])) {
            $must[] = [
                'key' => 'correspondent_id',
                'match' => ['value' => $filters['correspondent_id']]
            ];
        }

        if (!empty($filters['tag_ids'])) {
            $must[] = [
                'key' => 'tag_ids',
                'match' => ['any' => $filters['tag_ids']]
            ];
        }

        return empty($must) ? [] : ['must' => $must];
    }

    /**
     * Get collection info
     */
    public function getCollectionInfo(): ?array
    {
        $response = $this->request('GET', "/collections/{$this->collection}");

        if (!$response || !isset($response['result'])) {
            return null;
        }

        return [
            'name' => $this->collection,
            'vectors_count' => $response['result']['vectors_count'] ?? 0,
            'points_count' => $response['result']['points_count'] ?? 0,
            'indexed_vectors_count' => $response['result']['indexed_vectors_count'] ?? 0,
            'status' => $response['result']['status'] ?? 'unknown',
        ];
    }

    /**
     * Get sync status (documents vs vectors)
     */
    public function getSyncStatus(): array
    {
        $db = Database::getInstance();

        // Count documents with content
        $stmt = $db->query("
            SELECT COUNT(*) FROM documents
            WHERE deleted_at IS NULL
            AND (ocr_text IS NOT NULL OR content IS NOT NULL)
        ");
        $documentsWithContent = (int)$stmt->fetchColumn();

        // Get collection info
        $collectionInfo = $this->getCollectionInfo();
        $vectorsCount = $collectionInfo['vectors_count'] ?? 0;

        // Get embedding status counts
        $stmt = $db->query("
            SELECT embedding_status, COUNT(*) as count
            FROM documents
            WHERE deleted_at IS NULL
            GROUP BY embedding_status
        ");
        $statusCounts = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $statusCounts[$row['embedding_status'] ?? 'null'] = (int)$row['count'];
        }

        return [
            'documents_with_content' => $documentsWithContent,
            'vectors_in_qdrant' => $vectorsCount,
            'sync_percentage' => $documentsWithContent > 0
                ? round(($vectorsCount / $documentsWithContent) * 100, 1)
                : 0,
            'status_breakdown' => $statusCounts,
            'qdrant_available' => $this->isAvailable(),
            'embedding_available' => $this->embeddingService->isAvailable(),
        ];
    }

    /**
     * Sync all documents (create/update embeddings)
     */
    public function syncAll(?callable $progressCallback = null): array
    {
        if (!$this->isAvailable()) {
            throw new \Exception('Qdrant is not available');
        }

        if (!$this->embeddingService->isAvailable()) {
            throw new \Exception('Embedding service is not available');
        }

        // Initialize collection
        $this->initializeCollection();

        $db = Database::getInstance();

        // Get all documents needing sync
        $stmt = $db->query("
            SELECT id, title, original_filename, document_type_id, correspondent_id
            FROM documents
            WHERE deleted_at IS NULL
            AND (ocr_text IS NOT NULL OR content IS NOT NULL)
            AND (embedding_status IS NULL OR embedding_status IN ('pending', 'failed'))
            ORDER BY created_at DESC
        ");
        $documents = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $total = count($documents);
        $synced = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($documents as $index => $doc) {
            try {
                // Generate embedding
                $embedding = $this->embeddingService->embedDocument($doc['id']);

                if ($embedding) {
                    // Upsert to Qdrant
                    $metadata = [
                        'title' => $doc['title'],
                        'filename' => $doc['original_filename'],
                        'document_type_id' => $doc['document_type_id'],
                        'correspondent_id' => $doc['correspondent_id'],
                    ];

                    if ($this->upsertDocument($doc['id'], $embedding, $metadata)) {
                        $synced++;
                    } else {
                        $failed++;
                    }
                } else {
                    $skipped++;
                }

                if ($progressCallback) {
                    $progressCallback($index + 1, $total, $doc['id']);
                }

            } catch (\Exception $e) {
                error_log("Sync error for document {$doc['id']}: " . $e->getMessage());
                $failed++;
            }

            // Small delay to avoid rate limiting
            usleep(100000); // 100ms
        }

        return [
            'total' => $total,
            'synced' => $synced,
            'failed' => $failed,
            'skipped' => $skipped,
        ];
    }

    /**
     * Delete vectors for deleted documents
     */
    public function cleanupDeleted(): int
    {
        $db = Database::getInstance();

        // Get IDs of deleted documents that might have vectors
        $stmt = $db->query("
            SELECT id FROM documents
            WHERE deleted_at IS NOT NULL
            AND embedding_status = 'completed'
        ");
        $deleted = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $count = 0;
        foreach ($deleted as $docId) {
            if ($this->deleteDocument($docId)) {
                $count++;

                // Update status
                $updateStmt = $db->prepare("
                    UPDATE documents
                    SET embedding_status = NULL, vector_updated_at = NULL
                    WHERE id = ?
                ");
                $updateStmt->execute([$docId]);
            }
        }

        return $count;
    }

    /**
     * Make HTTP request to Qdrant
     */
    private function request(string $method, string $path, ?array $payload = null): ?array
    {
        $url = "http://{$this->host}:{$this->port}{$path}";

        $ch = curl_init($url);

        $headers = ['Content-Type: application/json'];
        if ($this->apiKey) {
            $headers[] = 'api-key: ' . $this->apiKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("Qdrant request error: $error");
            return null;
        }

        if ($httpCode >= 400) {
            error_log("Qdrant HTTP error $httpCode: $response");
            return null;
        }

        return json_decode($response, true);
    }
}

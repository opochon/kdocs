<?php
/**
 * K-Docs - Vector Store Service
 * Client pour Qdrant - Stockage et recherche vectorielle
 */

namespace KDocs\Services;

use KDocs\Core\Config;
use KDocs\Core\Database;

class VectorStoreService
{
    private string $host;
    private int $port;
    private string $collection;
    private ?string $apiKey;
    private int $dimensions;
    
    private static ?bool $available = null;

    public function __construct()
    {
        $config = Config::get('qdrant', []);
        $this->host = $config['host'] ?? 'localhost';
        $this->port = (int)($config['port'] ?? 6333);
        $this->collection = $config['collection'] ?? 'kdocs_documents';
        $this->apiKey = $config['api_key'] ?? null;
        
        $embeddingConfig = Config::get('embeddings', []);
        $this->dimensions = (int)($embeddingConfig['dimensions'] ?? 768);
    }

    /**
     * Check if Qdrant is available
     */
    public function isAvailable(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        try {
            $response = $this->request('GET', '/collections');
            self::$available = $response !== null && isset($response['result']);
        } catch (\Exception $e) {
            self::$available = false;
        }

        return self::$available;
    }

    /**
     * Reset availability cache
     */
    public static function resetCache(): void
    {
        self::$available = null;
    }

    /**
     * Get base URL for Qdrant API
     */
    private function getBaseUrl(): string
    {
        return "http://{$this->host}:{$this->port}";
    }

    /**
     * Create collection if not exists
     */
    public function createCollection(): bool
    {
        // Check if collection exists
        $response = $this->request('GET', "/collections/{$this->collection}");
        
        if ($response && isset($response['result'])) {
            return true; // Already exists
        }

        // Create collection
        $payload = [
            'vectors' => [
                'size' => $this->dimensions,
                'distance' => 'Cosine'
            ],
            'optimizers_config' => [
                'memmap_threshold' => 20000
            ],
            'on_disk_payload' => true
        ];

        $response = $this->request('PUT', "/collections/{$this->collection}", $payload);
        
        return $response && ($response['result'] ?? false) === true;
    }

    /**
     * Delete collection
     */
    public function deleteCollection(): bool
    {
        $response = $this->request('DELETE', "/collections/{$this->collection}");
        return $response && ($response['result'] ?? false) === true;
    }

    /**
     * Upsert a document vector
     * 
     * @param int $documentId Document ID (used as point ID)
     * @param array $vector Embedding vector
     * @param array $metadata Document metadata for filtering
     */
    public function upsert(int $documentId, array $vector, array $metadata = []): bool
    {
        $payload = [
            'points' => [
                [
                    'id' => $documentId,
                    'vector' => $vector,
                    'payload' => array_merge($metadata, [
                        'document_id' => $documentId,
                        'indexed_at' => date('c')
                    ])
                ]
            ]
        ];

        $response = $this->request('PUT', "/collections/{$this->collection}/points", $payload);
        
        $success = $response && ($response['status'] ?? '') === 'ok';
        
        if ($success) {
            $this->logOperation($documentId, 'upsert');
        }
        
        return $success;
    }

    /**
     * Upsert multiple document vectors in batch
     * 
     * @param array $points Array of ['id' => int, 'vector' => array, 'metadata' => array]
     */
    public function upsertBatch(array $points): bool
    {
        if (empty($points)) {
            return true;
        }

        $formattedPoints = [];
        foreach ($points as $point) {
            $formattedPoints[] = [
                'id' => (int)$point['id'],
                'vector' => $point['vector'],
                'payload' => array_merge($point['metadata'] ?? [], [
                    'document_id' => (int)$point['id'],
                    'indexed_at' => date('c')
                ])
            ];
        }

        $payload = ['points' => $formattedPoints];
        $response = $this->request('PUT', "/collections/{$this->collection}/points", $payload);
        
        return $response && ($response['status'] ?? '') === 'ok';
    }

    /**
     * Delete a document vector
     */
    public function delete(int $documentId): bool
    {
        $payload = [
            'points' => [$documentId]
        ];

        $response = $this->request('POST', "/collections/{$this->collection}/points/delete", $payload);
        
        $success = $response && ($response['status'] ?? '') === 'ok';
        
        if ($success) {
            $this->logOperation($documentId, 'delete');
        }
        
        return $success;
    }

    /**
     * Delete multiple document vectors
     */
    public function deleteBatch(array $documentIds): bool
    {
        if (empty($documentIds)) {
            return true;
        }

        $payload = [
            'points' => array_map('intval', $documentIds)
        ];

        $response = $this->request('POST', "/collections/{$this->collection}/points/delete", $payload);
        
        return $response && ($response['status'] ?? '') === 'ok';
    }

    /**
     * Search for similar documents
     * 
     * @param array $vector Query vector
     * @param int $limit Maximum results
     * @param array $filters Optional filters (e.g., ['correspondent_id' => 5])
     * @param float $scoreThreshold Minimum similarity score (0-1)
     * @return array Array of ['id' => int, 'score' => float, 'payload' => array]
     */
    public function search(array $vector, int $limit = 10, array $filters = [], float $scoreThreshold = 0.0): array
    {
        $payload = [
            'vector' => $vector,
            'limit' => $limit,
            'with_payload' => true,
            'score_threshold' => $scoreThreshold
        ];

        // Build filter if provided
        if (!empty($filters)) {
            $payload['filter'] = $this->buildFilter($filters);
        }

        $response = $this->request('POST', "/collections/{$this->collection}/points/search", $payload);
        
        if (!$response || !isset($response['result'])) {
            return [];
        }

        $results = [];
        foreach ($response['result'] as $point) {
            $results[] = [
                'id' => $point['id'],
                'score' => $point['score'],
                'payload' => $point['payload'] ?? []
            ];
        }

        return $results;
    }

    /**
     * Find similar documents to a given document
     */
    public function findSimilar(int $documentId, int $limit = 10, array $filters = []): array
    {
        // First, get the vector for this document
        $response = $this->request('GET', "/collections/{$this->collection}/points/{$documentId}");
        
        if (!$response || !isset($response['result']['vector'])) {
            return [];
        }

        $vector = $response['result']['vector'];
        
        // Exclude the source document from results
        $filters['exclude_id'] = $documentId;
        
        return $this->search($vector, $limit + 1, $filters);
    }

    /**
     * Get a specific point/vector
     */
    public function get(int $documentId): ?array
    {
        $response = $this->request('GET', "/collections/{$this->collection}/points/{$documentId}");
        
        if (!$response || !isset($response['result'])) {
            return null;
        }

        return $response['result'];
    }

    /**
     * Check if a document has a vector
     */
    public function exists(int $documentId): bool
    {
        return $this->get($documentId) !== null;
    }

    /**
     * Get collection info and statistics
     */
    public function getCollectionInfo(): ?array
    {
        $response = $this->request('GET', "/collections/{$this->collection}");
        
        if (!$response || !isset($response['result'])) {
            return null;
        }

        return $response['result'];
    }

    /**
     * Get total points count in collection
     */
    public function count(): int
    {
        $info = $this->getCollectionInfo();
        return $info['points_count'] ?? 0;
    }

    /**
     * Scroll through all points (pagination)
     */
    public function scroll(int $limit = 100, ?int $offset = null): array
    {
        $payload = [
            'limit' => $limit,
            'with_payload' => true,
            'with_vector' => false
        ];

        if ($offset !== null) {
            $payload['offset'] = $offset;
        }

        $response = $this->request('POST', "/collections/{$this->collection}/points/scroll", $payload);
        
        if (!$response || !isset($response['result'])) {
            return ['points' => [], 'next_offset' => null];
        }

        return [
            'points' => $response['result']['points'] ?? [],
            'next_offset' => $response['result']['next_page_offset'] ?? null
        ];
    }

    /**
     * Build Qdrant filter from simple array
     */
    private function buildFilter(array $filters): array
    {
        $must = [];
        $mustNot = [];

        foreach ($filters as $key => $value) {
            if ($key === 'exclude_id') {
                $mustNot[] = [
                    'has_id' => [$value]
                ];
                continue;
            }

            if (is_array($value)) {
                // Array = any of these values
                $must[] = [
                    'key' => $key,
                    'match' => ['any' => $value]
                ];
            } else {
                // Single value match
                $must[] = [
                    'key' => $key,
                    'match' => ['value' => $value]
                ];
            }
        }

        $filter = [];
        if (!empty($must)) {
            $filter['must'] = $must;
        }
        if (!empty($mustNot)) {
            $filter['must_not'] = $mustNot;
        }

        return $filter;
    }

    /**
     * Make HTTP request to Qdrant
     */
    private function request(string $method, string $endpoint, ?array $payload = null): ?array
    {
        $url = $this->getBaseUrl() . $endpoint;

        $ch = curl_init($url);
        
        $headers = ['Content-Type: application/json'];
        if ($this->apiKey) {
            $headers[] = 'api-key: ' . $this->apiKey;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
        ];

        if ($payload !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload);
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("VectorStoreService: cURL error - $error");
            return null;
        }

        if ($httpCode >= 400) {
            error_log("VectorStoreService: HTTP $httpCode - $response");
            return null;
        }

        return json_decode($response, true);
    }

    /**
     * Log vector operation
     */
    private function logOperation(int $documentId, string $action): void
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                INSERT INTO embedding_logs (document_id, action, created_at)
                VALUES (?, ?, NOW())
            ");
            $stmt->execute([$documentId, $action]);
        } catch (\Exception $e) {
            // Silent fail for logging
        }
    }

    /**
     * Sync a document to vector store (embedding + upsert)
     */
    public function syncDocument(int $documentId): bool
    {
        $embeddingService = new EmbeddingService();
        
        // Generate embedding
        $vector = $embeddingService->embedDocument($documentId);
        
        if (!$vector) {
            return false;
        }

        // Get document metadata for filtering
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT id, title, correspondent_id, document_type_id, 
                   document_date, status, created_at
            FROM documents 
            WHERE id = ?
        ");
        $stmt->execute([$documentId]);
        $doc = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$doc) {
            return false;
        }

        // Upsert to Qdrant
        return $this->upsert($documentId, $vector, [
            'title' => $doc['title'],
            'correspondent_id' => $doc['correspondent_id'],
            'document_type_id' => $doc['document_type_id'],
            'document_date' => $doc['document_date'],
            'status' => $doc['status'],
            'created_at' => $doc['created_at']
        ]);
    }

    /**
     * Sync all pending documents
     */
    public function syncPending(int $limit = 100): array
    {
        $embeddingService = new EmbeddingService();
        $pending = $embeddingService->getPendingDocuments($limit);
        
        $results = [
            'total' => count($pending),
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($pending as $doc) {
            try {
                if ($this->syncDocument($doc['id'])) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Document {$doc['id']}: sync failed";
                }
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = "Document {$doc['id']}: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Get service status
     */
    public function getStatus(): array
    {
        $available = $this->isAvailable();
        
        $status = [
            'available' => $available,
            'host' => $this->host,
            'port' => $this->port,
            'collection' => $this->collection,
            'dimensions' => $this->dimensions,
        ];

        if ($available) {
            $info = $this->getCollectionInfo();
            $status['collection_exists'] = $info !== null;
            $status['points_count'] = $info['points_count'] ?? 0;
            $status['vectors_count'] = $info['vectors_count'] ?? 0;
            $status['indexed_vectors_count'] = $info['indexed_vectors_count'] ?? 0;
        }

        return $status;
    }
}

<?php
/**
 * K-Docs - Embeddings API Controller
 * REST API for semantic search and embeddings management
 */

namespace KDocs\Controllers\Api;

use KDocs\Services\EmbeddingService;
use KDocs\Services\VectorSearchService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class EmbeddingsApiController extends ApiController
{
    private EmbeddingService $embeddingService;
    private VectorSearchService $vectorService;

    public function __construct()
    {
        $this->embeddingService = new EmbeddingService();
        $this->vectorService = new VectorSearchService();
    }

    /**
     * GET /api/embeddings/status
     * Get embedding/vector sync status
     */
    public function status(Request $request, Response $response): Response
    {
        try {
            $syncStatus = $this->vectorService->getSyncStatus();
            $modelInfo = $this->embeddingService->getModelInfo();
            $statistics = $this->embeddingService->getStatistics();
            $collectionInfo = $this->vectorService->getCollectionInfo();

            return $this->successResponse($response, [
                'sync' => $syncStatus,
                'model' => $modelInfo,
                'statistics' => $statistics,
                'collection' => $collectionInfo,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/embeddings/sync
     * Sync embeddings for documents
     */
    public function sync(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true) ?? [];
            $documentIds = $data['document_ids'] ?? null;
            $all = $data['all'] ?? false;

            if (!$this->embeddingService->isAvailable()) {
                return $this->errorResponse($response, 'Embedding service not available (check API key)', 503);
            }

            if (!$this->vectorService->isAvailable()) {
                return $this->errorResponse($response, 'Qdrant not available', 503);
            }

            // Initialize collection
            $this->vectorService->initializeCollection();

            if ($all) {
                // Sync all pending documents
                $result = $this->vectorService->syncAll();
                return $this->successResponse($response, $result, 'Sync completed');
            }

            if (!empty($documentIds) && is_array($documentIds)) {
                // Sync specific documents
                $synced = 0;
                $failed = 0;

                foreach ($documentIds as $docId) {
                    $embedding = $this->embeddingService->embedDocument((int)$docId);
                    if ($embedding && $this->vectorService->upsertDocument((int)$docId, $embedding)) {
                        $synced++;
                    } else {
                        $failed++;
                    }
                }

                return $this->successResponse($response, [
                    'synced' => $synced,
                    'failed' => $failed,
                ], 'Sync completed');
            }

            return $this->errorResponse($response, 'Specify document_ids or set all=true');

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/search/semantic
     * Semantic search using embeddings
     */
    public function semanticSearch(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true) ?? [];
            $query = $data['query'] ?? '';
            $limit = min((int)($data['limit'] ?? 10), 100);
            $filters = $data['filters'] ?? [];

            if (empty($query)) {
                return $this->errorResponse($response, 'Query is required');
            }

            if (!$this->vectorService->isAvailable()) {
                return $this->errorResponse($response, 'Vector search not available', 503);
            }

            $startTime = microtime(true);
            $results = $this->vectorService->search($query, $limit, $filters);
            $searchTime = round((microtime(true) - $startTime) * 1000);

            // Enrich with document details
            $enrichedResults = $this->enrichResults($results);

            return $this->successResponse($response, [
                'query' => $query,
                'results' => $enrichedResults,
                'count' => count($enrichedResults),
                'search_time_ms' => $searchTime,
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/search/hybrid
     * Hybrid search combining semantic and keyword search
     */
    public function hybridSearch(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true) ?? [];
            $query = $data['query'] ?? '';
            $limit = min((int)($data['limit'] ?? 10), 100);
            $filters = $data['filters'] ?? [];
            $semanticWeight = (float)($data['semantic_weight'] ?? 0.7);

            if (empty($query)) {
                return $this->errorResponse($response, 'Query is required');
            }

            // Clamp weight between 0 and 1
            $semanticWeight = max(0, min(1, $semanticWeight));

            $startTime = microtime(true);
            $results = $this->vectorService->hybridSearch($query, $limit, $filters, $semanticWeight);
            $searchTime = round((microtime(true) - $startTime) * 1000);

            return $this->successResponse($response, [
                'query' => $query,
                'results' => $results,
                'count' => count($results),
                'search_time_ms' => $searchTime,
                'weights' => [
                    'semantic' => $semanticWeight,
                    'keyword' => 1 - $semanticWeight,
                ],
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/search/similar/{id}
     * Find similar documents
     */
    public function similar(Request $request, Response $response, array $args): Response
    {
        try {
            $documentId = (int)($args['id'] ?? 0);
            $queryParams = $request->getQueryParams();
            $limit = min((int)($queryParams['limit'] ?? 5), 20);

            if ($documentId <= 0) {
                return $this->errorResponse($response, 'Valid document ID is required');
            }

            if (!$this->vectorService->isAvailable()) {
                return $this->errorResponse($response, 'Vector search not available', 503);
            }

            $startTime = microtime(true);
            $results = $this->vectorService->findSimilar($documentId, $limit);
            $searchTime = round((microtime(true) - $startTime) * 1000);

            return $this->successResponse($response, [
                'source_document_id' => $documentId,
                'similar_documents' => $results,
                'count' => count($results),
                'search_time_ms' => $searchTime,
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/documents/{id}/embed
     * Generate embedding for a specific document
     */
    public function embedDocument(Request $request, Response $response, array $args): Response
    {
        try {
            $documentId = (int)($args['id'] ?? 0);

            if ($documentId <= 0) {
                return $this->errorResponse($response, 'Valid document ID is required');
            }

            if (!$this->embeddingService->isAvailable()) {
                return $this->errorResponse($response, 'Embedding service not available', 503);
            }

            $embedding = $this->embeddingService->embedDocument($documentId);

            if (!$embedding) {
                return $this->errorResponse($response, 'Failed to generate embedding');
            }

            // Store in Qdrant if available
            if ($this->vectorService->isAvailable()) {
                $this->vectorService->initializeCollection();
                $this->vectorService->upsertDocument($documentId, $embedding);
            }

            return $this->successResponse($response, [
                'document_id' => $documentId,
                'vector_dimensions' => count($embedding),
                'stored_in_qdrant' => $this->vectorService->isAvailable(),
            ], 'Embedding generated successfully');

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/documents/{id}/embed
     * Delete embedding for a document
     */
    public function deleteEmbedding(Request $request, Response $response, array $args): Response
    {
        try {
            $documentId = (int)($args['id'] ?? 0);

            if ($documentId <= 0) {
                return $this->errorResponse($response, 'Valid document ID is required');
            }

            $deleted = $this->vectorService->deleteDocument($documentId);

            // Update document status
            $db = \KDocs\Core\Database::getInstance();
            $stmt = $db->prepare("
                UPDATE documents
                SET embedding_status = NULL, vector_updated_at = NULL
                WHERE id = ?
            ");
            $stmt->execute([$documentId]);

            return $this->successResponse($response, [
                'document_id' => $documentId,
                'deleted' => $deleted,
            ], 'Embedding deleted');

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/embeddings/cleanup
     * Clean up embeddings for deleted documents
     */
    public function cleanup(Request $request, Response $response): Response
    {
        try {
            if (!$this->vectorService->isAvailable()) {
                return $this->errorResponse($response, 'Qdrant not available', 503);
            }

            $cleaned = $this->vectorService->cleanupDeleted();

            return $this->successResponse($response, [
                'cleaned' => $cleaned,
            ], "Cleaned up $cleaned embeddings");

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * Enrich search results with document details
     */
    private function enrichResults(array $results): array
    {
        if (empty($results)) {
            return [];
        }

        $db = \KDocs\Core\Database::getInstance();
        $enriched = [];

        foreach ($results as $result) {
            $docId = $result['document_id'];

            $stmt = $db->prepare("
                SELECT d.id, d.title, d.original_filename, d.file_path, d.mime_type,
                       d.document_date, d.created_at,
                       dt.label as document_type_label,
                       c.name as correspondent_name
                FROM documents d
                LEFT JOIN document_types dt ON d.document_type_id = dt.id
                LEFT JOIN correspondents c ON d.correspondent_id = c.id
                WHERE d.id = ? AND d.deleted_at IS NULL
            ");
            $stmt->execute([$docId]);
            $doc = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($doc) {
                $enriched[] = [
                    'document' => $doc,
                    'score' => round($result['score'], 4),
                    'similarity' => round($result['score'] * 100, 1) . '%',
                ];
            }
        }

        return $enriched;
    }
}

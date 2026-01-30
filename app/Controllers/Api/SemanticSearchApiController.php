<?php
/**
 * K-Docs - API Controller pour la Recherche Sémantique (Complémentaire)
 * Fonctionnalités supplémentaires de recherche sémantique
 */

namespace KDocs\Controllers\Api;

use KDocs\Services\VectorSearchService;
use KDocs\Services\EmbeddingService;
use KDocs\Core\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SemanticSearchApiController extends ApiController
{
    private VectorSearchService $vectorService;
    private EmbeddingService $embeddingService;

    public function __construct()
    {
        $this->vectorService = new VectorSearchService();
        $this->embeddingService = new EmbeddingService();
    }

    /**
     * GET /api/semantic-search/status
     * Statut complet du service de recherche sémantique
     */
    public function status(Request $request, Response $response): Response
    {
        try {
            $available = $this->vectorService->isAvailable();
            $embeddingAvailable = $this->embeddingService->isAvailable();

            $data = [
                'available' => $available && $embeddingAvailable,
                'qdrant_available' => $available,
                'embeddings_enabled' => $embeddingAvailable,
                'model_info' => $this->embeddingService->getModelInfo(),
            ];

            if ($available) {
                $data['collection'] = $this->vectorService->getCollectionInfo();
                $data['sync_status'] = $this->vectorService->getSyncStatus();
            }

            return $this->successResponse($response, $data);
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/semantic-search
     * Recherche sémantique avec options avancées
     */
    public function search(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true) ?? [];

            if (empty($data['query'])) {
                return $this->errorResponse($response, 'Query is required');
            }

            if (!$this->vectorService->isAvailable()) {
                return $this->errorResponse($response, 'Semantic search is not available', 503);
            }

            $query = $data['query'];
            $limit = min((int)($data['limit'] ?? 20), 100);
            $filters = $data['filter'] ?? [];
            $useHybrid = $data['hybrid'] ?? false;
            $semanticWeight = (float)($data['semantic_weight'] ?? 0.7);

            $startTime = microtime(true);

            if ($useHybrid) {
                $results = $this->vectorService->hybridSearch($query, $limit, $filters, $semanticWeight);
            } else {
                $results = $this->vectorService->search($query, $limit, $filters);
                $results = $this->enrichResults($results, $limit);
            }

            $searchTime = round((microtime(true) - $startTime) * 1000);

            // Log la recherche
            $this->logSearch($_SESSION['user_id'] ?? null, $query, count($results), $searchTime);

            return $this->successResponse($response, [
                'query' => $query,
                'results' => $results,
                'count' => count($results),
                'search_time_ms' => $searchTime,
                'mode' => $useHybrid ? 'hybrid' : 'semantic',
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/semantic-search/similar/{documentId}
     * Trouve les documents similaires
     */
    public function similar(Request $request, Response $response, array $args): Response
    {
        try {
            $documentId = (int)($args['documentId'] ?? 0);

            if ($documentId <= 0) {
                return $this->errorResponse($response, 'Valid document ID is required');
            }

            if (!$this->vectorService->isAvailable()) {
                return $this->errorResponse($response, 'Semantic search is not available', 503);
            }

            $queryParams = $request->getQueryParams();
            $limit = min((int)($queryParams['limit'] ?? 5), 20);

            $results = $this->vectorService->findSimilar($documentId, $limit);

            return $this->successResponse($response, [
                'document_id' => $documentId,
                'similar_documents' => $results,
                'count' => count($results),
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/semantic-search/index/{documentId}
     * Indexe un document spécifique
     */
    public function indexDocument(Request $request, Response $response, array $args): Response
    {
        try {
            $documentId = (int)($args['documentId'] ?? 0);

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

            $stored = false;
            if ($this->vectorService->isAvailable()) {
                $this->vectorService->initializeCollection();
                $stored = $this->vectorService->upsertDocument($documentId, $embedding);
            }

            return $this->successResponse($response, [
                'success' => true,
                'document_id' => $documentId,
                'dimensions' => count($embedding),
                'stored_in_qdrant' => $stored,
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/semantic-search/index/{documentId}
     * Supprime un document de l'index
     */
    public function removeDocument(Request $request, Response $response, array $args): Response
    {
        try {
            $documentId = (int)($args['documentId'] ?? 0);

            if ($documentId <= 0) {
                return $this->errorResponse($response, 'Valid document ID is required');
            }

            $deleted = $this->vectorService->deleteDocument($documentId);

            // Mettre à jour le statut
            $db = Database::getInstance();
            $stmt = $db->prepare("
                UPDATE documents
                SET embedding_status = NULL, vector_updated_at = NULL
                WHERE id = ?
            ");
            $stmt->execute([$documentId]);

            return $this->successResponse($response, [
                'success' => $deleted,
                'document_id' => $documentId,
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/semantic-search/sync
     * Synchronise tous les documents
     */
    public function sync(Request $request, Response $response): Response
    {
        try {
            if (!$this->vectorService->isAvailable()) {
                return $this->errorResponse($response, 'Qdrant not available', 503);
            }

            if (!$this->embeddingService->isAvailable()) {
                return $this->errorResponse($response, 'Embedding service not available', 503);
            }

            set_time_limit(0);

            $this->vectorService->initializeCollection();
            $results = $this->vectorService->syncAll();

            return $this->successResponse($response, $results, 'Sync completed');

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/semantic-search/stats
     * Statistiques de recherche sémantique
     */
    public function stats(Request $request, Response $response): Response
    {
        try {
            $db = Database::getInstance();
            $stats = [];

            // Documents indexés
            if ($this->vectorService->isAvailable()) {
                $info = $this->vectorService->getCollectionInfo();
                $stats['indexed_count'] = $info['vectors_count'] ?? 0;
            }

            // Stats des embeddings
            $stats['embedding_stats'] = $this->embeddingService->getStatistics();

            // Recherches récentes (si table existe)
            try {
                $stmt = $db->query("
                    SELECT
                        COUNT(*) as total_searches,
                        AVG(search_time_ms) as avg_search_time
                    FROM semantic_search_logs
                    WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ");
                $stats['recent_searches'] = $stmt->fetch(\PDO::FETCH_ASSOC);
            } catch (\Exception $e) {
                // Table might not exist
            }

            return $this->successResponse($response, $stats);

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/semantic-search/feedback
     * Enregistre le feedback utilisateur
     */
    public function feedback(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true) ?? [];

            $documentId = $data['document_id'] ?? null;
            $helpful = $data['helpful'] ?? null;

            if (!$documentId || $helpful === null) {
                return $this->errorResponse($response, 'document_id and helpful are required');
            }

            $db = Database::getInstance();

            try {
                $stmt = $db->prepare("
                    UPDATE semantic_search_logs
                    SET clicked_document_id = :doc_id, feedback_helpful = :helpful
                    WHERE id = (SELECT MAX(id) FROM semantic_search_logs WHERE user_id = :user_id)
                ");
                $stmt->execute([
                    'doc_id' => $documentId,
                    'helpful' => $helpful ? 1 : 0,
                    'user_id' => $_SESSION['user_id'] ?? 0,
                ]);
            } catch (\Exception $e) {
                // Table might not exist
            }

            return $this->successResponse($response, ['success' => true]);

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * Enrichit les résultats avec les données des documents
     */
    private function enrichResults(array $results, int $limit): array
    {
        if (empty($results)) {
            return [];
        }

        $db = Database::getInstance();
        $enriched = [];

        foreach ($results as $result) {
            $docId = $result['document_id'];

            $stmt = $db->prepare("
                SELECT d.id, d.title, d.original_filename, d.mime_type, d.doc_date, d.created_at,
                       dt.label as document_type_label,
                       c.name as correspondent_name
                FROM documents d
                LEFT JOIN document_types dt ON d.document_type_id = dt.id
                LEFT JOIN correspondents c ON d.correspondent_id = c.id
                WHERE d.id = ? AND d.is_deleted = 0
            ");
            $stmt->execute([$docId]);
            $doc = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($doc) {
                $enriched[] = [
                    'document' => $doc,
                    'score' => round($result['score'], 4),
                    'relevance' => round($result['score'] * 100, 1) . '%',
                ];
            }
        }

        return array_slice($enriched, 0, $limit);
    }

    /**
     * Log une recherche
     */
    private function logSearch(?int $userId, string $query, int $resultCount, int $searchTimeMs): void
    {
        try {
            $db = Database::getInstance();

            $stmt = $db->prepare("
                INSERT INTO semantic_search_logs
                (user_id, query_text, result_count, search_time_ms)
                VALUES (:user_id, :query, :count, :time)
            ");

            $stmt->execute([
                'user_id' => $userId,
                'query' => $query,
                'count' => $resultCount,
                'time' => $searchTimeMs,
            ]);
        } catch (\Exception $e) {
            // Table might not exist
        }
    }
}

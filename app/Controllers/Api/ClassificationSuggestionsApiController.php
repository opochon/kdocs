<?php
/**
 * K-Docs - Classification Suggestions API Controller
 * API REST pour les suggestions de classification ML
 */

namespace KDocs\Controllers\Api;

use KDocs\Models\ClassificationSuggestion;
use KDocs\Services\Learning\ClassificationLearningService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ClassificationSuggestionsApiController extends ApiController
{
    /**
     * GET /api/documents/{id}/suggestions
     * Récupère les suggestions pour un document
     */
    public function getForDocument(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['id'];

        $learningService = new ClassificationLearningService();
        $suggestions = $learningService->getDocumentSuggestions($documentId);

        return $this->successResponse($response, $suggestions);
    }

    /**
     * POST /api/documents/{id}/suggestions/generate
     * Génère des suggestions ML pour un document
     */
    public function generate(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['id'];
        $data = $request->getParsedBody();
        $user = $request->getAttribute('user');

        $autoApply = $data['auto_apply'] ?? false;

        $learningService = new ClassificationLearningService();
        $result = $learningService->generateSuggestions($documentId, $autoApply, $user['id'] ?? null);

        return $this->successResponse($response, $result);
    }

    /**
     * POST /api/documents/{documentId}/suggestions/{suggestionId}/apply
     * Applique une suggestion
     */
    public function apply(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['documentId'];
        $suggestionId = (int)$args['suggestionId'];
        $user = $request->getAttribute('user');

        // Vérifier que la suggestion appartient bien au document
        $suggestion = ClassificationSuggestion::find($suggestionId);
        if (!$suggestion || $suggestion['document_id'] != $documentId) {
            return $this->errorResponse($response, 'Suggestion non trouvée', 404);
        }

        $learningService = new ClassificationLearningService();
        $result = $learningService->applySuggestion($suggestionId, $user['id'] ?? 0);

        if (isset($result['error'])) {
            return $this->errorResponse($response, $result['error']);
        }

        return $this->successResponse($response, $result, 'Suggestion appliquée');
    }

    /**
     * POST /api/documents/{documentId}/suggestions/{suggestionId}/ignore
     * Ignore une suggestion
     */
    public function ignore(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['documentId'];
        $suggestionId = (int)$args['suggestionId'];

        // Vérifier que la suggestion appartient bien au document
        $suggestion = ClassificationSuggestion::find($suggestionId);
        if (!$suggestion || $suggestion['document_id'] != $documentId) {
            return $this->errorResponse($response, 'Suggestion non trouvée', 404);
        }

        $learningService = new ClassificationLearningService();
        $learningService->ignoreSuggestion($suggestionId);

        return $this->successResponse($response, ['id' => $suggestionId], 'Suggestion ignorée');
    }

    /**
     * POST /api/documents/{id}/suggestions/apply-all
     * Applique toutes les suggestions pendantes d'un document
     */
    public function applyAll(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['id'];
        $user = $request->getAttribute('user');

        $suggestions = ClassificationSuggestion::getForDocument($documentId, 'pending');

        $results = [
            'applied' => [],
            'failed' => []
        ];

        $learningService = new ClassificationLearningService();

        foreach ($suggestions as $suggestion) {
            $result = $learningService->applySuggestion($suggestion['id'], $user['id'] ?? 0);

            if (isset($result['error'])) {
                $results['failed'][] = [
                    'id' => $suggestion['id'],
                    'field' => $suggestion['field_code'],
                    'error' => $result['error']
                ];
            } else {
                $results['applied'][] = [
                    'id' => $suggestion['id'],
                    'field' => $suggestion['field_code'],
                    'value' => $suggestion['suggested_value']
                ];
            }
        }

        return $this->successResponse($response, $results);
    }

    /**
     * POST /api/documents/{id}/suggestions/ignore-all
     * Ignore toutes les suggestions pendantes d'un document
     */
    public function ignoreAll(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['id'];

        $suggestions = ClassificationSuggestion::getForDocument($documentId, 'pending');

        foreach ($suggestions as $suggestion) {
            ClassificationSuggestion::ignore($suggestion['id']);
        }

        return $this->successResponse($response, [
            'ignored_count' => count($suggestions)
        ]);
    }

    /**
     * GET /api/suggestions/pending
     * Liste toutes les suggestions pendantes (admin)
     */
    public function listPending(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $limit = min(100, max(10, (int)($queryParams['limit'] ?? 50)));

        $suggestions = ClassificationSuggestion::getPending($limit);

        return $this->successResponse($response, $suggestions);
    }

    /**
     * GET /api/suggestions/stats
     * Statistiques des suggestions
     */
    public function stats(Request $request, Response $response): Response
    {
        $learningService = new ClassificationLearningService();
        $stats = $learningService->getStats();

        return $this->successResponse($response, $stats);
    }
}

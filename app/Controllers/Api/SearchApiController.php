<?php
namespace KDocs\Controllers\Api;

use KDocs\Services\AISearchService;
use KDocs\Services\NaturalLanguageQueryService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SearchApiController extends ApiController
{
    private AISearchService $searchService;
    private NaturalLanguageQueryService $nlQueryService;
    
    public function __construct()
    {
        $this->searchService = new AISearchService();
        $this->nlQueryService = new NaturalLanguageQueryService();
    }
    
    /**
     * POST /api/search/ask
     * Question en langage naturel (utilise maintenant NaturalLanguageQueryService)
     */
    public function ask(Request $request, Response $response): Response
    {
        try {
            $body = $request->getBody()->getContents();
            $data = json_decode($body, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->jsonResponse($response, ['error' => 'JSON invalide: ' . json_last_error_msg()], 400);
            }
            
            $question = $data['question'] ?? '';

            if (empty($question)) {
                return $this->jsonResponse($response, ['error' => 'Question requise'], 400);
            }

            // Build search options from request
            $searchOptions = [
                'scope' => $data['scope'] ?? 'all',
                'date_from' => $data['date_from'] ?? null,
                'date_to' => $data['date_to'] ?? null,
                'folder_id' => $data['folder_id'] ?? null,
            ];

            // Utiliser le nouveau NaturalLanguageQueryService
            $result = $this->nlQueryService->query($question, $searchOptions);
            
            // Convertir SearchResult en format attendu par le frontend
            return $this->jsonResponse($response, [
                'answer' => $result->aiResponse ?? "J'ai trouvé {$result->total} document(s).",
                'documents' => $result->documents,
                'count' => $result->total,
                'filters_used' => $result->query ? ['text_search' => $result->query] : [],
                'search_time' => $result->searchTime,
                'facets' => [
                    'correspondents' => $result->correspondentFacets,
                    'document_types' => $result->documentTypeFacets,
                    'tags' => $result->tagFacets,
                    'years' => $result->yearFacets,
                ]
            ]);
        } catch (\Exception $e) {
            error_log("NL Query error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            
            // Fallback sur l'ancien service si erreur
            try {
                $result = $this->searchService->askQuestion($question);
                return $this->jsonResponse($response, $result);
            } catch (\Exception $e2) {
                return $this->jsonResponse($response, ['error' => 'Erreur serveur: ' . $e->getMessage()], 500);
            }
        }
    }
    
    /**
     * GET /api/search/quick?q=xxx
     * Recherche rapide (dropdown)
     */
    public function quick(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $query = $queryParams['q'] ?? '';
        
        if (strlen($query) < 2) {
            return $this->jsonResponse($response, ['results' => []]);
        }
        
        try {
            $results = $this->searchService->quickSearch($query);
            return $this->jsonResponse($response, ['results' => $results]);
        } catch (\Exception $e) {
            error_log("QuickSearch error: " . $e->getMessage());
            return $this->jsonResponse($response, ['error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * GET /api/search/reference?ref=xxx
     * Trouver un document par référence
     */
    public function reference(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $ref = $queryParams['ref'] ?? '';
        
        if (empty($ref)) {
            return $this->jsonResponse($response, ['error' => 'Référence requise'], 400);
        }
        
        try {
            $documents = $this->searchService->findReference($ref);
            return $this->jsonResponse($response, [
                'reference' => $ref,
                'count' => count($documents),
                'documents' => $documents
            ]);
        } catch (\Exception $e) {
            error_log("ReferenceSearch error: " . $e->getMessage());
            return $this->jsonResponse($response, ['error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * GET /api/documents/{id}/summary
     * Résumé d'un document
     */
    public function summary(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        
        if ($id <= 0) {
            return $this->jsonResponse($response, ['error' => 'ID de document invalide'], 400);
        }
        
        try {
            $summary = $this->searchService->summarizeDocument($id);
            
            if (!$summary) {
                return $this->jsonResponse($response, ['error' => 'Impossible de résumer ce document'], 400);
            }
            
            return $this->jsonResponse($response, ['summary' => $summary]);
        } catch (\Exception $e) {
            error_log("Summary error: " . $e->getMessage());
            return $this->jsonResponse($response, ['error' => $e->getMessage()], 500);
        }
    }
}

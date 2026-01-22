<?php
namespace KDocs\Controllers\Api;

use KDocs\Services\AISearchService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SearchApiController extends ApiController
{
    private AISearchService $searchService;
    
    public function __construct()
    {
        parent::__construct();
        $this->searchService = new AISearchService();
    }
    
    /**
     * POST /api/search/ask
     * Question en langage naturel
     */
    public function ask(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        $question = $data['question'] ?? '';
        
        if (empty($question)) {
            return $this->jsonResponse($response, ['error' => 'Question requise'], 400);
        }
        
        try {
            $result = $this->searchService->askQuestion($question);
            return $this->jsonResponse($response, $result);
        } catch (\Exception $e) {
            error_log("AISearch error: " . $e->getMessage());
            return $this->jsonResponse($response, ['error' => $e->getMessage()], 500);
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

<?php
/**
 * K-Docs - AI Provider API Controller
 * Gestion et monitoring des providers IA
 */

namespace KDocs\Controllers\Api;

use KDocs\Services\AIProviderService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AIProviderApiController extends ApiController
{
    private AIProviderService $aiProvider;

    public function __construct()
    {
        $this->aiProvider = new AIProviderService();
    }

    /**
     * GET /api/ai/status
     * Statut de tous les providers IA
     */
    public function status(Request $request, Response $response): Response
    {
        $status = $this->aiProvider->getProvidersStatus();
        
        return $this->successResponse($response, $status);
    }

    /**
     * POST /api/ai/test
     * Test du provider actif
     */
    public function test(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true) ?? [];
        $text = $data['text'] ?? 'Ceci est un test de classification pour une facture EDF de janvier 2024.';

        $provider = $this->aiProvider->getActiveProvider();
        
        $startTime = microtime(true);
        $summary = $this->aiProvider->summarize($text, 100);
        $duration = round((microtime(true) - $startTime) * 1000);

        return $this->successResponse($response, [
            'provider' => $provider,
            'test_type' => 'summarize',
            'input_length' => strlen($text),
            'output' => $summary,
            'duration_ms' => $duration,
            'success' => !empty($summary),
        ]);
    }

    /**
     * POST /api/ai/classify/{documentId}
     * Classification via le provider actif
     */
    public function classify(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)($args['documentId'] ?? 0);

        if ($documentId <= 0) {
            return $this->errorResponse($response, 'Document ID required');
        }

        $startTime = microtime(true);
        $result = $this->aiProvider->classifyDocument($documentId);
        $result['duration_ms'] = round((microtime(true) - $startTime) * 1000);

        return $this->successResponse($response, $result);
    }

    /**
     * POST /api/ai/extract/{documentId}
     * Extraction via le provider actif
     */
    public function extract(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)($args['documentId'] ?? 0);

        if ($documentId <= 0) {
            return $this->errorResponse($response, 'Document ID required');
        }

        $startTime = microtime(true);
        $result = $this->aiProvider->extractMetadata($documentId);
        $result['duration_ms'] = round((microtime(true) - $startTime) * 1000);

        return $this->successResponse($response, $result);
    }

    /**
     * POST /api/ai/chat
     * Chat via le provider actif
     */
    public function chat(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true) ?? [];
        
        $message = $data['message'] ?? '';
        $context = $data['context'] ?? [];

        if (empty($message)) {
            return $this->errorResponse($response, 'Message required');
        }

        $startTime = microtime(true);
        $reply = $this->aiProvider->chat($message, $context);
        $duration = round((microtime(true) - $startTime) * 1000);

        return $this->successResponse($response, [
            'provider' => $this->aiProvider->getActiveProvider(),
            'message' => $message,
            'reply' => $reply,
            'duration_ms' => $duration,
        ]);
    }

    /**
     * POST /api/ai/refresh
     * Force le refresh du cache de disponibilité
     */
    public function refresh(Request $request, Response $response): Response
    {
        AIProviderService::clearCache();
        
        $newProvider = new AIProviderService();
        $status = $newProvider->getProvidersStatus();

        return $this->successResponse($response, [
            'message' => 'Cache cleared',
            'status' => $status,
        ]);
    }
}

<?php
/**
 * K-Docs - AI Status API Controller
 * Endpoint pour vérifier le statut des providers IA
 */

namespace KDocs\Controllers\Api;

use KDocs\Services\AIProviderService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AIStatusApiController extends ApiController
{
    /**
     * GET /api/ai/status
     * Statut complet des providers IA
     */
    public function status(Request $request, Response $response): Response
    {
        $aiProvider = new AIProviderService();
        $status = $aiProvider->getStatus();
        
        // Ajouter les recommandations si besoin
        if (!$status['claude']['available'] && !$status['ollama']['available']) {
            $status['recommendations'] = [
                'message' => "Aucun provider IA disponible. L'application fonctionnera en mode limité.",
                'options' => [
                    [
                        'name' => 'Claude API',
                        'description' => 'Meilleure qualité, payant',
                        'setup' => 'Ajouter la clé API dans claude_api_key.txt ou config.php',
                    ],
                    [
                        'name' => 'Ollama',
                        'description' => 'Gratuit, local, qualité acceptable',
                        'setup' => 'ollama pull llama3.2 && ollama pull nomic-embed-text',
                    ],
                ],
            ];
        } elseif ($status['fallback_active']) {
            $status['info'] = [
                'message' => "Claude non disponible, Ollama utilisé en fallback.",
                'quality' => 'acceptable',
            ];
        }
        
        return $this->successResponse($response, $status);
    }
    
    /**
     * POST /api/ai/test
     * Test rapide du provider actif
     */
    public function test(Request $request, Response $response): Response
    {
        $aiProvider = new AIProviderService();
        
        if (!$aiProvider->isAIAvailable()) {
            return $this->errorResponse($response, 'Aucun provider IA disponible', 503);
        }
        
        $startTime = microtime(true);
        
        $result = $aiProvider->complete(
            "Réponds uniquement par 'OK' si tu fonctionnes correctement.",
            ['max_tokens' => 50]
        );
        
        $duration = round((microtime(true) - $startTime) * 1000);
        
        if (!$result) {
            return $this->errorResponse($response, 'Test échoué', 500);
        }
        
        return $this->successResponse($response, [
            'success' => true,
            'provider' => $result['provider'],
            'model' => $result['model'],
            'response' => $result['text'],
            'duration_ms' => $duration,
        ]);
    }
    
    /**
     * POST /api/ai/refresh
     * Force le refresh du cache de détection
     */
    public function refresh(Request $request, Response $response): Response
    {
        AIProviderService::resetCache();
        
        $aiProvider = new AIProviderService();
        $status = $aiProvider->getStatus();
        
        return $this->successResponse($response, [
            'message' => 'Cache rafraîchi',
            'status' => $status,
        ]);
    }
}

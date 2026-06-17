<?php
/**
 * K-DOCS - Rate Limiter
 * Protection contre les abus d'API
 */

namespace KDocs\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Server\MiddlewareInterface;

class RateLimitMiddleware implements MiddlewareInterface
{
    private int $maxRequests;
    private int $windowSeconds;
    private string $storagePath;
    
    /**
     * @param int $maxRequests Nombre max de requêtes par fenêtre
     * @param int $windowSeconds Durée de la fenêtre en secondes
     */
    public function __construct(int $maxRequests = 100, int $windowSeconds = 60)
    {
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
        $this->storagePath = dirname(__DIR__, 2) . '/storage/cache/ratelimit';
        
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
    }
    
    public function process(Request $request, Handler $handler): Response
    {
        $identifier = $this->getIdentifier($request);
        $key = $this->getKey($identifier);
        
        $data = $this->getData($key);
        $now = time();
        
        // Nettoyer les vieilles entrées
        $data = array_filter($data, fn($ts) => $ts > ($now - $this->windowSeconds));
        
        // Vérifier la limite
        if (count($data) >= $this->maxRequests) {
            $retryAfter = min($data) + $this->windowSeconds - $now;
            
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write(json_encode([
                'error' => 'Rate limit exceeded',
                'retry_after' => $retryAfter,
                'limit' => $this->maxRequests,
                'window' => $this->windowSeconds,
            ]));
            
            return $response
                ->withStatus(429)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Retry-After', (string) $retryAfter)
                ->withHeader('X-RateLimit-Limit', (string) $this->maxRequests)
                ->withHeader('X-RateLimit-Remaining', '0')
                ->withHeader('X-RateLimit-Reset', (string) (min($data) + $this->windowSeconds));
        }
        
        // Enregistrer cette requête
        $data[] = $now;
        $this->setData($key, $data);
        
        // Continuer
        $response = $handler->handle($request);
        
        // Ajouter headers informatifs
        return $response
            ->withHeader('X-RateLimit-Limit', (string) $this->maxRequests)
            ->withHeader('X-RateLimit-Remaining', (string) ($this->maxRequests - count($data)))
            ->withHeader('X-RateLimit-Reset', (string) ($now + $this->windowSeconds));
    }
    
    /**
     * Vérifie sans bloquer (pour usage manuel)
     */
    public function check(string $identifier): array
    {
        $key = $this->getKey($identifier);
        $data = $this->getData($key);
        $now = time();
        
        $data = array_filter($data, fn($ts) => $ts > ($now - $this->windowSeconds));
        
        return [
            'allowed' => count($data) < $this->maxRequests,
            'remaining' => max(0, $this->maxRequests - count($data)),
            'limit' => $this->maxRequests,
            'reset' => $now + $this->windowSeconds,
        ];
    }
    
    /**
     * Reset le compteur pour un identifiant
     */
    public function reset(string $identifier): void
    {
        $key = $this->getKey($identifier);
        $filepath = $this->storagePath . '/' . $key . '.json';
        
        if (file_exists($filepath)) {
            unlink($filepath);
        }
    }
    
    /**
     * Nettoie les vieux fichiers de rate limit
     */
    public function cleanup(): int
    {
        $files = glob($this->storagePath . '/*.json');
        $deleted = 0;
        $cutoff = time() - ($this->windowSeconds * 2);
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $deleted++;
            }
        }
        
        return $deleted;
    }
    
    private function getIdentifier(Request $request): string
    {
        // Priorité: User ID > API Key > IP
        $userId = $request->getAttribute('user_id');
        if ($userId) {
            return 'user_' . $userId;
        }
        
        $apiKey = $request->getHeaderLine('X-API-Key');
        if ($apiKey) {
            return 'api_' . substr(md5($apiKey), 0, 16);
        }
        
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
        return 'ip_' . $ip;
    }
    
    private function getKey(string $identifier): string
    {
        return md5($identifier);
    }
    
    private function getData(string $key): array
    {
        $filepath = $this->storagePath . '/' . $key . '.json';
        
        if (!file_exists($filepath)) {
            return [];
        }
        
        $data = json_decode(file_get_contents($filepath), true);
        return is_array($data) ? $data : [];
    }
    
    private function setData(string $key, array $data): void
    {
        $filepath = $this->storagePath . '/' . $key . '.json';
        file_put_contents($filepath, json_encode(array_values($data)));
    }
}

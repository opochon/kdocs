<?php
/**
 * K-Docs - Rate Limit Middleware
 * Protection contre les abus par limitation de requêtes
 */

namespace KDocs\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * Configuration par défaut
     */
    private int $maxRequests;      // Nombre max de requêtes
    private int $windowSeconds;    // Fenêtre de temps en secondes
    private string $storageDir;    // Répertoire de stockage

    public function __construct(
        int $maxRequests = 100,
        int $windowSeconds = 60,
        ?string $storageDir = null
    ) {
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
        $this->storageDir = $storageDir ?? sys_get_temp_dir() . '/kdocs_ratelimit';

        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $identifier = $this->getIdentifier($request);
        $record = $this->getRecord($identifier);

        // Nettoyer les anciennes entrées
        $record = $this->cleanOldEntries($record);

        // Vérifier la limite
        if (count($record['requests']) >= $this->maxRequests) {
            return $this->tooManyRequests($record);
        }

        // Ajouter la requête actuelle
        $record['requests'][] = time();
        $this->saveRecord($identifier, $record);

        // Calculer les headers
        $remaining = $this->maxRequests - count($record['requests']);
        $resetTime = !empty($record['requests'])
            ? min($record['requests']) + $this->windowSeconds
            : time() + $this->windowSeconds;

        // Exécuter la requête
        $response = $handler->handle($request);

        // Ajouter les headers de rate limit
        return $response
            ->withHeader('X-RateLimit-Limit', (string)$this->maxRequests)
            ->withHeader('X-RateLimit-Remaining', (string)max(0, $remaining))
            ->withHeader('X-RateLimit-Reset', (string)$resetTime);
    }

    /**
     * Identifie le client (IP + User-Agent hash)
     */
    private function getIdentifier(Request $request): string
    {
        $ip = $this->getClientIp($request);
        $userAgent = $request->getHeaderLine('User-Agent');

        // Utiliser l'ID utilisateur si connecté
        $userId = $request->getAttribute('user_id');
        if ($userId) {
            return 'user_' . $userId;
        }

        return 'ip_' . md5($ip . $userAgent);
    }

    /**
     * Récupère l'IP du client
     */
    private function getClientIp(Request $request): string
    {
        $headers = [
            'X-Forwarded-For',
            'X-Real-IP',
            'CF-Connecting-IP',
        ];

        foreach ($headers as $header) {
            $value = $request->getHeaderLine($header);
            if (!empty($value)) {
                // Prendre la première IP si plusieurs
                $ips = explode(',', $value);
                return trim($ips[0]);
            }
        }

        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Récupère l'enregistrement de rate limit
     */
    private function getRecord(string $identifier): array
    {
        $file = $this->getFilePath($identifier);

        if (file_exists($file)) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);
            if (is_array($data)) {
                return $data;
            }
        }

        return ['requests' => []];
    }

    /**
     * Sauvegarde l'enregistrement
     */
    private function saveRecord(string $identifier, array $record): void
    {
        $file = $this->getFilePath($identifier);
        file_put_contents($file, json_encode($record), LOCK_EX);
    }

    /**
     * Retourne le chemin du fichier pour un identifiant
     */
    private function getFilePath(string $identifier): string
    {
        return $this->storageDir . '/' . $identifier . '.json';
    }

    /**
     * Nettoie les entrées expirées
     */
    private function cleanOldEntries(array $record): array
    {
        $cutoff = time() - $this->windowSeconds;

        $record['requests'] = array_filter(
            $record['requests'],
            fn($timestamp) => $timestamp > $cutoff
        );

        return $record;
    }

    /**
     * Retourne une réponse 429 Too Many Requests
     */
    private function tooManyRequests(array $record): Response
    {
        $response = new SlimResponse();

        $resetTime = !empty($record['requests'])
            ? min($record['requests']) + $this->windowSeconds
            : time() + $this->windowSeconds;

        $retryAfter = max(1, $resetTime - time());

        $body = json_encode([
            'success' => false,
            'error' => 'Trop de requêtes. Veuillez réessayer dans ' . $retryAfter . ' secondes.',
            'code' => 429,
            'retry_after' => $retryAfter
        ]);

        $response->getBody()->write($body);

        return $response
            ->withStatus(429)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Retry-After', (string)$retryAfter)
            ->withHeader('X-RateLimit-Limit', (string)$this->maxRequests)
            ->withHeader('X-RateLimit-Remaining', '0')
            ->withHeader('X-RateLimit-Reset', (string)$resetTime);
    }

    /**
     * Nettoie les fichiers expirés (à appeler périodiquement)
     */
    public function cleanup(): int
    {
        $count = 0;
        $cutoff = time() - ($this->windowSeconds * 2);

        foreach (glob($this->storageDir . '/*.json') as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $count++;
            }
        }

        return $count;
    }
}

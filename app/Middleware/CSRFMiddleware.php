<?php
/**
 * K-Docs - CSRF Middleware
 * Valide les tokens CSRF sur les requêtes modificatrices
 */

namespace KDocs\Middleware;

use KDocs\Core\CSRF;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class CSRFMiddleware implements MiddlewareInterface
{
    /**
     * Méthodes HTTP qui nécessitent validation CSRF
     */
    private const PROTECTED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Routes exemptées (API avec auth bearer, webhooks, etc.)
     */
    private array $exemptRoutes = [
        '/api/',           // API utilise authentification
        '/webhook/',       // Webhooks externes
        '/cron/',          // Tâches cron
    ];

    /**
     * Routes exemptées supplémentaires (configurables)
     */
    private array $customExemptRoutes = [];

    public function __construct(array $exemptRoutes = [])
    {
        $this->customExemptRoutes = $exemptRoutes;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        // Vérifier si la méthode nécessite protection
        if (!in_array($method, self::PROTECTED_METHODS)) {
            return $handler->handle($request);
        }

        // Vérifier si la route est exemptée
        if ($this->isExemptRoute($path)) {
            return $handler->handle($request);
        }

        // Récupérer le token depuis le body ou les headers
        $token = $this->getTokenFromRequest($request);

        // Valider le token
        if (!CSRF::validateToken($token)) {
            return $this->forbidden('Token CSRF invalide ou manquant');
        }

        // Régénérer le token pour la prochaine requête
        CSRF::generateToken();

        return $handler->handle($request);
    }

    /**
     * Vérifie si la route est exemptée de validation CSRF
     */
    private function isExemptRoute(string $path): bool
    {
        $allExemptRoutes = array_merge($this->exemptRoutes, $this->customExemptRoutes);

        foreach ($allExemptRoutes as $route) {
            if (strpos($path, $route) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Récupère le token depuis la requête
     */
    private function getTokenFromRequest(Request $request): ?string
    {
        // 1. Essayer depuis le body (formulaires)
        $body = $request->getParsedBody();
        if (is_array($body) && isset($body[CSRF::getTokenName()])) {
            return $body[CSRF::getTokenName()];
        }

        // 2. Essayer depuis les headers (AJAX)
        $headerToken = $request->getHeaderLine('X-CSRF-Token');
        if (!empty($headerToken)) {
            return $headerToken;
        }

        // 3. Essayer depuis X-Requested-With avec meta tag
        $xRequestedWith = $request->getHeaderLine('X-Requested-With');
        if ($xRequestedWith === 'XMLHttpRequest') {
            // Pour AJAX, accepter le token depuis le header
            return $request->getHeaderLine('X-CSRF-Token');
        }

        return null;
    }

    /**
     * Retourne une réponse 403 Forbidden
     */
    private function forbidden(string $message): Response
    {
        $response = new SlimResponse();

        // Déterminer le format de réponse
        $body = json_encode([
            'success' => false,
            'error' => $message,
            'code' => 403
        ]);

        $response->getBody()->write($body);

        return $response
            ->withStatus(403)
            ->withHeader('Content-Type', 'application/json');
    }
}

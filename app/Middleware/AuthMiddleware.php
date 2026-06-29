<?php
/**
 * K-Docs - Middleware d'authentification
 */

namespace KDocs\Middleware;

use KDocs\Core\Auth;
use KDocs\Core\Config;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class AuthMiddleware implements MiddlewareInterface
{
    /**
     * Vérifie si l'utilisateur est authentifié
     */
    public function process(Request $request, RequestHandler $handler): Response
    {
        
        $sessionId = $_COOKIE['kdocs_session'] ?? null;
        $user = $sessionId ? Auth::getUserFromSession($sessionId) : null;


        if (!$user) {
            $basePath = Config::basePath();
            $path = $request->getUri()->getPath();
            $apiPath = $basePath !== '' ? str_replace($basePath, '', $path) : $path;
            if (str_starts_with($apiPath, '/api/')) {
                $response = new \Slim\Psr7\Response();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Non authentifié',
                ], JSON_UNESCAPED_UNICODE));
                return $response
                    ->withHeader('Content-Type', 'application/json; charset=utf-8')
                    ->withStatus(401);
            }

            $response = new \Slim\Psr7\Response();
            return $response
                ->withHeader('Location', $basePath . '/login')
                ->withStatus(302);
        }

        // Ajouter l'utilisateur à la requête
        $request = $request->withAttribute('user', $user);
        $request = $request->withAttribute('user_id', $user['id'] ?? null);


        return $handler->handle($request);
    }
}

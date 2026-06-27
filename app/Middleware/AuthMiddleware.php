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
            // Rediriger vers login
            $basePath = Config::basePath();
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

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
        // #region agent log
        \KDocs\Core\DebugLogger::log('AuthMiddleware::process', 'Middleware entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'D');
        // #endregion
        
        $sessionId = $_COOKIE['kdocs_session'] ?? null;
        $user = $sessionId ? Auth::getUserFromSession($sessionId) : null;

        // #region agent log
        \KDocs\Core\DebugLogger::log('AuthMiddleware::process', 'User check', [
            'userFound' => $user !== null,
            'userId' => $user['id'] ?? null,
            'hasSessionId' => $sessionId !== null
        ], 'D');
        // #endregion

        if (!$user) {
            // #region agent log
            \KDocs\Core\DebugLogger::log('AuthMiddleware::process', 'User not authenticated, redirecting', [
                'path' => $request->getUri()->getPath()
            ], 'D');
            // #endregion
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

        // #region agent log
        \KDocs\Core\DebugLogger::log('AuthMiddleware::process', 'Middleware exit', [
            'path' => $request->getUri()->getPath(),
            'userId' => $user['id']
        ], 'D');
        // #endregion

        return $handler->handle($request);
    }
}

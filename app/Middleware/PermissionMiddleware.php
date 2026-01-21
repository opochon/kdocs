<?php
/**
 * K-Docs - Middleware de vérification des permissions
 */

namespace KDocs\Middleware;

use KDocs\Models\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class PermissionMiddleware implements MiddlewareInterface
{
    private $requiredPermission;

    public function __construct(string $requiredPermission)
    {
        $this->requiredPermission = $requiredPermission;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $user = $request->getAttribute('user');
        
        if (!$user) {
            $basePath = \KDocs\Core\Config::basePath();
            return (new \Slim\Psr7\Response())
                ->withHeader('Location', $basePath . '/login')
                ->withStatus(302);
        }
        
        if (!User::hasPermission($user, $this->requiredPermission)) {
            $basePath = \KDocs\Core\Config::basePath();
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write('
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Accès refusé - K-Docs</title>
                    <style>
                        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
                        h1 { color: #dc2626; }
                    </style>
                </head>
                <body>
                    <h1>Accès refusé</h1>
                    <p>Vous n\'avez pas la permission d\'accéder à cette ressource.</p>
                    <a href="' . $basePath . '/documents">Retour aux documents</a>
                </body>
                </html>
            ');
            return $response->withStatus(403);
        }
        
        return $handler->handle($request);
    }
}

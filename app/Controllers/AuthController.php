<?php
/**
 * K-Docs - Contrôleur d'authentification
 */

namespace KDocs\Controllers;

use KDocs\Core\Auth;
use KDocs\Core\Config;
use KDocs\Services\AuditService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    /**
     * Helper pour rendre un template
     */
    private function renderTemplate(string $templatePath, array $data = []): string
    {
        extract($data);
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }

    /**
     * Affiche la page de login
     */
    public function showLogin(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('AuthController::showLogin', 'Controller entry', [
            'path' => $request->getUri()->getPath()
        ], 'A');
        // #endregion
        
        // Si déjà connecté, rediriger vers le dashboard
        $sessionId = $_COOKIE['kdocs_session'] ?? null;
        if ($sessionId && Auth::getUserFromSession($sessionId)) {
            $basePath = Config::basePath();
            return $response
                ->withHeader('Location', $basePath . '/')
                ->withStatus(302);
        }

        $basePath = Config::basePath();
        $loginContent = $this->renderTemplate(__DIR__ . '/../../templates/auth/login.php', [
            'error' => null,
            'username' => '',
            'basePath' => $basePath
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/auth.php', [
            'title' => 'Connexion - K-Docs',
            'content' => $loginContent
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Traite la connexion
     */
    public function login(Request $request, Response $response): Response
    {
        // #region agent log
        $data = $request->getParsedBody();
        $username = $data['username'] ?? '';
        \KDocs\Core\DebugLogger::log('AuthController::login', 'Login attempt', [
            'username' => $username,
            'hasPassword' => !empty($data['password'] ?? '')
        ], 'B');
        // #endregion
        
        $password = $data['password'] ?? '';

        $user = Auth::attempt($username, $password);
        
        // #region agent log
        \KDocs\Core\DebugLogger::log('AuthController::login', 'Auth attempt result', [
            'success' => $user !== false,
            'userId' => $user['id'] ?? null
        ], 'B');
        // #endregion

        if (!$user) {
            // Log échec de connexion
            AuditService::log('auth.login_failed', 'user', null, $username);

            // Afficher à nouveau le formulaire avec erreur
            $basePath = Config::basePath();
            $loginContent = $this->renderTemplate(__DIR__ . '/../../templates/auth/login.php', [
                'error' => 'Identifiants incorrects',
                'username' => htmlspecialchars($username),
                'basePath' => $basePath
            ]);
            
            $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/auth.php', [
                'title' => 'Connexion - K-Docs',
                'content' => $loginContent
            ]);

            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        // Créer la session
        $sessionId = bin2hex(random_bytes(32));
        $ipAddress = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $userAgent = $request->getHeaderLine('User-Agent');

        Auth::createSession($user['id'], $sessionId, $ipAddress, $userAgent);

        // Log connexion réussie
        AuditService::log('auth.login', 'user', $user['id'], $user['username'], null, $user['id']);

        // Définir le cookie de session avec Slim
        $basePath = Config::basePath();
        $response = $response->withHeader(
            'Set-Cookie',
            sprintf(
                'kdocs_session=%s; Path=%s; Max-Age=3600; HttpOnly; SameSite=Lax',
                $sessionId,
                $basePath
            )
        );

        // Rediriger vers le dashboard
        return $response
            ->withHeader('Location', $basePath . '/')
            ->withStatus(302);
    }

    /**
     * Déconnexion
     */
    public function logout(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('AuthController::logout', 'Logout attempt', [
            'path' => $request->getUri()->getPath()
        ], 'C');
        // #endregion
        
        $sessionId = $_COOKIE['kdocs_session'] ?? null;
        $user = null;
        if ($sessionId) {
            $user = Auth::getUserFromSession($sessionId);
            Auth::destroySession($sessionId);
        }

        // Log déconnexion
        if ($user) {
            AuditService::log('auth.logout', 'user', $user['id'], $user['username'], null, $user['id']);
        }

        // Supprimer le cookie
        $basePath = Config::basePath();
        $response = $response->withHeader(
            'Set-Cookie',
            sprintf('kdocs_session=; Path=%s; Max-Age=0; HttpOnly; SameSite=Lax', $basePath)
        );

        return $response
            ->withHeader('Location', $basePath . '/login')
            ->withStatus(302);
    }
}

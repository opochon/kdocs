<?php
/**
 * K-Docs - Contrôleur d'administration
 */

namespace KDocs\Controllers;

use KDocs\Core\Config;
use KDocs\Core\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
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
     * Page d'accueil de l'administration
     */
    public function index(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('AdminController::index', 'Controller entry', [
            'path' => $request->getUri()->getPath()
        ], 'A');
        // #endregion
        
        $user = $request->getAttribute('user');
        
        $db = Database::getInstance();
        
        // Statistiques générales
        $stats = [
            'users' => (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'documents' => (int)$db->query("SELECT COUNT(*) FROM documents")->fetchColumn(),
            'tasks' => (int)$db->query("SELECT COUNT(*) FROM tasks")->fetchColumn(),
            'document_types' => (int)$db->query("SELECT COUNT(*) FROM document_types")->fetchColumn(),
            'correspondents' => (int)$db->query("SELECT COUNT(*) FROM correspondents")->fetchColumn(),
        ];
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/index.php', [
            'stats' => $stats,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Administration - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Administration'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Liste des utilisateurs
     */
    public function users(Request $request, Response $response): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('AdminController::users', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $user = $request->getAttribute('user');
        
        $db = Database::getInstance();
        $users = $db->query("
            SELECT u.*, 
                   COUNT(DISTINCT d.id) as document_count,
                   COUNT(DISTINCT t.id) as task_count
            FROM users u
            LEFT JOIN documents d ON d.created_by = u.id
            LEFT JOIN tasks t ON t.assigned_to = u.id
            GROUP BY u.id
            ORDER BY u.created_at DESC
        ")->fetchAll();
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/users.php', [
            'users' => $users,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Gestion des utilisateurs - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Gestion des utilisateurs'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Configuration système
     */
    public function settings(Request $request, Response $response): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('AdminController::settings', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $user = $request->getAttribute('user');
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/settings.php', []);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Configuration - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Configuration'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}

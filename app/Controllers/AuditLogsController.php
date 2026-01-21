<?php
/**
 * K-Docs - Contrôleur Audit Logs
 */

namespace KDocs\Controllers;

use KDocs\Models\AuditLog;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuditLogsController
{
    private function renderTemplate(string $templatePath, array $data = []): string
    {
        extract($data);
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }

    /**
     * Liste des logs d'audit
     */
    public function index(Request $request, Response $response): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('AuditLogsController::index', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $user = $request->getAttribute('user');
        $queryParams = $request->getQueryParams();
        
        $page = max(1, (int)($queryParams['page'] ?? 1));
        $perPage = 50;
        
        $filters = [];
        if (!empty($queryParams['user_id'])) {
            $filters['user_id'] = (int)$queryParams['user_id'];
        }
        if (!empty($queryParams['action'])) {
            $filters['action'] = $queryParams['action'];
        }
        if (!empty($queryParams['object_type'])) {
            $filters['object_type'] = $queryParams['object_type'];
        }
        if (!empty($queryParams['date_from'])) {
            $filters['date_from'] = $queryParams['date_from'];
        }
        if (!empty($queryParams['date_to'])) {
            $filters['date_to'] = $queryParams['date_to'];
        }
        
        $auditLog = new AuditLog();
        $logs = $auditLog->getLogs($filters, $page, $perPage);
        $total = $auditLog->countLogs($filters);
        $totalPages = ceil($total / $perPage);
        
        // Récupérer les utilisateurs pour le filtre
        $db = \KDocs\Core\Database::getInstance();
        $users = $db->query("SELECT id, username FROM users ORDER BY username")->fetchAll(\PDO::FETCH_ASSOC);
        
        // Récupérer les statistiques
        $stats = $auditLog->getStats(7);
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/audit_logs.php', [
            'logs' => $logs,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'filters' => $filters,
            'users' => $users,
            'stats' => $stats,
        ]);

        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Logs d\'audit - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Logs d\'audit'
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}

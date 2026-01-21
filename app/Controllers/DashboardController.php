<?php
/**
 * K-Docs - Contrôleur du Dashboard (Priorité 2.5)
 */

namespace KDocs\Controllers;

use KDocs\Core\Database;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DashboardController
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
     * Affiche le dashboard avec statistiques
     */
    public function index(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DashboardController::index', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        
        $user = $request->getAttribute('user');
        $db = Database::getInstance();
        
        // Statistiques générales
        $stats = [
            'total_documents' => (int)$db->query("SELECT COUNT(*) FROM documents WHERE deleted_at IS NULL")->fetchColumn(),
            'indexed_documents' => (int)$db->query("SELECT COUNT(*) FROM documents WHERE deleted_at IS NULL AND is_indexed = 1")->fetchColumn(),
            'total_tasks' => (int)$db->query("SELECT COUNT(*) FROM tasks")->fetchColumn(),
            'pending_tasks' => (int)$db->query("SELECT COUNT(*) FROM tasks WHERE status = 'pending'")->fetchColumn(),
            'total_correspondents' => (int)$db->query("SELECT COUNT(*) FROM correspondents")->fetchColumn(),
            'total_tags' => (int)$db->query("SELECT COUNT(*) FROM tags")->fetchColumn(),
        ];
        
        // Documents par mois (12 derniers mois)
        $documentsByMonth = $db->query("
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as count
            FROM documents
            WHERE deleted_at IS NULL
            AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month
        ")->fetchAll();
        
        // Répartition par type de document
        $documentsByType = $db->query("
            SELECT 
                dt.label as type,
                COUNT(d.id) as count
            FROM documents d
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            WHERE d.deleted_at IS NULL
            GROUP BY dt.id, dt.label
            ORDER BY count DESC
            LIMIT 10
        ")->fetchAll();
        
        // Répartition par correspondant
        $documentsByCorrespondent = $db->query("
            SELECT 
                c.name as correspondent,
                COUNT(d.id) as count
            FROM documents d
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            WHERE d.deleted_at IS NULL
            AND c.id IS NOT NULL
            GROUP BY c.id, c.name
            ORDER BY count DESC
            LIMIT 10
        ")->fetchAll();
        
        // Montants totaux par mois
        $amountsByMonth = $db->query("
            SELECT 
                DATE_FORMAT(document_date, '%Y-%m') as month,
                SUM(amount) as total,
                currency
            FROM documents
            WHERE deleted_at IS NULL
            AND amount IS NOT NULL
            AND amount > 0
            AND document_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(document_date, '%Y-%m'), currency
            ORDER BY month, currency
        ")->fetchAll();
        
        // Documents récents (10 derniers)
        $recentDocuments = $db->query("
            SELECT d.*, dt.label as document_type_label, c.name as correspondent_name
            FROM documents d
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            WHERE d.deleted_at IS NULL
            ORDER BY d.created_at DESC
            LIMIT 10
        ")->fetchAll();
        
        // Documents en attente de traitement
        $pendingDocuments = $db->query("
            SELECT COUNT(*) as count
            FROM documents
            WHERE deleted_at IS NULL
            AND is_indexed = 0
        ")->fetchColumn();
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/dashboard/index.php', [
            'user' => $user,
            'stats' => $stats,
            'documentsByMonth' => $documentsByMonth,
            'documentsByType' => $documentsByType,
            'documentsByCorrespondent' => $documentsByCorrespondent,
            'amountsByMonth' => $amountsByMonth,
            'recentDocuments' => $recentDocuments,
            'pendingDocuments' => (int)$pendingDocuments,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Dashboard - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Dashboard'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}

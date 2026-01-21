<?php
/**
 * K-Docs - Contrôleur de gestion des tâches
 */

namespace KDocs\Controllers;

use KDocs\Models\Task;
use KDocs\Models\Document;
use KDocs\Core\Config;
use KDocs\Core\Database;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class TasksController
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
     * Liste des tâches
     */
    public function index(Request $request, Response $response): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('TasksController::index', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $user = $request->getAttribute('user');
        $queryParams = $request->getQueryParams();
        $page = (int)($queryParams['page'] ?? 1);
        $status = $queryParams['status'] ?? null;
        $showMine = isset($queryParams['mine']) && $queryParams['mine'] === '1';
        
        $limit = 20;
        $offset = ($page - 1) * $limit;
        
        $assignedTo = $showMine ? (int)$user['id'] : null;
        
        $tasks = Task::getAll($limit, $offset, $assignedTo, $status);
        $total = Task::count($assignedTo, $status);
        $totalPages = ceil($total / $limit);
        
        // Statistiques
        $db = Database::getInstance();
        $stats = [
            'total' => Task::count(),
            'pending' => Task::count(null, 'pending'),
            'in_progress' => Task::count(null, 'in_progress'),
            'completed' => Task::count(null, 'completed'),
            'my_tasks' => Task::count((int)$user['id']),
        ];
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/tasks/list.php', [
            'tasks' => $tasks,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'status' => $status,
            'showMine' => $showMine,
            'stats' => $stats,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Tâches - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Tâches'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Page de création de tâche
     */
    public function showCreate(Request $request, Response $response): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('TasksController::showCreate', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $user = $request->getAttribute('user');
        
        $db = Database::getInstance();
        $documents = $db->query("SELECT id, title, original_filename FROM documents ORDER BY created_at DESC LIMIT 50")->fetchAll();
        $workflowTypes = []; // Table workflow_types n'existe pas encore
        $users = $db->query("SELECT id, username, email FROM users ORDER BY username")->fetchAll();
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/tasks/create.php', [
            'documents' => $documents,
            'workflowTypes' => $workflowTypes,
            'users' => $users,
            'error' => null,
            'success' => null,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Créer une tâche - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Créer une tâche'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Traitement de la création de tâche
     */
    public function create(Request $request, Response $response): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('TasksController::create', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $user = $request->getAttribute('user');
        $basePath = Config::basePath();
        $data = $request->getParsedBody();
        
        if (empty($data['title'])) {
            $db = Database::getInstance();
            $documents = $db->query("SELECT id, title, original_filename FROM documents ORDER BY created_at DESC LIMIT 50")->fetchAll();
            $workflowTypes = $db->query("SELECT id, name, code FROM workflow_types ORDER BY name")->fetchAll();
            $users = $db->query("SELECT id, username, email FROM users ORDER BY username")->fetchAll();
            
            $content = $this->renderTemplate(__DIR__ . '/../../templates/tasks/create.php', [
                'documents' => $documents,
                'workflowTypes' => $workflowTypes,
                'users' => $users,
                'error' => 'Le titre est obligatoire',
                'success' => null,
            ]);
            
            $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
                'title' => 'Créer une tâche - K-Docs',
                'content' => $content,
                'user' => $user,
                'pageTitle' => 'Créer une tâche'
            ]);
            
            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
        
        try {
            $taskId = Task::create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'document_id' => !empty($data['document_id']) ? (int)$data['document_id'] : null,
                'workflow_type_id' => !empty($data['workflow_type_id']) ? (int)$data['workflow_type_id'] : null,
                'assigned_to' => !empty($data['assigned_to']) ? (int)$data['assigned_to'] : null,
                'priority' => $data['priority'] ?? 'medium',
                'due_date' => !empty($data['due_date']) ? $data['due_date'] : null,
                'created_by' => $user['id'],
            ]);
            
            return $response
                ->withHeader('Location', $basePath . '/tasks?success=1')
                ->withStatus(302);
                
        } catch (\Exception $e) {
            $db = Database::getInstance();
            $documents = $db->query("SELECT id, title, original_filename FROM documents ORDER BY created_at DESC LIMIT 50")->fetchAll();
            $workflowTypes = $db->query("SELECT id, name, code FROM workflow_types ORDER BY name")->fetchAll();
            $users = $db->query("SELECT id, username, email FROM users ORDER BY username")->fetchAll();
            
            $content = $this->renderTemplate(__DIR__ . '/../../templates/tasks/create.php', [
                'documents' => $documents,
                'workflowTypes' => $workflowTypes,
                'users' => $users,
                'error' => 'Erreur lors de la création : ' . $e->getMessage(),
                'success' => null,
            ]);
            
            $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
                'title' => 'Créer une tâche - K-Docs',
                'content' => $content,
                'user' => $user,
                'pageTitle' => 'Créer une tâche'
            ]);
            
            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
    }

    /**
     * Met à jour le statut d'une tâche
     */
    public function updateStatus(Request $request, Response $response, array $args): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('TasksController::updateStatus', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $user = $request->getAttribute('user');
        $basePath = Config::basePath();
        $id = (int)$args['id'];
        $data = $request->getParsedBody();
        $status = $data['status'] ?? null;
        
        if (!$status || !in_array($status, ['pending', 'in_progress', 'completed', 'cancelled'])) {
            return $response
                ->withHeader('Location', $basePath . '/tasks')
                ->withStatus(302);
        }
        
        Task::updateStatus($id, $status, $user['id']);
        
        return $response
            ->withHeader('Location', $basePath . '/tasks?success=1')
            ->withStatus(302);
    }
}

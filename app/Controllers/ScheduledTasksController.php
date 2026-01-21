<?php
/**
 * K-Docs - Contrôleur ScheduledTasksController
 * Gestion des tâches planifiées
 */

namespace KDocs\Controllers;

use KDocs\Models\ScheduledTask;
use KDocs\Services\TaskService;
use KDocs\Core\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ScheduledTasksController
{
    private function renderTemplate(string $templatePath, array $data = []): string
    {
        extract($data);
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }
    
    /**
     * Liste des tâches planifiées
     */
    public function index(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('ScheduledTasksController::index', 'Controller entry', [
            'path' => $request->getUri()->getPath()
        ], 'B');
        // #endregion
        
        $user = $request->getAttribute('user');
        
        // #region agent log
        \KDocs\Core\DebugLogger::log('ScheduledTasksController::index', 'Before ScheduledTask::all', [], 'E');
        // #endregion
        
        try {
            $tasks = ScheduledTask::all();
            
            // #region agent log
            \KDocs\Core\DebugLogger::log('ScheduledTasksController::index', 'After ScheduledTask::all', [
                'tasksCount' => count($tasks)
            ], 'E');
            // #endregion
        } catch (\Exception $e) {
            // #region agent log
            \KDocs\Core\DebugLogger::logException($e, 'ScheduledTasksController::index - Error fetching tasks', 'E');
            // #endregion
            $tasks = [];
        }
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/scheduled_tasks.php', [
            'tasks' => $tasks
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Tâches Planifiées - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Tâches Planifiées',
            'currentPage' => 'scheduled-tasks'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
    
    /**
     * Exécution manuelle d'une tâche
     */
    public function run(Request $request, Response $response, array $args): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('ScheduledTasksController::run', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $id = (int)$args['id'];
        $task = ScheduledTask::find($id);
        
        if (!$task) {
            return $response->withStatus(404)->withJson(['error' => 'Tâche introuvable']);
        }
        
        try {
            // Récupérer les données de la tâche si disponibles (pour les futures extensions)
            $taskData = [];
            $result = TaskService::executeTask($task['task_type'], $taskData);
            ScheduledTask::updateExecution($id, $result['success'] ? 'success' : 'error', $result['error'] ?? null);
            
            return $response->withJson($result);
        } catch (\Exception $e) {
            ScheduledTask::updateExecution($id, 'error', $e->getMessage());
            return $response->withStatus(500)->withJson(['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Traitement de la file d'attente
     */
    public function processQueue(Request $request, Response $response): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('ScheduledTasksController::processQueue', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $result = TaskService::processQueue();
        return $response->withJson($result);
    }
}

<?php
/**
 * K-Docs - MyTasksController
 * Page "Mes Tâches" centralisée
 */

namespace KDocs\Controllers;

use KDocs\Services\TaskUnifiedService;
use KDocs\Services\ValidationService;
use KDocs\Services\UserNoteService;
use KDocs\Services\NotificationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MyTasksController
{
    private $taskService;
    private $validationService;
    private $noteService;

    public function __construct()
    {
        $this->taskService = new TaskUnifiedService();
        $this->validationService = new ValidationService();
        $this->noteService = new UserNoteService();
    }

    /**
     * GET /mes-taches
     * Affiche la page "Mes Tâches"
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $response->withHeader('Location', url('/login'))->withStatus(302);
        }

        $queryParams = $request->getQueryParams();
        $activeTab = $queryParams['tab'] ?? 'all';
        $filter = $queryParams['filter'] ?? null;

        // Récupérer les tâches selon l'onglet actif
        $filters = [];
        if ($activeTab !== 'all') {
            $filters['type'] = $activeTab;
        }

        $tasks = $this->taskService->getAllTasksForUser($user['id'], $filters);
        $counts = $this->taskService->getTaskCounts($user['id']);

        // Récupérer les destinataires disponibles pour l'envoi de notes
        $recipients = $this->noteService->getAvailableRecipients($user['id']);

        // Données pour la vue
        $data = [
            'user' => $user,
            'tasks' => $tasks,
            'counts' => $counts,
            'activeTab' => $activeTab,
            'recipients' => $recipients,
            'pageTitle' => 'Mes Tâches'
        ];

        // Rendu du template
        ob_start();
        extract($data);
        include __DIR__ . '/../../templates/dashboard/my_tasks.php';
        $content = ob_get_clean();

        $response->getBody()->write($content);
        return $response;
    }

    /**
     * GET /api/tasks
     * API pour récupérer les tâches (AJAX)
     */
    public function apiIndex(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non authentifié'], 401);
        }

        $queryParams = $request->getQueryParams();
        $type = $queryParams['type'] ?? null;
        $limit = min((int)($queryParams['limit'] ?? 50), 100);

        $filters = ['limit' => $limit];
        if ($type) {
            $filters['type'] = $type;
        }

        $tasks = $this->taskService->getAllTasksForUser($user['id'], $filters);
        $counts = $this->taskService->getTaskCounts($user['id']);

        return $this->jsonResponse($response, [
            'success' => true,
            'tasks' => $tasks,
            'counts' => $counts
        ]);
    }

    /**
     * GET /api/tasks/counts
     * Compteurs uniquement (pour badge sidebar)
     */
    public function apiCounts(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non authentifié'], 401);
        }

        $counts = $this->taskService->getTaskCounts($user['id']);

        return $this->jsonResponse($response, [
            'success' => true,
            'counts' => $counts
        ]);
    }

    /**
     * GET /api/tasks/summary
     * Résumé pour widget dashboard
     */
    public function apiSummary(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non authentifié'], 401);
        }

        $summary = $this->taskService->getDashboardSummary($user['id']);

        return $this->jsonResponse($response, [
            'success' => true,
            'summary' => $summary
        ]);
    }

    /**
     * Helper pour les réponses JSON
     */
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}

<?php
/**
 * K-Docs - NotificationsApiController
 * API REST pour les notifications utilisateur
 */

namespace KDocs\Controllers\Api;

use KDocs\Services\NotificationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class NotificationsApiController
{
    private $notificationService;

    public function __construct()
    {
        $this->notificationService = new NotificationService();
    }

    /**
     * GET /api/notifications
     * Liste toutes les notifications de l'utilisateur connecté
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non authentifié'], 401);
        }

        $queryParams = $request->getQueryParams();
        $limit = min((int)($queryParams['limit'] ?? 50), 100);
        $offset = (int)($queryParams['offset'] ?? 0);

        $notifications = $this->notificationService->getAllForUser($user['id'], $limit, $offset);

        return $this->jsonResponse($response, [
            'success' => true,
            'notifications' => $notifications,
            'count' => count($notifications)
        ]);
    }

    /**
     * GET /api/notifications/unread
     * Liste les notifications non lues avec compteur
     */
    public function unread(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non authentifié'], 401);
        }

        $queryParams = $request->getQueryParams();
        $limit = min((int)($queryParams['limit'] ?? 20), 50);

        $notifications = $this->notificationService->getUnreadForUser($user['id'], $limit);
        $counts = $this->notificationService->getUnreadCountByPriority($user['id']);

        return $this->jsonResponse($response, [
            'success' => true,
            'notifications' => $notifications,
            'count' => $counts['total'],
            'counts_by_priority' => $counts
        ]);
    }

    /**
     * GET /api/notifications/count
     * Retourne uniquement le compteur (pour polling léger)
     */
    public function count(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non authentifié'], 401);
        }

        $counts = $this->notificationService->getUnreadCountByPriority($user['id']);

        return $this->jsonResponse($response, [
            'success' => true,
            'count' => $counts['total'],
            'counts_by_priority' => $counts
        ]);
    }

    /**
     * POST /api/notifications/{id}/read
     * Marque une notification comme lue
     */
    public function markRead(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non authentifié'], 401);
        }

        $notificationId = (int)($args['id'] ?? 0);
        if (!$notificationId) {
            return $this->jsonResponse($response, ['error' => 'ID notification requis'], 400);
        }

        $success = $this->notificationService->markAsRead($notificationId, $user['id']);

        return $this->jsonResponse($response, [
            'success' => $success
        ]);
    }

    /**
     * POST /api/notifications/read-all
     * Marque toutes les notifications comme lues
     */
    public function markAllRead(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non authentifié'], 401);
        }

        $success = $this->notificationService->markAllAsRead($user['id']);

        return $this->jsonResponse($response, [
            'success' => $success
        ]);
    }

    /**
     * DELETE /api/notifications/{id}
     * Supprime une notification
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non authentifié'], 401);
        }

        $notificationId = (int)($args['id'] ?? 0);
        if (!$notificationId) {
            return $this->jsonResponse($response, ['error' => 'ID notification requis'], 400);
        }

        $success = $this->notificationService->delete($notificationId, $user['id']);

        return $this->jsonResponse($response, [
            'success' => $success
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

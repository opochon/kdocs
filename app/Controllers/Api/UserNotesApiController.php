<?php
/**
 * K-Docs - UserNotesApiController
 * API REST pour les notes inter-utilisateurs
 */

namespace KDocs\Controllers\Api;

use KDocs\Services\UserNoteService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UserNotesApiController
{
    private $noteService;

    public function __construct()
    {
        $this->noteService = new UserNoteService();
    }

    /**
     * GET /api/notes
     * Liste les notes reçues par l'utilisateur
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non authentifié'], 401);
        }

        $queryParams = $request->getQueryParams();
        $limit = min((int)($queryParams['limit'] ?? 50), 100);
        $unreadOnly = filter_var($queryParams['unread'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $type = $queryParams['type'] ?? 'received'; // received, sent, actions

        if ($type === 'sent') {
            $notes = $this->noteService->getSentNotes($user['id'], $limit);
        } elseif ($type === 'actions') {
            $notes = $this->noteService->getPendingActionsForUser($user['id'], $limit);
        } else {
            $notes = $this->noteService->getNotesForUser($user['id'], $unreadOnly, $limit);
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'notes' => $notes,
            'count' => count($notes),
            'unread_count' => $this->noteService->getUnreadCount($user['id']),
            'pending_actions' => $this->noteService->getPendingActionCount($user['id'])
        ]);
    }

    /**
     * GET /api/notes/{id}
     * Récupère une note spécifique
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non authentifié'], 401);
        }

        $noteId = (int)($args['id'] ?? 0);
        $note = $this->noteService->getById($noteId);

        if (!$note) {
            return $this->jsonResponse($response, ['error' => 'Note non trouvée'], 404);
        }

        // Vérifier que l'utilisateur a accès à cette note
        if ($note['from_user_id'] != $user['id'] && $note['to_user_id'] != $user['id']) {
            return $this->jsonResponse($response, ['error' => 'Accès non autorisé'], 403);
        }

        // Marquer comme lue si c'est le destinataire
        if ($note['to_user_id'] == $user['id'] && !$note['is_read']) {
            $this->noteService->markAsRead($noteId, $user['id']);
            $note['is_read'] = true;
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'note' => $note
        ]);
    }

    /**
     * GET /api/notes/{id}/thread
     * Récupère le thread complet d'une note
     */
    public function thread(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non authentifié'], 401);
        }

        $noteId = (int)($args['id'] ?? 0);
        $thread = $this->noteService->getThread($noteId);

        if (empty($thread)) {
            return $this->jsonResponse($response, ['error' => 'Thread non trouvé'], 404);
        }

        // Vérifier que l'utilisateur a accès à ce thread
        $hasAccess = false;
        foreach ($thread as $note) {
            if ($note['from_user_id'] == $user['id'] || $note['to_user_id'] == $user['id']) {
                $hasAccess = true;
                break;
            }
        }

        if (!$hasAccess) {
            return $this->jsonResponse($response, ['error' => 'Accès non autorisé'], 403);
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'thread' => $thread
        ]);
    }

    /**
     * POST /api/notes
     * Envoie une nouvelle note
     */
    public function create(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non authentifié'], 401);
        }

        $data = json_decode($request->getBody()->getContents(), true) ?? [];

        // Validation
        if (empty($data['to_user_id'])) {
            return $this->jsonResponse($response, ['error' => 'Destinataire requis'], 400);
        }
        if (empty($data['message'])) {
            return $this->jsonResponse($response, ['error' => 'Message requis'], 400);
        }

        $result = $this->noteService->sendNote(
            $user['id'],
            (int)$data['to_user_id'],
            $data['message'],
            !empty($data['document_id']) ? (int)$data['document_id'] : null,
            [
                'subject' => $data['subject'] ?? null,
                'action_required' => $data['action_required'] ?? false,
                'action_type' => $data['action_type'] ?? null
            ]
        );

        if ($result['success']) {
            return $this->jsonResponse($response, [
                'success' => true,
                'note_id' => $result['note_id']
            ], 201);
        } else {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => $result['error'] ?? 'Erreur lors de l\'envoi'
            ], 500);
        }
    }

    /**
     * POST /api/notes/{id}/reply
     * Répond à une note
     */
    public function reply(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non authentifié'], 401);
        }

        $noteId = (int)($args['id'] ?? 0);
        $data = json_decode($request->getBody()->getContents(), true) ?? [];

        if (empty($data['message'])) {
            return $this->jsonResponse($response, ['error' => 'Message requis'], 400);
        }

        // Vérifier que l'utilisateur a accès à la note parent
        $parentNote = $this->noteService->getById($noteId);
        if (!$parentNote) {
            return $this->jsonResponse($response, ['error' => 'Note parent non trouvée'], 404);
        }
        if ($parentNote['from_user_id'] != $user['id'] && $parentNote['to_user_id'] != $user['id']) {
            return $this->jsonResponse($response, ['error' => 'Accès non autorisé'], 403);
        }

        $result = $this->noteService->reply($noteId, $user['id'], $data['message']);

        if ($result['success']) {
            return $this->jsonResponse($response, [
                'success' => true,
                'note_id' => $result['note_id']
            ], 201);
        } else {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => $result['error'] ?? 'Erreur lors de la réponse'
            ], 500);
        }
    }

    /**
     * POST /api/notes/{id}/read
     * Marque une note comme lue
     */
    public function markRead(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non authentifié'], 401);
        }

        $noteId = (int)($args['id'] ?? 0);
        $success = $this->noteService->markAsRead($noteId, $user['id']);

        return $this->jsonResponse($response, ['success' => $success]);
    }

    /**
     * POST /api/notes/{id}/complete
     * Marque l'action d'une note comme terminée
     */
    public function markComplete(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non authentifié'], 401);
        }

        $noteId = (int)($args['id'] ?? 0);
        $success = $this->noteService->markActionCompleted($noteId, $user['id']);

        return $this->jsonResponse($response, ['success' => $success]);
    }

    /**
     * DELETE /api/notes/{id}
     * Supprime une note (expéditeur uniquement)
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non authentifié'], 401);
        }

        $noteId = (int)($args['id'] ?? 0);
        $success = $this->noteService->delete($noteId, $user['id']);

        return $this->jsonResponse($response, ['success' => $success]);
    }

    /**
     * GET /api/notes/recipients
     * Liste les destinataires disponibles
     */
    public function recipients(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non authentifié'], 401);
        }

        $recipients = $this->noteService->getAvailableRecipients($user['id']);

        return $this->jsonResponse($response, [
            'success' => true,
            'recipients' => $recipients
        ]);
    }

    /**
     * GET /api/notes/document/{documentId}
     * Récupère les notes liées à un document
     */
    public function forDocument(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non authentifié'], 401);
        }

        $documentId = (int)($args['documentId'] ?? 0);
        $notes = $this->noteService->getThreadForDocument($documentId);

        return $this->jsonResponse($response, [
            'success' => true,
            'notes' => $notes
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

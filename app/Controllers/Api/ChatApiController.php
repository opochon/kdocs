<?php
/**
 * K-Docs - ChatApiController
 * API pour la gestion des conversations de chat
 */

namespace KDocs\Controllers\Api;

use KDocs\Services\ChatHistoryService;
use KDocs\Services\NaturalLanguageQueryService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ChatApiController extends ApiController
{
    private ChatHistoryService $chatService;
    private NaturalLanguageQueryService $nlService;

    public function __construct()
    {
        $this->chatService = new ChatHistoryService();
        $this->nlService = new NaturalLanguageQueryService();
    }

    /**
     * GET /api/chat/conversations
     * List recent conversations
     */
    public function listConversations(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non autorisé'], 401);
        }

        $conversations = $this->chatService->getRecentConversations($user['id']);

        return $this->jsonResponse($response, [
            'success' => true,
            'conversations' => $conversations
        ]);
    }

    /**
     * POST /api/chat/conversations
     * Create a new conversation
     */
    public function createConversation(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non autorisé'], 401);
        }

        $id = $this->chatService->createConversation($user['id']);
        $conversation = $this->chatService->getConversation($id, $user['id']);

        return $this->jsonResponse($response, [
            'success' => true,
            'conversation' => $conversation
        ]);
    }

    /**
     * GET /api/chat/conversations/{id}
     * Get a conversation with messages
     */
    public function getConversation(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non autorisé'], 401);
        }

        $id = (int) ($args['id'] ?? 0);
        $conversation = $this->chatService->getConversation($id, $user['id']);

        if (!$conversation) {
            return $this->jsonResponse($response, ['error' => 'Conversation non trouvée'], 404);
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'conversation' => $conversation
        ]);
    }

    /**
     * DELETE /api/chat/conversations/{id}
     * Delete a conversation
     */
    public function deleteConversation(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non autorisé'], 401);
        }

        $id = (int) ($args['id'] ?? 0);
        $this->chatService->deleteConversation($id, $user['id']);

        return $this->jsonResponse($response, ['success' => true]);
    }

    /**
     * POST /api/chat/conversations/{id}/messages
     * Send a message and get AI response
     */
    public function sendMessage(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non autorisé'], 401);
        }

        $id = (int) ($args['id'] ?? 0);

        // Verify conversation belongs to user
        $conversation = $this->chatService->getConversation($id, $user['id']);
        if (!$conversation) {
            return $this->jsonResponse($response, ['error' => 'Conversation non trouvée'], 404);
        }

        $body = json_decode($request->getBody()->getContents(), true);
        $question = $body['message'] ?? '';

        if (empty($question)) {
            return $this->jsonResponse($response, ['error' => 'Message requis'], 400);
        }

        // Save user message
        $this->chatService->addMessage($id, 'user', $question);

        // Build search options
        $searchOptions = [
            'scope' => $body['scope'] ?? 'all',
            'date_from' => $body['date_from'] ?? null,
            'date_to' => $body['date_to'] ?? null,
            'folder_id' => $body['folder_id'] ?? null,
        ];

        // Get AI response
        try {
            $result = $this->nlService->query($question, $searchOptions);

            $aiResponse = $result->aiResponse ?? "J'ai trouvé {$result->total} document(s).";

            // Save assistant message with metadata
            $metadata = [
                'documents' => array_map(function($doc) {
                    return [
                        'id' => $doc['id'],
                        'title' => $doc['title'] ?? $doc['filename'],
                        'relevance_score' => $doc['relevance_score'] ?? null,
                        'excerpts' => $doc['excerpts'] ?? []
                    ];
                }, array_slice($result->documents, 0, 10)),
                'total' => $result->total,
                'search_time' => $result->searchTime
            ];

            $this->chatService->addMessage($id, 'assistant', $aiResponse, $metadata);

            return $this->jsonResponse($response, [
                'success' => true,
                'answer' => $aiResponse,
                'documents' => $result->documents,
                'total' => $result->total,
                'search_time' => $result->searchTime
            ]);

        } catch (\Exception $e) {
            error_log("Chat error: " . $e->getMessage());

            $errorMessage = "Désolé, une erreur s'est produite lors du traitement de votre question.";
            $this->chatService->addMessage($id, 'assistant', $errorMessage);

            return $this->jsonResponse($response, [
                'success' => false,
                'answer' => $errorMessage,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * PATCH /api/chat/conversations/{id}
     * Update conversation (title, archive)
     */
    public function updateConversation(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $this->jsonResponse($response, ['error' => 'Non autorisé'], 401);
        }

        $id = (int) ($args['id'] ?? 0);
        $body = json_decode($request->getBody()->getContents(), true);

        if (isset($body['title'])) {
            $this->chatService->updateTitle($id, $body['title']);
        }

        if (isset($body['archived']) && $body['archived']) {
            $this->chatService->archiveConversation($id, $user['id']);
        }

        return $this->jsonResponse($response, ['success' => true]);
    }
}

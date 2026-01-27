<?php
/**
 * K-Docs - ValidationApiController
 * API REST pour la gestion des validations de documents
 */

namespace KDocs\Controllers\Api;

use KDocs\Core\Database;
use KDocs\Services\ValidationService;
use KDocs\Models\Role;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ValidationApiController
{
    private ValidationService $validationService;

    public function __construct()
    {
        $this->validationService = new ValidationService();
    }

    /**
     * GET /api/validation/pending
     * Liste les documents en attente de validation pour l'utilisateur courant
     */
    public function getPending(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return $this->jsonResponse($response, ['error' => 'Non authentifié'], 401);
        }

        $params = $request->getQueryParams();
        $limit = (int)($params['limit'] ?? 50);

        $documents = $this->validationService->getPendingForUser($userId, $limit);

        return $this->jsonResponse($response, [
            'success' => true,
            'count' => count($documents),
            'documents' => $documents
        ]);
    }

    /**
     * POST /api/validation/{documentId}/submit
     * Soumet un document pour validation
     */
    public function submit(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return $this->jsonResponse($response, ['error' => 'Non authentifié'], 401);
        }

        $documentId = (int)$args['documentId'];

        $result = $this->validationService->submitForApproval($documentId, $userId);

        if (!$result['success']) {
            return $this->jsonResponse($response, $result, 400);
        }

        return $this->jsonResponse($response, $result);
    }

    /**
     * POST /api/validation/{documentId}/approve
     * Approuve un document
     */
    public function approve(Request $request, Response $response, array $args): Response
    {
        return $this->handleValidation($request, $response, $args, 'approved');
    }

    /**
     * POST /api/validation/{documentId}/reject
     * Rejette un document
     */
    public function reject(Request $request, Response $response, array $args): Response
    {
        return $this->handleValidation($request, $response, $args, 'rejected');
    }

    /**
     * POST /api/validation/{documentId}/validate
     * Valide un document (approuve ou rejette selon le body)
     */
    public function validate(Request $request, Response $response, array $args): Response
    {
        $data = $request->getParsedBody();
        $decision = $data['decision'] ?? null;

        if (!in_array($decision, ['approved', 'rejected'])) {
            return $this->jsonResponse($response, ['error' => 'Décision invalide'], 400);
        }

        return $this->handleValidation($request, $response, $args, $decision);
    }

    /**
     * GET /api/validation/{documentId}/history
     * Historique de validation d'un document
     */
    public function getHistory(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['documentId'];

        $history = $this->validationService->getHistory($documentId);

        return $this->jsonResponse($response, [
            'success' => true,
            'document_id' => $documentId,
            'history' => $history
        ]);
    }

    /**
     * POST /api/validation/{documentId}/status
     * Définit le statut de validation d'un document
     */
    public function setStatus(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return $this->jsonResponse($response, ['error' => 'Non authentifié'], 401);
        }

        $documentId = (int)$args['documentId'];
        $data = $request->getParsedBody();

        $status = $data['status'] ?? null;
        $comment = $data['comment'] ?? null;

        if (!in_array($status, ['approved', 'rejected', 'na'])) {
            return $this->jsonResponse($response, ['error' => 'Statut invalide'], 400);
        }

        $result = $this->validationService->validate($documentId, $status, $userId, $comment);

        if (!$result['success']) {
            return $this->jsonResponse($response, $result, 400);
        }

        return $this->jsonResponse($response, $result);
    }

    /**
     * GET /api/validation/{documentId}/status
     * Statut de validation d'un document
     */
    public function getStatus(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['documentId'];
        $db = Database::getInstance();

        $stmt = $db->prepare("
            SELECT id, title, validation_status, validated_by, validated_at,
                   validation_comment, validation_level, requires_approval, approval_deadline
            FROM documents WHERE id = ?
        ");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$document) {
            return $this->jsonResponse($response, ['error' => 'Document non trouvé'], 404);
        }

        // Récupérer le validateur
        $validator = null;
        if ($document['validated_by']) {
            $stmt = $db->prepare("SELECT id, username, first_name, last_name FROM users WHERE id = ?");
            $stmt->execute([$document['validated_by']]);
            $validator = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'document_id' => $documentId,
            'status' => $document['validation_status'],
            'validated_by' => $validator,
            'validated_at' => $document['validated_at'],
            'comment' => $document['validation_comment'],
            'level' => $document['validation_level'],
            'requires_approval' => (bool)$document['requires_approval'],
            'deadline' => $document['approval_deadline']
        ]);
    }

    /**
     * GET /api/validation/statistics
     * Statistiques de validation
     */
    public function getStatistics(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $period = $params['period'] ?? 'month';
        $userId = $params['user_id'] ?? null;

        $stats = $this->validationService->getStatistics($userId ? (int)$userId : null, $period);

        return $this->jsonResponse($response, [
            'success' => true,
            'period' => $period,
            'statistics' => $stats
        ]);
    }

    /**
     * GET /api/validation/can-validate/{documentId}
     * Vérifie si l'utilisateur peut valider un document
     */
    public function canValidate(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return $this->jsonResponse($response, ['error' => 'Non authentifié'], 401);
        }

        $documentId = (int)$args['documentId'];
        $db = Database::getInstance();

        $stmt = $db->prepare("
            SELECT d.*, dt.code as document_type_code
            FROM documents d
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            WHERE d.id = ?
        ");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$document) {
            return $this->jsonResponse($response, ['error' => 'Document non trouvé'], 404);
        }

        $result = Role::canUserValidateDocument($userId, $document);

        return $this->jsonResponse($response, [
            'success' => true,
            'document_id' => $documentId,
            'can_validate' => $result['can_validate'],
            'role' => $result['role_code'] ?? null,
            'reason' => $result['reason'] ?? null,
            'max_amount' => $result['max_amount'] ?? null
        ]);
    }

    /**
     * GET /api/roles
     * Liste tous les rôles disponibles
     */
    public function getRoles(Request $request, Response $response): Response
    {
        $roles = Role::getAllRoleTypes();

        return $this->jsonResponse($response, [
            'success' => true,
            'roles' => $roles
        ]);
    }

    /**
     * GET /api/roles/user/{userId}
     * Rôles d'un utilisateur
     */
    public function getUserRoles(Request $request, Response $response, array $args): Response
    {
        $userId = (int)$args['userId'];
        $roles = Role::getUserRoles($userId);

        return $this->jsonResponse($response, [
            'success' => true,
            'user_id' => $userId,
            'roles' => $roles
        ]);
    }

    /**
     * POST /api/roles/user/{userId}/assign
     * Assigne un rôle à un utilisateur
     */
    public function assignRole(Request $request, Response $response, array $args): Response
    {
        $userId = (int)$args['userId'];
        $data = $request->getParsedBody();

        $roleCode = $data['role_code'] ?? null;
        if (!$roleCode) {
            return $this->jsonResponse($response, ['error' => 'role_code requis'], 400);
        }

        $scope = $data['scope'] ?? '*';
        $maxAmount = isset($data['max_amount']) ? (float)$data['max_amount'] : null;
        $validFrom = $data['valid_from'] ?? null;
        $validTo = $data['valid_to'] ?? null;

        $success = Role::assignRole($userId, $roleCode, $scope, $maxAmount, $validFrom, $validTo);

        if (!$success) {
            return $this->jsonResponse($response, ['error' => 'Échec de l\'assignation'], 500);
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'user_id' => $userId,
            'role_code' => $roleCode
        ]);
    }

    /**
     * DELETE /api/roles/user/{userId}/{roleCode}
     * Retire un rôle d'un utilisateur
     */
    public function removeRole(Request $request, Response $response, array $args): Response
    {
        $userId = (int)$args['userId'];
        $roleCode = $args['roleCode'];

        $params = $request->getQueryParams();
        $scope = $params['scope'] ?? null;

        $success = Role::removeRole($userId, $roleCode, $scope);

        return $this->jsonResponse($response, [
            'success' => $success,
            'user_id' => $userId,
            'role_code' => $roleCode
        ]);
    }

    /**
     * Traitement commun de validation
     */
    private function handleValidation(Request $request, Response $response, array $args, string $decision): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return $this->jsonResponse($response, ['error' => 'Non authentifié'], 401);
        }

        $documentId = (int)$args['documentId'];
        $data = $request->getParsedBody();
        $comment = $data['comment'] ?? null;

        $result = $this->validationService->validate($documentId, $decision, $userId, $comment);

        if (!$result['success']) {
            return $this->jsonResponse($response, $result, 400);
        }

        return $this->jsonResponse($response, $result);
    }

    /**
     * Helper pour réponses JSON
     */
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}

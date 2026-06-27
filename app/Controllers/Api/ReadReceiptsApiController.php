<?php
/**
 * K-Docs - API quittances de lecture (plugin SMQ / C.3)
 */

namespace KDocs\Controllers\Api;

use KDocs\Models\Document;
use KDocs\Models\DocumentReadReceipt;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ReadReceiptsApiController extends ApiController
{
    /**
     * POST /api/documents/{documentId}/versions/{versionNumber}/read
     * Enregistre la quittance de lecture pour l'utilisateur courant.
     */
    public function record(Request $request, Response $response, array $args): Response
    {
        try {
            $documentId = (int) ($args['documentId'] ?? 0);
            $versionNumber = (int) ($args['versionNumber'] ?? 0);

            if (!Document::findById($documentId)) {
                return $this->errorResponse($response, 'Document not found', 404);
            }

            $user = $request->getAttribute('user');
            $userId = (int) ($user['id'] ?? 0);
            if (!$userId) {
                return $this->errorResponse($response, 'Non authentifié', 401);
            }

            DocumentReadReceipt::record($documentId, $versionNumber, $userId);

            return $this->successResponse($response, [
                'document_id' => $documentId,
                'version_number' => $versionNumber,
                'read_at' => DocumentReadReceipt::readAt($documentId, $versionNumber, $userId),
            ], 'Quittance enregistrée', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/documents/{documentId}/versions/{versionNumber}/read-status
     * État de lecture (utilisateur courant + liste des lecteurs).
     */
    public function status(Request $request, Response $response, array $args): Response
    {
        try {
            $documentId = (int) ($args['documentId'] ?? 0);
            $versionNumber = (int) ($args['versionNumber'] ?? 0);
            $user = $request->getAttribute('user');
            $userId = (int) ($user['id'] ?? 0);

            $readers = DocumentReadReceipt::readersForVersion($documentId, $versionNumber);

            return $this->successResponse($response, [
                'document_id' => $documentId,
                'version_number' => $versionNumber,
                'has_read' => $userId ? DocumentReadReceipt::hasRead($documentId, $versionNumber, $userId) : false,
                'read_at' => $userId ? DocumentReadReceipt::readAt($documentId, $versionNumber, $userId) : null,
                'readers_count' => count($readers),
                'readers' => $readers,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }
}

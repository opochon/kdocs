<?php
/**
 * K-Docs - Classification Audit API Controller
 * API REST pour l'audit des classifications
 */

namespace KDocs\Controllers\Api;

use KDocs\Services\Audit\ClassificationAuditService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ClassificationAuditApiController extends ApiController
{
    /**
     * GET /api/documents/{id}/classification-history
     * Historique des classifications d'un document
     */
    public function documentHistory(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['id'];
        $queryParams = $request->getQueryParams();
        $limit = min(200, max(10, (int)($queryParams['limit'] ?? 100)));

        $auditService = new ClassificationAuditService();
        $history = $auditService->getDocumentHistory($documentId, $limit);

        return $this->successResponse($response, $history);
    }

    /**
     * GET /api/audit/classifications
     * Historique global des classifications (admin)
     */
    public function globalHistory(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $pagination = $this->getPaginationParams($queryParams);

        // Filtres
        $filters = [];

        if (!empty($queryParams['document_id'])) {
            $filters['document_id'] = (int)$queryParams['document_id'];
        }

        if (!empty($queryParams['field_code'])) {
            $filters['field_code'] = $queryParams['field_code'];
        }

        if (!empty($queryParams['change_source'])) {
            $filters['change_source'] = $queryParams['change_source'];
        }

        if (!empty($queryParams['user_id'])) {
            $filters['user_id'] = (int)$queryParams['user_id'];
        }

        if (!empty($queryParams['date_from'])) {
            $filters['date_from'] = $queryParams['date_from'];
        }

        if (!empty($queryParams['date_to'])) {
            $filters['date_to'] = $queryParams['date_to'];
        }

        $auditService = new ClassificationAuditService();
        $result = $auditService->getGlobalHistory($pagination['page'], $pagination['per_page'], $filters);

        return $this->paginatedResponse(
            $response,
            $result['data'],
            $result['pagination']['page'],
            $result['pagination']['per_page'],
            $result['pagination']['total']
        );
    }

    /**
     * GET /api/audit/classifications/stats
     * Statistiques d'audit
     */
    public function stats(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $days = min(365, max(1, (int)($queryParams['days'] ?? 30)));

        $auditService = new ClassificationAuditService();
        $stats = $auditService->getStats($days);

        return $this->successResponse($response, $stats);
    }

    /**
     * GET /api/audit/classifications/export
     * Exporte l'historique en CSV
     */
    public function export(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();

        $filters = [];
        if (!empty($queryParams['document_id'])) {
            $filters['document_id'] = (int)$queryParams['document_id'];
        }
        if (!empty($queryParams['field_code'])) {
            $filters['field_code'] = $queryParams['field_code'];
        }
        if (!empty($queryParams['change_source'])) {
            $filters['change_source'] = $queryParams['change_source'];
        }

        $auditService = new ClassificationAuditService();
        $csv = $auditService->exportCsv($filters);

        $filename = 'classification_audit_' . date('Y-m-d_H-i-s') . '.csv';

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"$filename\"")
            ->withStatus(200);
    }

    /**
     * POST /api/audit/classifications/{id}/revert
     * Annule une modification
     */
    public function revert(Request $request, Response $response, array $args): Response
    {
        $logId = (int)$args['id'];
        $user = $request->getAttribute('user');

        $auditService = new ClassificationAuditService();
        $result = $auditService->revert($logId, $user['id'] ?? 0);

        if (isset($result['error'])) {
            return $this->errorResponse($response, $result['error']);
        }

        return $this->successResponse($response, $result, 'Modification annulée');
    }

    /**
     * GET /api/documents/{id}/classification-compare
     * Compare les versions d'un document
     */
    public function compare(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['id'];
        $queryParams = $request->getQueryParams();

        if (empty($queryParams['from']) || empty($queryParams['to'])) {
            return $this->errorResponse($response, 'Les paramètres from et to sont requis');
        }

        $auditService = new ClassificationAuditService();
        $comparison = $auditService->compareVersions(
            $documentId,
            $queryParams['from'],
            $queryParams['to']
        );

        return $this->successResponse($response, $comparison);
    }
}

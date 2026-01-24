<?php
/**
 * K-Docs - API REST pour Document Types
 */

namespace KDocs\Controllers\Api;

use KDocs\Core\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;

class DocumentTypesApiController extends ApiController
{
    /**
     * Liste des types de documents (GET /api/document-types)
     */
    public function index(Request $request, Response $response): Response
    {
        $db = Database::getInstance();
        $types = $db->query("SELECT id, label, code FROM document_types ORDER BY label")->fetchAll(PDO::FETCH_ASSOC);
        
        return $this->successResponse($response, $types);
    }
}

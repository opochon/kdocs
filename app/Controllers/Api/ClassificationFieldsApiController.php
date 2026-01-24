<?php
/**
 * K-Docs - API REST pour Classification Fields
 */

namespace KDocs\Controllers\Api;

use KDocs\Models\ClassificationField;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ClassificationFieldsApiController extends ApiController
{
    /**
     * Liste des champs de classification (GET /api/classification-fields)
     */
    public function index(Request $request, Response $response): Response
    {
        $fields = ClassificationField::all();
        return $this->successResponse($response, $fields);
    }
}

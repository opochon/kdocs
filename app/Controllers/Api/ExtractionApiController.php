<?php
/**
 * K-Docs - Extraction API Controller
 * API pour le système d'extraction de données
 */

namespace KDocs\Controllers\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use KDocs\Services\ExtractionService;
use KDocs\Models\ExtractionTemplate;

class ExtractionApiController
{
    private ExtractionService $extractionService;

    public function __construct()
    {
        $this->extractionService = new ExtractionService();
    }

    /**
     * GET /api/extraction/templates
     * Liste tous les templates d'extraction
     */
    public function listTemplates(Request $request, Response $response): Response
    {
        $templates = ExtractionTemplate::allActive();

        // Ajouter les options parsées
        foreach ($templates as &$template) {
            $template['options_parsed'] = ExtractionTemplate::getOptions($template['id']);
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'templates' => $templates
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * GET /api/extraction/templates/{id}
     * Détail d'un template
     */
    public function getTemplate(Request $request, Response $response, array $args): Response
    {
        $template = ExtractionTemplate::findById((int) $args['id']);

        if (!$template) {
            $response->getBody()->write(json_encode(['error' => 'Template not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $template['options_parsed'] = ExtractionTemplate::getOptions($template['id']);
        $template['rules_parsed'] = ExtractionTemplate::parseRules($template);

        $response->getBody()->write(json_encode([
            'success' => true,
            'template' => $template
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST /api/extraction/templates
     * Créer un nouveau template
     */
    public function createTemplate(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();

        if (empty($data['name']) || empty($data['field_code'])) {
            $response->getBody()->write(json_encode(['error' => 'name and field_code are required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Vérifier unicité du code
        if (ExtractionTemplate::findByCode($data['field_code'])) {
            $response->getBody()->write(json_encode(['error' => 'field_code already exists']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $data['created_by'] = $user['id'] ?? null;
        $id = ExtractionTemplate::create($data);

        $response->getBody()->write(json_encode([
            'success' => true,
            'id' => $id,
            'message' => 'Template created'
        ]));

        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    /**
     * PUT /api/extraction/templates/{id}
     * Modifier un template
     */
    public function updateTemplate(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $data = $request->getParsedBody();

        $template = ExtractionTemplate::findById($id);
        if (!$template) {
            $response->getBody()->write(json_encode(['error' => 'Template not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Ne pas permettre de changer field_code
        unset($data['field_code'], $data['id'], $data['created_by'], $data['created_at']);

        ExtractionTemplate::update($id, $data);

        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Template updated'
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * DELETE /api/extraction/templates/{id}
     * Supprimer un template
     */
    public function deleteTemplate(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $template = ExtractionTemplate::findById($id);
        if (!$template) {
            $response->getBody()->write(json_encode(['error' => 'Template not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        ExtractionTemplate::delete($id);

        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Template deleted'
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST /api/documents/{id}/extract
     * Extrait toutes les données d'un document
     */
    public function extractDocument(Request $request, Response $response, array $args): Response
    {
        $documentId = (int) $args['id'];

        $results = $this->extractionService->extractAll($documentId);

        $response->getBody()->write(json_encode([
            'success' => true,
            'document_id' => $documentId,
            'extracted' => $results,
            'count' => count($results)
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * GET /api/documents/{id}/extracted
     * Récupère les données extraites d'un document
     */
    public function getExtracted(Request $request, Response $response, array $args): Response
    {
        $documentId = (int) $args['id'];

        $data = $this->extractionService->getExtractedData($documentId);

        $response->getBody()->write(json_encode([
            'success' => true,
            'document_id' => $documentId,
            'data' => $data
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST /api/documents/{id}/extracted/{field_code}/confirm
     * Confirme une valeur extraite
     */
    public function confirmValue(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $documentId = (int) $args['id'];
        $fieldCode = $args['field_code'];

        $success = $this->extractionService->confirmValue($documentId, $fieldCode, $user['id'] ?? null);

        $response->getBody()->write(json_encode([
            'success' => $success,
            'message' => $success ? 'Value confirmed' : 'Failed to confirm'
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST /api/documents/{id}/extracted/{field_code}/correct
     * Corrige une valeur extraite (apprentissage)
     */
    public function correctValue(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $documentId = (int) $args['id'];
        $fieldCode = $args['field_code'];
        $data = $request->getParsedBody();

        if (!isset($data['value'])) {
            $response->getBody()->write(json_encode(['error' => 'value is required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $success = $this->extractionService->correctValue(
            $documentId,
            $fieldCode,
            $data['value'],
            $user['id'] ?? null
        );

        $response->getBody()->write(json_encode([
            'success' => $success,
            'message' => $success ? 'Value corrected and learned' : 'Failed to correct'
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * GET /api/extraction/suggestions/{field_code}
     * Récupère les suggestions pour un champ
     */
    public function getSuggestions(Request $request, Response $response, array $args): Response
    {
        $fieldCode = $args['field_code'];
        $params = $request->getQueryParams();
        $correspondentId = isset($params['correspondent_id']) ? (int) $params['correspondent_id'] : null;

        $suggestions = $this->extractionService->getSuggestions($fieldCode, $correspondentId);

        $response->getBody()->write(json_encode([
            'success' => true,
            'field_code' => $fieldCode,
            'suggestions' => $suggestions
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST /api/extraction/templates/{id}/options
     * Ajoute une option à un template
     */
    public function addOption(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $data = $request->getParsedBody();

        if (empty($data['value'])) {
            $response->getBody()->write(json_encode(['error' => 'value is required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $success = ExtractionTemplate::addOption($id, $data['value'], $data['label'] ?? null);

        $response->getBody()->write(json_encode([
            'success' => $success,
            'message' => $success ? 'Option added' : 'Failed to add option'
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }
}

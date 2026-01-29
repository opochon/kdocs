<?php
/**
 * K-Docs - Attribution Rules API Controller
 * API REST pour la gestion des règles d'attribution
 */

namespace KDocs\Controllers\Api;

use KDocs\Models\AttributionRule;
use KDocs\Services\Attribution\AttributionService;
use KDocs\Services\Attribution\AttributionRuleEngine;
use KDocs\Services\Attribution\RuleConditionEvaluator;
use KDocs\Core\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AttributionRulesApiController extends ApiController
{
    /**
     * GET /api/attribution-rules
     * Liste toutes les règles
     */
    public function index(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $activeOnly = isset($queryParams['active']) && $queryParams['active'] === '1';

        $rules = $activeOnly ? AttributionRule::getActiveRules() : AttributionRule::all();

        return $this->successResponse($response, $rules);
    }

    /**
     * POST /api/attribution-rules
     * Crée une nouvelle règle
     */
    public function create(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $user = $request->getAttribute('user');

        // Validation
        if (empty($data['name'])) {
            return $this->errorResponse($response, 'Le nom est requis');
        }

        try {
            // Créer la règle
            $ruleId = AttributionRule::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'priority' => $data['priority'] ?? 100,
                'is_active' => $data['is_active'] ?? false,
                'stop_on_match' => $data['stop_on_match'] ?? true,
                'created_by' => $user['id'] ?? null
            ]);

            // Ajouter les conditions
            if (!empty($data['conditions'])) {
                foreach ($data['conditions'] as $condition) {
                    AttributionRule::addCondition($ruleId, $condition);
                }
            }

            // Ajouter les actions
            if (!empty($data['actions'])) {
                foreach ($data['actions'] as $action) {
                    AttributionRule::addAction($ruleId, $action);
                }
            }

            $rule = AttributionRule::find($ruleId);
            return $this->successResponse($response, $rule, 'Règle créée avec succès', 201);

        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Erreur lors de la création: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/attribution-rules/{id}
     * Récupère une règle par ID
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $rule = AttributionRule::find($id);

        if (!$rule) {
            return $this->errorResponse($response, 'Règle non trouvée', 404);
        }

        // Ajouter les logs récents
        $rule['recent_logs'] = AttributionRule::getLogs($id, 10);

        return $this->successResponse($response, $rule);
    }

    /**
     * PUT /api/attribution-rules/{id}
     * Met à jour une règle
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();

        $rule = AttributionRule::find($id);
        if (!$rule) {
            return $this->errorResponse($response, 'Règle non trouvée', 404);
        }

        try {
            // Mettre à jour les propriétés de base
            $updateData = [];
            foreach (['name', 'description', 'priority', 'is_active', 'stop_on_match'] as $field) {
                if (array_key_exists($field, $data)) {
                    $updateData[$field] = $data[$field];
                }
            }

            if (!empty($updateData)) {
                AttributionRule::update($id, $updateData);
            }

            // Mettre à jour les conditions si fournies
            if (array_key_exists('conditions', $data)) {
                AttributionRule::clearConditions($id);
                foreach ($data['conditions'] as $condition) {
                    AttributionRule::addCondition($id, $condition);
                }
            }

            // Mettre à jour les actions si fournies
            if (array_key_exists('actions', $data)) {
                AttributionRule::clearActions($id);
                foreach ($data['actions'] as $action) {
                    AttributionRule::addAction($id, $action);
                }
            }

            $updatedRule = AttributionRule::find($id);
            return $this->successResponse($response, $updatedRule, 'Règle mise à jour');

        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Erreur lors de la mise à jour: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/attribution-rules/{id}
     * Supprime une règle
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        $rule = AttributionRule::find($id);
        if (!$rule) {
            return $this->errorResponse($response, 'Règle non trouvée', 404);
        }

        try {
            AttributionRule::delete($id);
            return $this->successResponse($response, ['id' => $id], 'Règle supprimée');
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Erreur lors de la suppression: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/attribution-rules/{id}/test
     * Teste une règle sur des documents
     */
    public function test(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();

        $rule = AttributionRule::find($id);
        if (!$rule) {
            return $this->errorResponse($response, 'Règle non trouvée', 404);
        }

        // Récupérer les IDs de documents à tester
        $documentIds = $data['document_ids'] ?? [];

        // Si aucun document spécifié, prendre les 10 derniers
        if (empty($documentIds)) {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT id FROM documents ORDER BY created_at DESC LIMIT 10");
            $documentIds = array_column($stmt->fetchAll(), 'id');
        }

        $ruleEngine = new AttributionRuleEngine();
        $results = $ruleEngine->testRule($id, $documentIds);

        return $this->successResponse($response, $results);
    }

    /**
     * POST /api/attribution-rules/{id}/duplicate
     * Duplique une règle
     */
    public function duplicate(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        $newId = AttributionRule::duplicate($id);
        if (!$newId) {
            return $this->errorResponse($response, 'Règle non trouvée ou erreur lors de la duplication', 404);
        }

        $newRule = AttributionRule::find($newId);
        return $this->successResponse($response, $newRule, 'Règle dupliquée', 201);
    }

    /**
     * GET /api/attribution-rules/{id}/logs
     * Récupère les logs d'exécution d'une règle
     */
    public function logs(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $queryParams = $request->getQueryParams();
        $limit = min(100, max(10, (int)($queryParams['limit'] ?? 50)));

        $rule = AttributionRule::find($id);
        if (!$rule) {
            return $this->errorResponse($response, 'Règle non trouvée', 404);
        }

        $logs = AttributionRule::getLogs($id, $limit);
        return $this->successResponse($response, $logs);
    }

    /**
     * GET /api/attribution-rules/field-types
     * Retourne les types de champs disponibles pour les conditions
     */
    public function fieldTypes(Request $request, Response $response): Response
    {
        $fieldTypes = AttributionRuleEngine::getFieldTypes();
        $actionTypes = AttributionRuleEngine::getActionTypes();

        // Ajouter les opérateurs pour chaque type de champ
        $evaluator = new RuleConditionEvaluator();
        foreach ($fieldTypes as $type => &$config) {
            $config['operators'] = RuleConditionEvaluator::getOperatorsForFieldType($type);
        }

        return $this->successResponse($response, [
            'field_types' => $fieldTypes,
            'action_types' => $actionTypes
        ]);
    }

    /**
     * POST /api/attribution-rules/process-document
     * Traite un document avec les règles d'attribution
     */
    public function processDocument(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $user = $request->getAttribute('user');

        if (empty($data['document_id'])) {
            return $this->errorResponse($response, 'document_id est requis');
        }

        $documentId = (int)$data['document_id'];
        $apply = $data['apply'] ?? true;

        $service = new AttributionService();
        $result = $service->process($documentId, $apply, $user['id'] ?? null);

        return $this->successResponse($response, $result);
    }

    /**
     * POST /api/attribution-rules/process-batch
     * Traite plusieurs documents en batch
     */
    public function processBatch(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $user = $request->getAttribute('user');

        if (empty($data['document_ids']) || !is_array($data['document_ids'])) {
            return $this->errorResponse($response, 'document_ids (tableau) est requis');
        }

        $apply = $data['apply'] ?? true;

        $service = new AttributionService();
        $result = $service->processBatch($data['document_ids'], $apply, $user['id'] ?? null);

        return $this->successResponse($response, $result);
    }
}

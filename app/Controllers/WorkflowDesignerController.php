<?php
/**
 * K-Docs - WorkflowDesignerController
 * API REST pour le Workflow Designer
 */

namespace KDocs\Controllers;

use KDocs\Workflow\WorkflowManager;
use KDocs\Models\WorkflowDefinition;
use KDocs\Models\WorkflowNode;
use KDocs\Models\WorkflowConnection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class WorkflowDesignerController
{
    /**
     * Helper pour réponse JSON
     */
    private function jsonResponse(Response $response, $data, int $statusCode = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($statusCode);
    }
    
    /**
     * GET /api/workflows
     * Liste tous les workflows
     */
    public function list(Request $request, Response $response): Response
    {
        try {
            $enabledOnly = ($request->getQueryParams()['enabled_only'] ?? 'false') === 'true';
            $workflows = WorkflowManager::listWorkflows($enabledOnly);
            
            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $workflows,
                'count' => count($workflows)
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * GET /api/workflows/{id}
     * Récupère un workflow complet
     */
    public function get(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int)($args['id'] ?? 0);
            if ($id <= 0) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'error' => 'ID invalide'
                ], 400);
            }
            
            $workflow = WorkflowManager::getWorkflow($id);
            if (!$workflow) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'error' => 'Workflow non trouvé'
                ], 404);
            }
            
            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $workflow
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * POST /api/workflows
     * Crée un nouveau workflow
     */
    public function create(Request $request, Response $response): Response
    {
        try {
            $user = $request->getAttribute('user');
            $userId = $request->getAttribute('user_id') ?? ($user['id'] ?? null);
            $data = json_decode($request->getBody()->getContents(), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'error' => 'JSON invalide: ' . json_last_error_msg()
                ], 400);
            }
            
            // Validation
            if (empty($data['name'])) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'error' => 'Le nom est requis'
                ], 400);
            }

            // Vérifier si le nom existe déjà
            $db = \KDocs\Core\Database::getInstance();
            $stmt = $db->prepare("SELECT COUNT(*) FROM workflow_definitions WHERE name = ?");
            $stmt->execute([$data['name']]);
            if ($stmt->fetchColumn() > 0) {
                // Générer un nom suggéré
                $baseName = preg_replace('/\s*\(\d+\)$/', '', $data['name']); // Retirer "(N)" existant
                $suggestedName = $this->generateUniqueName($db, $baseName);

                return $this->jsonResponse($response, [
                    'success' => false,
                    'error' => "Un workflow nommé \"{$data['name']}\" existe déjà",
                    'suggested_name' => $suggestedName,
                    'code' => 'DUPLICATE_NAME'
                ], 409);
            }

            $data['created_by'] = $userId;
            $workflowId = WorkflowManager::createWorkflow($data);
            
            $workflow = WorkflowManager::getWorkflow($workflowId);
            
            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $workflow,
                'id' => $workflowId
            ], 201);
        } catch (\Exception $e) {
            error_log("WorkflowDesignerController::create error: " . $e->getMessage());
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * PUT /api/workflows/{id}
     * Met à jour un workflow
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int)($args['id'] ?? 0);
            if ($id <= 0) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'error' => 'ID invalide'
                ], 400);
            }
            
            $data = json_decode($request->getBody()->getContents(), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'error' => 'JSON invalide: ' . json_last_error_msg()
                ], 400);
            }
            
            // Vérifier que le workflow existe
            $existing = WorkflowDefinition::findById($id);
            if (!$existing) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'error' => 'Workflow non trouvé'
                ], 404);
            }
            
            WorkflowManager::updateWorkflow($id, $data);
            $workflow = WorkflowManager::getWorkflow($id);
            
            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $workflow
            ]);
        } catch (\Exception $e) {
            error_log("WorkflowDesignerController::update error: " . $e->getMessage());
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * DELETE /api/workflows/{id}
     * Supprime un workflow
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int)($args['id'] ?? 0);
            if ($id <= 0) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'error' => 'ID invalide'
                ], 400);
            }
            
            // Vérifier que le workflow existe
            $existing = WorkflowDefinition::findById($id);
            if (!$existing) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'error' => 'Workflow non trouvé'
                ], 404);
            }
            
            WorkflowManager::deleteWorkflow($id);
            
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Workflow supprimé'
            ]);
        } catch (\Exception $e) {
            error_log("WorkflowDesignerController::delete error: " . $e->getMessage());
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * POST /api/workflows/{id}/enable
     * Active/désactive un workflow
     */
    public function toggleEnabled(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int)($args['id'] ?? 0);
            if ($id <= 0) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'error' => 'ID invalide'
                ], 400);
            }
            
            $data = json_decode($request->getBody()->getContents(), true);
            $enabled = isset($data['enabled']) ? (bool)$data['enabled'] : true;
            
            WorkflowManager::setEnabled($id, $enabled);
            $workflow = WorkflowManager::getWorkflow($id);
            
            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $workflow
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Génère un nom unique pour un workflow
     */
    private function generateUniqueName(\PDO $db, string $baseName): string
    {
        $counter = 2;
        $suggestedName = "{$baseName} ({$counter})";

        $stmt = $db->prepare("SELECT name FROM workflow_definitions WHERE name LIKE ?");
        $stmt->execute([$baseName . ' (%)']);
        $existingNames = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        while (in_array($suggestedName, $existingNames) || $counter > 100) {
            $counter++;
            $suggestedName = "{$baseName} ({$counter})";
        }

        // Vérifier aussi le nom exact
        $stmt = $db->prepare("SELECT COUNT(*) FROM workflow_definitions WHERE name = ?");
        $stmt->execute([$suggestedName]);
        while ($stmt->fetchColumn() > 0 && $counter < 100) {
            $counter++;
            $suggestedName = "{$baseName} ({$counter})";
            $stmt->execute([$suggestedName]);
        }

        return $suggestedName;
    }
}

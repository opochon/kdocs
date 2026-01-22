<?php
/**
 * K-Docs - WorkflowEngine
 * Moteur principal d'exécution des workflows
 * Unifie l'ancien système (WorkflowService) et le nouveau (ExecutionEngine)
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Models\Workflow;
use KDocs\Models\WorkflowDefinition;
use KDocs\Workflow\ExecutionEngine;
use KDocs\Workflow\WorkflowManager;

class WorkflowEngine
{
    private Database $db;
    private WorkflowService $workflowService;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->workflowService = new WorkflowService();
    }
    
    /**
     * Exécute les workflows pour un événement donné
     * Supporte à la fois l'ancien système (workflows linéaires) et le nouveau (workflow designer)
     */
    public function executeForEvent(string $event, int $documentId, array $context = []): array
    {
        $results = [];
        
        // #region agent log
        \KDocs\Core\DebugLogger::log('WorkflowEngine::executeForEvent', 'Event triggered', [
            'event' => $event,
            'documentId' => $documentId,
            'context' => $context
        ], 'A');
        // #endregion
        
        // 1. Exécuter les workflows de l'ancien système (linéaires)
        try {
            $oldResults = $this->workflowService->executeForEvent($event, $documentId, $context);
            $results = array_merge($results, $oldResults);
        } catch (\Exception $e) {
            // #region agent log
            \KDocs\Core\DebugLogger::log('WorkflowEngine::executeForEvent', 'Old system error', [
                'error' => $e->getMessage()
            ], 'A');
            // #endregion
            error_log("WorkflowEngine: Erreur ancien système: " . $e->getMessage());
        }
        
        // 2. Exécuter les workflows du nouveau système (designer)
        try {
            $newResults = $this->executeDesignerWorkflows($event, $documentId, $context);
            $results = array_merge($results, $newResults);
        } catch (\Exception $e) {
            // #region agent log
            \KDocs\Core\DebugLogger::log('WorkflowEngine::executeForEvent', 'Designer system error', [
                'error' => $e->getMessage()
            ], 'A');
            // #endregion
            error_log("WorkflowEngine: Erreur nouveau système: " . $e->getMessage());
        }
        
        // #region agent log
        \KDocs\Core\DebugLogger::log('WorkflowEngine::executeForEvent', 'Execution completed', [
            'resultsCount' => count($results)
        ], 'A');
        // #endregion
        
        return $results;
    }
    
    /**
     * Exécute les workflows du designer pour un événement
     */
    private function executeDesignerWorkflows(string $event, int $documentId, array $context = []): array
    {
        $results = [];
        
        // Vérifier si la table workflow_definitions existe
        try {
            $this->db->query("SELECT 1 FROM workflow_definitions LIMIT 1");
        } catch (\Exception $e) {
            // Table n'existe pas encore, ignorer
            return [];
        }
        
        // Récupérer les workflows actifs avec des triggers correspondants
        $workflows = $this->getDesignerWorkflowsForEvent($event);
        
        foreach ($workflows as $workflow) {
            try {
                // Vérifier si le workflow a un trigger qui correspond
                $workflowData = WorkflowManager::getWorkflow($workflow['id']);
                if (!$workflowData) {
                    continue;
                }
                
                // Trouver les nodes de type trigger
                $triggerNodes = array_filter($workflowData['nodes'] ?? [], function($node) use ($event) {
                    return strpos($node['node_type'], 'trigger_') === 0 && $this->triggerNodeMatches($node, $event, $documentId, $context);
                });
                
                if (empty($triggerNodes)) {
                    continue;
                }
                
                // Démarrer l'exécution du workflow
                $executionId = ExecutionEngine::startWorkflow($workflow['id'], $documentId);
                
                $results[] = [
                    'workflow_id' => $workflow['id'],
                    'workflow_name' => $workflow['name'],
                    'execution_id' => $executionId,
                    'success' => true,
                    'type' => 'designer'
                ];
                
            } catch (\Exception $e) {
                $results[] = [
                    'workflow_id' => $workflow['id'],
                    'workflow_name' => $workflow['name'] ?? 'Unknown',
                    'success' => false,
                    'error' => $e->getMessage(),
                    'type' => 'designer'
                ];
                error_log("WorkflowEngine: Erreur exécution workflow designer {$workflow['id']}: " . $e->getMessage());
            }
        }
        
        return $results;
    }
    
    /**
     * Vérifie si un trigger node correspond à l'événement
     */
    private function triggerNodeMatches(array $node, string $event, int $documentId, array $context = []): bool
    {
        $nodeType = $node['node_type'] ?? '';
        $config = $node['config'] ?? [];
        
        // Mapping événement -> type de trigger
        $eventToTrigger = [
            'consumption_started' => 'trigger_scan',
            'document_added' => 'trigger_upload',
            'document_updated' => 'trigger_upload', // Peut être déclenché par upload aussi
        ];
        
        $expectedTrigger = $eventToTrigger[$event] ?? null;
        if ($expectedTrigger && $nodeType !== $expectedTrigger) {
            return false;
        }
        
        // Vérifier les filtres dans la config du node
        if (!empty($config['filter_filename'])) {
            $document = $this->getDocumentWithRelations($documentId);
            if (!$document) {
                return false;
            }
            $filename = $document['original_filename'] ?? '';
            if (!$this->matchesPattern($filename, $config['filter_filename'])) {
                return false;
            }
        }
        
        // Autres filtres similaires...
        
        return true;
    }
    
    /**
     * Récupère les workflows du designer pour un événement
     */
    private function getDesignerWorkflowsForEvent(string $event): array
    {
        try {
            // Vérifier si la table existe
            $this->db->query("SELECT 1 FROM workflow_definitions LIMIT 1");
        } catch (\Exception $e) {
            return [];
        }
        
        // Pour l'instant, retourner tous les workflows actifs
        // Le filtrage par trigger se fait au niveau du node
        $stmt = $this->db->query("
            SELECT * FROM workflow_definitions 
            WHERE enabled = 1 
            ORDER BY created_at DESC
        ");
        
        return $stmt->fetchAll() ?: [];
    }
    
    /**
     * Matching pattern (glob to regex)
     */
    private function matchesPattern(string $text, string $pattern): bool
    {
        $regex = str_replace(['*', '?'], ['.*', '.'], preg_quote($pattern, '/'));
        return preg_match('/^' . $regex . '$/i', $text) === 1;
    }
    
    /**
     * Méthodes statiques pour compatibilité
     */
    public static function executeOnDocumentAdded(int $documentId): void
    {
        $engine = new self();
        $engine->executeForEvent('document_added', $documentId);
    }
    
    public static function executeOnDocumentModified(int $documentId): void
    {
        $engine = new self();
        $engine->executeForEvent('document_updated', $documentId);
    }
    
    public static function executeOnConsumptionStarted(int $documentId, array $context = []): void
    {
        $engine = new self();
        $engine->executeForEvent('consumption_started', $documentId, $context);
    }
}

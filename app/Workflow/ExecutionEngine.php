<?php
/**
 * K-Docs - ExecutionEngine
 * Moteur d'exécution des workflows
 */

namespace KDocs\Workflow;

use KDocs\Models\WorkflowDefinition;
use KDocs\Models\WorkflowNode;
use KDocs\Models\WorkflowConnection;
use KDocs\Models\WorkflowExecution;
use KDocs\Workflow\Nodes\NodeExecutorFactory;
use KDocs\Core\Database;

class ExecutionEngine
{
    /**
     * Démarre l'exécution d'un workflow
     */
    public static function startWorkflow(int $workflowId, ?int $documentId = null): int
    {
        $workflow = WorkflowDefinition::findById($workflowId);
        if (!$workflow || !$workflow['enabled']) {
            throw new \Exception("Workflow non trouvé ou désactivé");
        }
        
        // Trouver le node d'entrée
        $entryNodes = WorkflowNode::findEntryPoints($workflowId);
        if (empty($entryNodes)) {
            throw new \Exception("Aucun point d'entrée trouvé pour ce workflow");
        }
        
        $entryNode = $entryNodes[0]; // Prendre le premier
        
        // Créer l'exécution
        $executionId = WorkflowExecution::create([
            'workflow_id' => $workflowId,
            'document_id' => $documentId,
            'status' => 'pending',
            'current_node_id' => $entryNode['id'],
            'context' => [],
        ]);
        
        // Démarrer l'exécution
        self::step($executionId);
        
        return $executionId;
    }
    
    /**
     * Exécute une étape du workflow
     */
    public static function step(int $executionId): bool
    {
        $execution = WorkflowExecution::findById($executionId);
        if (!$execution) {
            throw new \Exception("Exécution non trouvée");
        }
        
        if ($execution['status'] !== 'pending' && $execution['status'] !== 'running' && $execution['status'] !== 'waiting') {
            return false; // Déjà terminé ou annulé
        }
        
        // Mettre le statut à "running"
        WorkflowExecution::update($executionId, ['status' => 'running']);
        
        $currentNodeId = $execution['current_node_id'];
        if (!$currentNodeId) {
            WorkflowExecution::update($executionId, [
                'status' => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
            ]);
            return false;
        }
        
        $node = WorkflowNode::findById($currentNodeId);
        if (!$node) {
            WorkflowExecution::update($executionId, [
                'status' => 'failed',
                'error_message' => 'Node non trouvé',
                'completed_at' => date('Y-m-d H:i:s'),
            ]);
            return false;
        }
        
        // Créer le contexte
        $context = ContextBag::fromArray([
            'execution_id' => $executionId,
            'document_id' => $execution['document_id'],
            'workflow_id' => $execution['workflow_id'],
            'data' => $execution['context'],
        ]);
        
        // Logger le début
        self::logNodeStart($executionId, $currentNodeId, $context->toArray());
        
        try {
            // Créer l'executor
            $executor = NodeExecutorFactory::create($node['node_type']);
            if (!$executor) {
                throw new \Exception("Type de node non supporté: " . $node['node_type']);
            }
            
            // Exécuter le node
            $startTime = microtime(true);
            $result = $executor->execute($context, $node['config']);
            $duration = (int)((microtime(true) - $startTime) * 1000);
            
            // Logger le résultat
            self::logNodeResult($executionId, $currentNodeId, $result, $context->toArray(), $duration);
            
            // Mettre à jour le contexte
            $contextData = $context->toArray();
            WorkflowExecution::update($executionId, [
                'context' => $contextData['data'],
            ]);
            
            // Gérer le résultat
            if ($result->status === ExecutionResult::STATUS_FAILED) {
                WorkflowExecution::update($executionId, [
                    'status' => 'failed',
                    'error_message' => $result->error,
                    'completed_at' => date('Y-m-d H:i:s'),
                ]);
                return false;
            }
            
            if ($result->status === ExecutionResult::STATUS_WAITING) {
                $waitingUntil = null;
                if ($result->waitSeconds) {
                    $waitingUntil = date('Y-m-d H:i:s', time() + $result->waitSeconds);
                }
                
                WorkflowExecution::update($executionId, [
                    'status' => 'waiting',
                    'waiting_until' => $waitingUntil,
                    'waiting_for' => $result->waitFor,
                ]);
                return false; // En attente, ne pas continuer
            }
            
            // Trouver le prochain node selon l'output
            $nextNodeId = self::findNextNode($execution['workflow_id'], $currentNodeId, $result->output);
            
            if ($nextNodeId) {
                WorkflowExecution::update($executionId, [
                    'current_node_id' => $nextNodeId,
                ]);
                // Continuer avec le prochain node
                return self::step($executionId);
            } else {
                // Fin du workflow
                WorkflowExecution::update($executionId, [
                    'status' => 'completed',
                    'completed_at' => date('Y-m-d H:i:s'),
                    'current_node_id' => null,
                ]);
                return false;
            }
            
        } catch (\Exception $e) {
            error_log("ExecutionEngine::step error: " . $e->getMessage());
            WorkflowExecution::update($executionId, [
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => date('Y-m-d H:i:s'),
            ]);
            return false;
        }
    }
    
    /**
     * Reprend une exécution en attente
     */
    public static function resume(int $executionId, string $output = 'default'): bool
    {
        $execution = WorkflowExecution::findById($executionId);
        if (!$execution || $execution['status'] !== 'waiting') {
            return false;
        }
        
        // Trouver le prochain node
        $nextNodeId = self::findNextNode($execution['workflow_id'], $execution['current_node_id'], $output);
        
        if ($nextNodeId) {
            WorkflowExecution::update($executionId, [
                'status' => 'running',
                'current_node_id' => $nextNodeId,
                'waiting_until' => null,
                'waiting_for' => null,
            ]);
            return self::step($executionId);
        } else {
            WorkflowExecution::update($executionId, [
                'status' => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
                'current_node_id' => null,
            ]);
            return false;
        }
    }
    
    /**
     * Annule une exécution
     */
    public static function cancel(int $executionId): bool
    {
        $execution = WorkflowExecution::findById($executionId);
        if (!$execution) {
            return false;
        }
        
        WorkflowExecution::update($executionId, [
            'status' => 'cancelled',
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
        
        return true;
    }
    
    /**
     * Trouve le prochain node selon l'output
     */
    private static function findNextNode(int $workflowId, int $fromNodeId, string $output): ?int
    {
        $connections = WorkflowConnection::findByFromNode($fromNodeId);
        
        foreach ($connections as $conn) {
            if ($conn['output_name'] === $output) {
                return $conn['to_node_id'];
            }
        }
        
        // Si pas de match exact, prendre la première connection "default"
        foreach ($connections as $conn) {
            if ($conn['output_name'] === 'default') {
                return $conn['to_node_id'];
            }
        }
        
        return null;
    }
    
    /**
     * Log le début d'un node
     */
    private static function logNodeStart(int $executionId, int $nodeId, array $inputData): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO workflow_execution_logs 
            (execution_id, node_id, status, input_data)
            VALUES (?, ?, 'started', ?)
        ");
        $stmt->execute([$executionId, $nodeId, json_encode($inputData)]);
    }
    
    /**
     * Log le résultat d'un node
     */
    private static function logNodeResult(int $executionId, int $nodeId, ExecutionResult $result, array $outputData, int $durationMs): void
    {
        $db = Database::getInstance();
        $status = match($result->status) {
            ExecutionResult::STATUS_SUCCESS => 'completed',
            ExecutionResult::STATUS_FAILED => 'failed',
            ExecutionResult::STATUS_WAITING => 'started', // Toujours en cours
            default => 'completed',
        };
        
        $stmt = $db->prepare("
            INSERT INTO workflow_execution_logs 
            (execution_id, node_id, status, output_data, error_message, duration_ms)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $executionId,
            $nodeId,
            $status,
            json_encode($outputData),
            $result->error,
            $durationMs,
        ]);
    }
}

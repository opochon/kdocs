<?php
/**
 * K-Docs - ApprovalWait
 * Attend une approbation avant de continuer
 */

namespace KDocs\Workflow\Nodes\Waits;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;
use KDocs\Core\Database;

class ApprovalWait extends AbstractNodeExecutor
{
    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        if (!$context->documentId) {
            return ExecutionResult::failed('Aucun document associé');
        }
        
        $userId = $config['assign_to_user_id'] ?? null;
        if (!$userId) {
            return ExecutionResult::failed('ID utilisateur requis pour approbation');
        }
        
        try {
            $db = Database::getInstance();
            
            // Créer une tâche d'approbation
            $stmt = $db->prepare("
                INSERT INTO workflow_approval_tasks 
                (execution_id, node_id, document_id, assigned_user_id, expires_at, escalate_to_user_id, escalate_after_hours)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $expiresAt = null;
            if (isset($config['timeout_hours'])) {
                $expiresAt = date('Y-m-d H:i:s', time() + ($config['timeout_hours'] * 3600));
            }
            
            $stmt->execute([
                $context->executionId,
                $config['node_id'] ?? null,
                $context->documentId,
                $userId,
                $expiresAt,
                $config['escalate_to_user_id'] ?? null,
                $config['escalate_after_hours'] ?? null,
            ]);
            
            // Mettre l'exécution en attente
            return ExecutionResult::waiting('approval', null);
        } catch (\Exception $e) {
            return ExecutionResult::failed('Erreur création approbation: ' . $e->getMessage());
        }
    }
    
    public function getOutputs(): array
    {
        return ['approved', 'rejected', 'timeout'];
    }
    
    public function getConfigSchema(): array
    {
        return [
            'assign_to_user_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'ID de l\'utilisateur à qui demander l\'approbation',
            ],
            'timeout_hours' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Timeout en heures',
            ],
            'escalate_to_user_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'ID utilisateur pour escalade',
            ],
            'escalate_after_hours' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Heures avant escalade',
            ],
        ];
    }
}

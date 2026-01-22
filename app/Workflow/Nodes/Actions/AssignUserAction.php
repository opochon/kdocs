<?php
/**
 * K-Docs - AssignUserAction
 * Assigne un document à un utilisateur
 */

namespace KDocs\Workflow\Nodes\Actions;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;
use KDocs\Core\Database;

class AssignUserAction extends AbstractNodeExecutor
{
    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        if (!$context->documentId) {
            return ExecutionResult::failed('Aucun document associé');
        }
        
        $userId = $config['user_id'] ?? null;
        if (!$userId) {
            return ExecutionResult::failed('ID utilisateur requis');
        }
        
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("UPDATE documents SET owner_id = ? WHERE id = ?");
            $stmt->execute([$userId, $context->documentId]);
            
            return ExecutionResult::success([
                'user_id' => $userId,
                'document_id' => $context->documentId,
            ]);
        } catch (\Exception $e) {
            return ExecutionResult::failed('Erreur assignation: ' . $e->getMessage());
        }
    }
    
    public function getConfigSchema(): array
    {
        return [
            'user_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'ID de l\'utilisateur à assigner',
            ],
        ];
    }
}

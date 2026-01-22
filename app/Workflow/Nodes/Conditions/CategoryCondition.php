<?php
/**
 * K-Docs - CategoryCondition
 * Condition basée sur le type de document
 */

namespace KDocs\Workflow\Nodes\Conditions;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;
use KDocs\Core\Database;

class CategoryCondition extends AbstractNodeExecutor
{
    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        if (!$context->documentId) {
            return ExecutionResult::failed('Aucun document associé');
        }
        
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT document_type_id FROM documents WHERE id = ?");
        $stmt->execute([$context->documentId]);
        $document = $stmt->fetch();
        
        if (!$document) {
            return ExecutionResult::failed('Document non trouvé');
        }
        
        $documentTypeId = $document['document_type_id'];
        $expectedTypeId = $config['document_type_id'] ?? null;
        
        $matches = ($documentTypeId == $expectedTypeId);
        
        return ExecutionResult::success(
            ['matches' => $matches],
            $matches ? 'true' : 'false'
        );
    }
    
    public function getOutputs(): array
    {
        return ['true', 'false'];
    }
    
    public function getConfigSchema(): array
    {
        return [
            'document_type_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'ID du type de document attendu',
            ],
        ];
    }
}

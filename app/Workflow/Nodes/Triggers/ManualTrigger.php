<?php
/**
 * K-Docs - ManualTrigger
 * Trigger déclenché manuellement depuis l'interface
 */

namespace KDocs\Workflow\Nodes\Triggers;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;

class ManualTrigger extends AbstractNodeExecutor
{
    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        return ExecutionResult::success([
            'message' => 'Trigger manuel activé',
            'document_id' => $context->documentId,
        ]);
    }
    
    public function getConfigSchema(): array
    {
        return [];
    }
}

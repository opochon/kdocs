<?php
/**
 * K-Docs - UploadTrigger
 * Trigger déclenché lors d'un upload manuel
 */

namespace KDocs\Workflow\Nodes\Triggers;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;

class UploadTrigger extends AbstractNodeExecutor
{
    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        // Ce trigger est déclenché lors d'un upload manuel
        return ExecutionResult::success([
            'message' => 'Upload trigger activé',
            'document_id' => $context->documentId,
        ]);
    }
    
    public function getConfigSchema(): array
    {
        return [];
    }
}

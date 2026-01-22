<?php
/**
 * K-Docs - NodeExecutorFactory
 * Factory pour instancier le bon executor selon le type de node
 */

namespace KDocs\Workflow\Nodes;

use KDocs\Workflow\Nodes\Triggers\ScanTrigger;
use KDocs\Workflow\Nodes\Triggers\UploadTrigger;
use KDocs\Workflow\Nodes\Triggers\ManualTrigger;
use KDocs\Workflow\Nodes\Processing\OcrProcessor;
use KDocs\Workflow\Nodes\Processing\AiExtractProcessor;
use KDocs\Workflow\Nodes\Processing\ClassifyProcessor;
use KDocs\Workflow\Nodes\Conditions\CategoryCondition;
use KDocs\Workflow\Nodes\Actions\AssignUserAction;
use KDocs\Workflow\Nodes\Actions\AddTagAction;
use KDocs\Workflow\Nodes\Waits\ApprovalWait;

class NodeExecutorFactory
{
    /**
     * Crée un executor pour un type de node donné
     */
    public static function create(string $nodeType): ?NodeExecutorInterface
    {
        return match($nodeType) {
            // Triggers
            'trigger_scan' => new ScanTrigger(),
            'trigger_upload' => new UploadTrigger(),
            'trigger_manual' => new ManualTrigger(),
            
            // Processing
            'process_ocr' => new OcrProcessor(),
            'process_ai_extract' => new AiExtractProcessor(),
            'process_classify' => new ClassifyProcessor(),
            
            // Conditions
            'condition_category' => new CategoryCondition(),
            
            // Actions
            'action_assign_user' => new AssignUserAction(),
            'action_add_tag' => new AddTagAction(),
            
            // Waits
            'wait_approval' => new ApprovalWait(),
            
            default => null,
        };
    }
    
    /**
     * Vérifie si un type de node est supporté
     */
    public static function isSupported(string $nodeType): bool
    {
        return self::create($nodeType) !== null;
    }
}

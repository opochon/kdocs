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
        $matchMode = $config['match_mode'] ?? 'exact'; // exact, any, none, list
        $expectedTypeId = $config['document_type_id'] ?? null;
        $expectedTypeIds = $config['document_type_ids'] ?? []; // Liste pour mode "list"
        $matchByName = $config['match_by_name'] ?? false;
        
        $matches = false;
        
        // Mode "none" : document sans type
        if ($matchMode === 'none') {
            $matches = ($documentTypeId === null);
        }
        // Mode "any" : document avec n'importe quel type
        elseif ($matchMode === 'any') {
            $matches = ($documentTypeId !== null);
        }
        // Mode "list" : document avec un type dans la liste (OR)
        elseif ($matchMode === 'list' && !empty($expectedTypeIds)) {
            $matches = ($documentTypeId !== null && in_array($documentTypeId, $expectedTypeIds));
        }
        // Mode "exact" : correspondance exacte (par ID ou nom)
        else {
            if ($matchByName && !empty($config['document_type_name'])) {
                // Matching par nom
                $stmt = $db->prepare("SELECT id FROM document_types WHERE label = ? LIMIT 1");
                $stmt->execute([$config['document_type_name']]);
                $type = $stmt->fetch();
                if ($type) {
                    $matches = ($documentTypeId == $type['id']);
                } else {
                    $matches = false;
                }
            } else {
                // Matching par ID
                $matches = ($documentTypeId == $expectedTypeId);
            }
        }
        
        return ExecutionResult::success(
            [
                'matches' => $matches,
                'document_type_id' => $documentTypeId,
                'match_mode' => $matchMode
            ],
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
            'match_mode' => [
                'type' => 'string',
                'required' => false,
                'default' => 'exact',
                'description' => 'Mode de matching: exact, any, none, list',
                'enum' => ['exact', 'any', 'none', 'list']
            ],
            'document_type_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'ID du type de document attendu (mode exact)',
            ],
            'document_type_ids' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Liste d\'IDs de types (mode list)',
            ],
            'document_type_name' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Nom du type de document (si match_by_name = true)',
            ],
            'match_by_name' => [
                'type' => 'boolean',
                'required' => false,
                'default' => false,
                'description' => 'Faire le matching par nom au lieu de l\'ID',
            ],
        ];
    }
}

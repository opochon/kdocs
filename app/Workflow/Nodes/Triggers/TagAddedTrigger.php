<?php
/**
 * K-Docs - TagAddedTrigger
 * Déclenche le workflow quand un tag spécifique est ajouté à un document
 */

namespace KDocs\Workflow\Nodes\Triggers;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;
use KDocs\Core\Database;

class TagAddedTrigger extends AbstractNodeExecutor
{
    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        return ExecutionResult::success([
            'trigger_type' => 'tag_added',
            'document_id' => $context->documentId,
            'tag_id' => $context->get('tag_id'),
            'tag_name' => $context->get('tag_name')
        ]);
    }
    
    /**
     * Vérifie si ce trigger doit déclencher le workflow
     */
    public static function shouldTrigger(array $config, int $documentId, array $eventContext = []): bool
    {
        $addedTagId = $eventContext['tag_id'] ?? null;
        $addedTagName = $eventContext['tag_name'] ?? null;
        
        if (!$addedTagId && !$addedTagName) {
            return false;
        }
        
        // Filtrer par IDs de tags
        if (!empty($config['trigger_tag_ids'])) {
            if (!in_array($addedTagId, $config['trigger_tag_ids'])) {
                return false;
            }
        }
        
        // Filtrer par noms de tags
        if (!empty($config['trigger_tag_names'])) {
            $matchFound = false;
            foreach ($config['trigger_tag_names'] as $pattern) {
                if (strcasecmp($pattern, $addedTagName) === 0) {
                    $matchFound = true;
                    break;
                }
                // Support des patterns avec *
                if (strpos($pattern, '*') !== false) {
                    $regex = str_replace(['*', '?'], ['.*', '.'], preg_quote($pattern, '/'));
                    if (preg_match('/^' . $regex . '$/i', $addedTagName)) {
                        $matchFound = true;
                        break;
                    }
                }
            }
            if (!$matchFound) {
                return false;
            }
        }
        
        // Si aucun filtre, déclencher pour n'importe quel tag
        if (empty($config['trigger_tag_ids']) && empty($config['trigger_tag_names'])) {
            return true;
        }
        
        return true;
    }
    
    public function getConfigSchema(): array
    {
        return [
            'trigger_tag_ids' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Déclencher seulement pour ces IDs de tags',
            ],
            'trigger_tag_names' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Déclencher seulement pour ces noms de tags (supporte *)',
            ],
        ];
    }
}

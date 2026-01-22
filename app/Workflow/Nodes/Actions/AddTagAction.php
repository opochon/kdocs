<?php
/**
 * K-Docs - AddTagAction
 * Ajoute un tag à un document
 */

namespace KDocs\Workflow\Nodes\Actions;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;
use KDocs\Core\Database;

class AddTagAction extends AbstractNodeExecutor
{
    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        if (!$context->documentId) {
            return ExecutionResult::failed('Aucun document associé');
        }
        
        $tagIds = $config['tag_ids'] ?? [];
        if (empty($tagIds)) {
            return ExecutionResult::failed('Aucun tag spécifié');
        }
        
        if (!is_array($tagIds)) {
            $tagIds = [$tagIds];
        }
        
        try {
            $db = Database::getInstance();
            $added = 0;
            
            foreach ($tagIds as $tagId) {
                $stmt = $db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)");
                if ($stmt->execute([$context->documentId, $tagId])) {
                    $added++;
                }
            }
            
            return ExecutionResult::success([
                'tags_added' => $added,
                'tag_ids' => $tagIds,
            ]);
        } catch (\Exception $e) {
            return ExecutionResult::failed('Erreur ajout tags: ' . $e->getMessage());
        }
    }
    
    public function getConfigSchema(): array
    {
        return [
            'tag_ids' => [
                'type' => 'array',
                'required' => true,
                'description' => 'IDs des tags à ajouter',
            ],
        ];
    }
}

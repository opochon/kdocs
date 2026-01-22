<?php
/**
 * K-Docs - ClassifyProcessor
 * Classifie automatiquement un document avec les suggestions IA
 */

namespace KDocs\Workflow\Nodes\Processing;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;
use KDocs\Core\Database;

class ClassifyProcessor extends AbstractNodeExecutor
{
    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        if (!$context->documentId) {
            return ExecutionResult::failed('Aucun document associé');
        }
        
        $suggestions = $context->get('ai_suggestions', []);
        if (empty($suggestions)) {
            return ExecutionResult::failed('Aucune suggestion IA disponible');
        }
        
        try {
            $db = Database::getInstance();
            $updates = [];
            
            // Appliquer les suggestions
            if (isset($suggestions['correspondent_id'])) {
                $updates[] = "correspondent_id = " . (int)$suggestions['correspondent_id'];
            }
            if (isset($suggestions['document_type_id'])) {
                $updates[] = "document_type_id = " . (int)$suggestions['document_type_id'];
            }
            if (isset($suggestions['title'])) {
                $updates[] = "title = " . $db->quote($suggestions['title']);
            }
            if (isset($suggestions['document_date'])) {
                $updates[] = "doc_date = " . $db->quote($suggestions['document_date']);
            }
            if (isset($suggestions['amount'])) {
                $updates[] = "amount = " . (float)$suggestions['amount'];
            }
            
            if (!empty($updates)) {
                $sql = "UPDATE documents SET " . implode(', ', $updates) . " WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute([$context->documentId]);
            }
            
            // Appliquer les tags
            if (isset($suggestions['tags']) && is_array($suggestions['tags'])) {
                foreach ($suggestions['tags'] as $tagId) {
                    $db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)")
                       ->execute([$context->documentId, $tagId]);
                }
            }
            
            return ExecutionResult::success([
                'applied' => count($updates),
                'tags_applied' => count($suggestions['tags'] ?? []),
            ]);
        } catch (\Exception $e) {
            return ExecutionResult::failed('Erreur classification: ' . $e->getMessage());
        }
    }
    
    public function getConfigSchema(): array
    {
        return [
            'auto_apply' => [
                'type' => 'boolean',
                'required' => false,
                'description' => 'Appliquer automatiquement les suggestions',
                'default' => true,
            ],
        ];
    }
}

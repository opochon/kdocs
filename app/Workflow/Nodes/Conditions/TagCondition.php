<?php
/**
 * K-Docs - TagCondition
 * Condition basée sur les tags du document
 */

namespace KDocs\Workflow\Nodes\Conditions;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;
use KDocs\Core\Database;

class TagCondition extends AbstractNodeExecutor
{
    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        if (!$context->documentId) {
            return ExecutionResult::failed('Aucun document associé');
        }
        
        $db = Database::getInstance();
        
        // Récupérer les tags du document
        $stmt = $db->prepare("
            SELECT t.id, t.name 
            FROM tags t
            INNER JOIN document_tags dt ON t.id = dt.tag_id
            WHERE dt.document_id = ?
        ");
        $stmt->execute([$context->documentId]);
        $documentTags = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $documentTagIds = array_column($documentTags, 'id');
        $documentTagNames = array_column($documentTags, 'name');
        
        $matchMode = $config['match_mode'] ?? 'any'; // any, all, none, exact
        $tagIds = $config['tag_ids'] ?? [];
        $tagNames = $config['tag_names'] ?? [];
        
        // Convertir les noms en IDs si nécessaire
        if (!empty($tagNames) && empty($tagIds)) {
            $placeholders = implode(',', array_fill(0, count($tagNames), '?'));
            $stmt = $db->prepare("SELECT id FROM tags WHERE name IN ($placeholders)");
            $stmt->execute($tagNames);
            $tagIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }
        
        $matches = false;
        
        switch ($matchMode) {
            case 'any':
                // Au moins un tag correspond
                $matches = !empty(array_intersect($documentTagIds, $tagIds));
                break;
                
            case 'all':
                // Tous les tags doivent être présents
                $matches = !empty($tagIds) && count(array_intersect($documentTagIds, $tagIds)) === count($tagIds);
                break;
                
            case 'none':
                // Aucun des tags ne doit être présent
                $matches = empty(array_intersect($documentTagIds, $tagIds));
                break;
                
            case 'exact':
                // Exactement ces tags, ni plus ni moins
                sort($documentTagIds);
                sort($tagIds);
                $matches = $documentTagIds === $tagIds;
                break;
                
            case 'has_any':
                // Le document a au moins un tag (n'importe lequel)
                $matches = !empty($documentTagIds);
                break;
                
            case 'has_none':
                // Le document n'a aucun tag
                $matches = empty($documentTagIds);
                break;
        }
        
        return ExecutionResult::success(
            [
                'matches' => $matches,
                'document_tag_ids' => $documentTagIds,
                'document_tag_names' => $documentTagNames,
                'expected_tag_ids' => $tagIds,
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
                'default' => 'any',
                'description' => 'Mode de matching: any (au moins un), all (tous), none (aucun), exact, has_any, has_none',
                'enum' => ['any', 'all', 'none', 'exact', 'has_any', 'has_none']
            ],
            'tag_ids' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Liste des IDs de tags à vérifier',
            ],
            'tag_names' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Liste des noms de tags à vérifier (alternative à tag_ids)',
            ],
        ];
    }
}

<?php
/**
 * K-Docs - CorrespondentCondition
 * Condition basée sur le correspondant du document
 */

namespace KDocs\Workflow\Nodes\Conditions;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;
use KDocs\Core\Database;

class CorrespondentCondition extends AbstractNodeExecutor
{
    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        if (!$context->documentId) {
            return ExecutionResult::failed('Aucun document associé');
        }
        
        $db = Database::getInstance();
        
        // Récupérer le correspondant du document
        $stmt = $db->prepare("
            SELECT d.correspondent_id, c.name as correspondent_name, c.is_supplier, c.is_customer
            FROM documents d
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            WHERE d.id = ?
        ");
        $stmt->execute([$context->documentId]);
        $document = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$document) {
            return ExecutionResult::failed('Document non trouvé');
        }
        
        $correspondentId = $document['correspondent_id'];
        $correspondentName = $document['correspondent_name'];
        $isSupplier = (bool)$document['is_supplier'];
        $isCustomer = (bool)$document['is_customer'];
        
        $matchMode = $config['match_mode'] ?? 'exact'; // exact, any, none, list, is_supplier, is_customer
        $expectedId = $config['correspondent_id'] ?? null;
        $expectedIds = $config['correspondent_ids'] ?? [];
        $expectedName = $config['correspondent_name'] ?? null;
        
        $matches = false;
        
        switch ($matchMode) {
            case 'none':
            case 'has_none':
                // Document sans correspondant
                $matches = ($correspondentId === null);
                break;
                
            case 'any':
            case 'has_any':
                // Document avec n'importe quel correspondant
                $matches = ($correspondentId !== null);
                break;
                
            case 'is_supplier':
                // Le correspondant est un fournisseur
                $matches = $isSupplier;
                break;
                
            case 'is_customer':
                // Le correspondant est un client
                $matches = $isCustomer;
                break;
                
            case 'list':
            case 'in_list':
                // Le correspondant est dans la liste
                $matches = ($correspondentId !== null && in_array($correspondentId, $expectedIds));
                break;
                
            case 'not_in_list':
                // Le correspondant n'est pas dans la liste
                $matches = ($correspondentId === null || !in_array($correspondentId, $expectedIds));
                break;
                
            case 'name_contains':
                // Le nom du correspondant contient la chaîne
                $matches = ($correspondentName !== null && stripos($correspondentName, $expectedName) !== false);
                break;
                
            case 'name_equals':
                // Le nom du correspondant est exactement égal
                $matches = ($correspondentName !== null && strcasecmp($correspondentName, $expectedName) === 0);
                break;
                
            case 'exact':
            default:
                // Correspondance exacte par ID
                $matches = ($correspondentId == $expectedId);
                break;
        }
        
        return ExecutionResult::success(
            [
                'matches' => $matches,
                'correspondent_id' => $correspondentId,
                'correspondent_name' => $correspondentName,
                'is_supplier' => $isSupplier,
                'is_customer' => $isCustomer,
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
                'description' => 'Mode de matching',
                'enum' => ['exact', 'any', 'none', 'list', 'not_in_list', 'is_supplier', 'is_customer', 'name_contains', 'name_equals']
            ],
            'correspondent_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'ID du correspondant attendu (mode exact)',
            ],
            'correspondent_ids' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Liste des IDs de correspondants (mode list)',
            ],
            'correspondent_name' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Nom du correspondant (modes name_contains, name_equals)',
            ],
        ];
    }
}

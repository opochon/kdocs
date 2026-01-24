<?php
/**
 * K-Docs - AmountCondition
 * Condition basée sur le montant du document
 */

namespace KDocs\Workflow\Nodes\Conditions;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;
use KDocs\Core\Database;

class AmountCondition extends AbstractNodeExecutor
{
    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        if (!$context->documentId) {
            return ExecutionResult::failed('Aucun document associé');
        }
        
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT amount FROM documents WHERE id = ?");
        $stmt->execute([$context->documentId]);
        $document = $stmt->fetch();
        
        if (!$document) {
            return ExecutionResult::failed('Document non trouvé');
        }
        
        $documentAmount = $document['amount'] ?? null;
        $operator = $config['operator'] ?? '=='; // ==, !=, >, <, >=, <=, between
        $value = isset($config['value']) ? (float)$config['value'] : null;
        $value2 = isset($config['value2']) ? (float)$config['value2'] : null; // Pour "between"
        
        $matches = false;
        
        // Si le document n'a pas de montant
        if ($documentAmount === null) {
            // Mode "is_null" : vérifier si le montant est NULL
            if ($operator === 'is_null') {
                $matches = true;
            } else {
                $matches = false;
            }
        } else {
            $amount = (float)$documentAmount;
            
            switch ($operator) {
                case '==':
                case 'equals':
                    $matches = ($amount == $value);
                    break;
                    
                case '!=':
                case 'not_equals':
                    $matches = ($amount != $value);
                    break;
                    
                case '>':
                case 'greater_than':
                    $matches = ($amount > $value);
                    break;
                    
                case '<':
                case 'less_than':
                    $matches = ($amount < $value);
                    break;
                    
                case '>=':
                case 'greater_or_equal':
                    $matches = ($amount >= $value);
                    break;
                    
                case '<=':
                case 'less_or_equal':
                    $matches = ($amount <= $value);
                    break;
                    
                case 'between':
                    if ($value !== null && $value2 !== null) {
                        $min = min($value, $value2);
                        $max = max($value, $value2);
                        $matches = ($amount >= $min && $amount <= $max);
                    }
                    break;
                    
                case 'is_null':
                    $matches = false; // On sait déjà que ce n'est pas NULL
                    break;
                    
                case 'is_not_null':
                    $matches = true;
                    break;
                    
                default:
                    $matches = false;
                    break;
            }
        }
        
        return ExecutionResult::success(
            [
                'matches' => $matches,
                'document_amount' => $documentAmount,
                'operator' => $operator,
                'value' => $value
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
            'operator' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Opérateur de comparaison',
                'enum' => ['==', '!=', '>', '<', '>=', '<=', 'between', 'is_null', 'is_not_null'],
                'default' => '=='
            ],
            'value' => [
                'type' => 'number',
                'required' => false,
                'description' => 'Valeur de comparaison (requis sauf pour is_null/is_not_null)',
            ],
            'value2' => [
                'type' => 'number',
                'required' => false,
                'description' => 'Deuxième valeur pour l\'opérateur "between"',
            ],
        ];
    }
}

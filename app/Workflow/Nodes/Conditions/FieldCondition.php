<?php
/**
 * K-Docs - FieldCondition
 * Condition basée sur un champ de classification ou champ personnalisé
 */

namespace KDocs\Workflow\Nodes\Conditions;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;
use KDocs\Core\Database;

class FieldCondition extends AbstractNodeExecutor
{
    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        if (!$context->documentId) {
            return ExecutionResult::failed('Aucun document associé');
        }
        
        $db = Database::getInstance();
        $fieldType = $config['field_type'] ?? 'document'; // document, classification, custom
        $fieldName = $config['field_name'] ?? null;
        $operator = $config['operator'] ?? '==';
        $value = $config['value'] ?? null;
        $value2 = $config['value2'] ?? null; // Pour between
        
        if (!$fieldName) {
            return ExecutionResult::failed('Nom du champ non spécifié');
        }
        
        $actualValue = null;
        
        // Récupérer la valeur selon le type de champ
        switch ($fieldType) {
            case 'document':
                // Champ standard du document (titre, montant, date, correspondant, type, etc.)
                $allowedFields = [
                    'title', 'amount', 'currency', 'document_date', 'doc_date',
                    'correspondent_id', 'document_type_id', 'status', 'ocr_status',
                    'file_size', 'mime_type', 'asn'
                ];
                
                if (!in_array($fieldName, $allowedFields)) {
                    return ExecutionResult::failed("Champ document non autorisé: $fieldName");
                }
                
                $stmt = $db->prepare("SELECT $fieldName FROM documents WHERE id = ?");
                $stmt->execute([$context->documentId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                $actualValue = $row[$fieldName] ?? null;
                break;
                
            case 'classification':
                // Champ de classification (stocké dans classification_fields)
                $stmt = $db->prepare("
                    SELECT dcfv.value 
                    FROM document_classification_field_values dcfv
                    INNER JOIN classification_fields cf ON dcfv.field_id = cf.id
                    WHERE dcfv.document_id = ? AND (cf.field_code = ? OR cf.field_name = ?)
                ");
                $stmt->execute([$context->documentId, $fieldName, $fieldName]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                $actualValue = $row['value'] ?? null;
                break;
                
            case 'custom':
                // Champ personnalisé (custom_fields)
                $stmt = $db->prepare("
                    SELECT dcfv.value 
                    FROM document_custom_field_values dcfv
                    INNER JOIN custom_fields cf ON dcfv.custom_field_id = cf.id
                    WHERE dcfv.document_id = ? AND (cf.code = ? OR cf.name = ?)
                ");
                $stmt->execute([$context->documentId, $fieldName, $fieldName]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                $actualValue = $row['value'] ?? null;
                break;
                
            case 'metadata':
                // Champ dans le JSON metadata
                $stmt = $db->prepare("SELECT metadata FROM documents WHERE id = ?");
                $stmt->execute([$context->documentId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                $metadata = json_decode($row['metadata'] ?? '{}', true);
                $actualValue = $metadata[$fieldName] ?? null;
                break;
        }
        
        // Comparer selon l'opérateur
        $matches = $this->compare($actualValue, $operator, $value, $value2);
        
        return ExecutionResult::success(
            [
                'matches' => $matches,
                'field_type' => $fieldType,
                'field_name' => $fieldName,
                'actual_value' => $actualValue,
                'expected_value' => $value,
                'operator' => $operator
            ],
            $matches ? 'true' : 'false'
        );
    }
    
    private function compare($actual, string $operator, $expected, $expected2 = null): bool
    {
        // Gestion des valeurs nulles
        if ($operator === 'is_null' || $operator === 'is_empty') {
            return $actual === null || $actual === '';
        }
        if ($operator === 'is_not_null' || $operator === 'is_not_empty') {
            return $actual !== null && $actual !== '';
        }
        
        // Si la valeur est null et qu'on ne teste pas is_null
        if ($actual === null) {
            return false;
        }
        
        // Déterminer si on compare des nombres
        $isNumeric = is_numeric($actual) && is_numeric($expected);
        
        if ($isNumeric) {
            $actual = (float)$actual;
            $expected = (float)$expected;
            if ($expected2 !== null) {
                $expected2 = (float)$expected2;
            }
        }
        
        switch ($operator) {
            case '==':
            case 'equals':
            case 'eq':
                return $actual == $expected;
                
            case '===':
            case 'strict_equals':
                return $actual === $expected;
                
            case '!=':
            case 'not_equals':
            case 'ne':
                return $actual != $expected;
                
            case '>':
            case 'greater_than':
            case 'gt':
                return $actual > $expected;
                
            case '<':
            case 'less_than':
            case 'lt':
                return $actual < $expected;
                
            case '>=':
            case 'greater_or_equal':
            case 'gte':
                return $actual >= $expected;
                
            case '<=':
            case 'less_or_equal':
            case 'lte':
                return $actual <= $expected;
                
            case 'between':
                if ($expected2 === null) return false;
                $min = min($expected, $expected2);
                $max = max($expected, $expected2);
                return $actual >= $min && $actual <= $max;
                
            case 'contains':
                return stripos((string)$actual, (string)$expected) !== false;
                
            case 'not_contains':
                return stripos((string)$actual, (string)$expected) === false;
                
            case 'starts_with':
                return stripos((string)$actual, (string)$expected) === 0;
                
            case 'ends_with':
                $len = strlen((string)$expected);
                return $len === 0 || substr((string)$actual, -$len) === (string)$expected;
                
            case 'regex':
            case 'matches':
                return @preg_match($expected, (string)$actual) === 1;
                
            case 'in':
            case 'in_list':
                $list = is_array($expected) ? $expected : explode(',', (string)$expected);
                $list = array_map('trim', $list);
                return in_array((string)$actual, $list);
                
            case 'not_in':
            case 'not_in_list':
                $list = is_array($expected) ? $expected : explode(',', (string)$expected);
                $list = array_map('trim', $list);
                return !in_array((string)$actual, $list);
                
            default:
                return false;
        }
    }
    
    public function getOutputs(): array
    {
        return ['true', 'false'];
    }
    
    public function getConfigSchema(): array
    {
        return [
            'field_type' => [
                'type' => 'string',
                'required' => false,
                'default' => 'document',
                'description' => 'Type de champ: document, classification, custom, metadata',
                'enum' => ['document', 'classification', 'custom', 'metadata']
            ],
            'field_name' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Nom ou code du champ à vérifier',
            ],
            'operator' => [
                'type' => 'string',
                'required' => false,
                'default' => '==',
                'description' => 'Opérateur de comparaison',
                'enum' => ['==', '!=', '>', '<', '>=', '<=', 'between', 'contains', 'not_contains', 
                          'starts_with', 'ends_with', 'regex', 'in', 'not_in', 'is_null', 'is_not_null']
            ],
            'value' => [
                'type' => 'mixed',
                'required' => false,
                'description' => 'Valeur à comparer',
            ],
            'value2' => [
                'type' => 'mixed',
                'required' => false,
                'description' => 'Deuxième valeur (pour between)',
            ],
        ];
    }
}

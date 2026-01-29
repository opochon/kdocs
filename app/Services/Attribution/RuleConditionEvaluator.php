<?php
/**
 * K-Docs - Rule Condition Evaluator
 * Évalue les conditions individuelles d'une règle d'attribution
 */

namespace KDocs\Services\Attribution;

use KDocs\Core\Database;

class RuleConditionEvaluator
{
    /**
     * Évalue une condition sur un document
     *
     * @param array $condition La condition à évaluer
     * @param array $document Les données du document
     * @return array ['matched' => bool, 'reason' => string]
     */
    public function evaluate(array $condition, array $document): array
    {
        $fieldType = $condition['field_type'];
        $operator = $condition['operator'];
        $conditionValue = $this->parseConditionValue($condition['value']);

        // Récupérer la valeur du document selon le type de champ
        $documentValue = $this->getDocumentValue($fieldType, $condition['field_name'] ?? null, $document);

        // Évaluer l'opérateur
        $matched = $this->evaluateOperator($operator, $documentValue, $conditionValue, $document);

        return [
            'matched' => $matched,
            'field_type' => $fieldType,
            'operator' => $operator,
            'document_value' => $documentValue,
            'condition_value' => $conditionValue,
            'reason' => $matched
                ? "Match: {$fieldType} {$operator} " . json_encode($conditionValue)
                : "No match: {$fieldType}=" . json_encode($documentValue) . " {$operator} " . json_encode($conditionValue)
        ];
    }

    /**
     * Parse la valeur de condition (peut être JSON)
     */
    private function parseConditionValue($value)
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        return $value;
    }

    /**
     * Récupère la valeur du document pour le type de champ
     */
    private function getDocumentValue(string $fieldType, ?string $fieldName, array $document)
    {
        switch ($fieldType) {
            case 'correspondent':
                return $document['correspondent_id'] ?? null;

            case 'document_type':
                return $document['document_type_id'] ?? null;

            case 'tag':
                // Récupérer les tags du document
                return $this->getDocumentTags($document['id'] ?? null);

            case 'amount':
                return isset($document['amount']) ? (float)$document['amount'] : null;

            case 'content':
                return $document['ocr_content'] ?? $document['content'] ?? '';

            case 'date':
                return $document['doc_date'] ?? $document['created_at'] ?? null;

            case 'custom_field':
                // Chercher dans les champs personnalisés ou directement dans le document
                if ($fieldName) {
                    return $document[$fieldName] ?? $this->getCustomFieldValue($document['id'] ?? null, $fieldName);
                }
                return null;

            default:
                return null;
        }
    }

    /**
     * Récupère les tags d'un document
     */
    private function getDocumentTags(?int $documentId): array
    {
        if (!$documentId) {
            return [];
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT t.id, t.name
            FROM document_tags dt
            JOIN tags t ON dt.tag_id = t.id
            WHERE dt.document_id = ?
        ");
        $stmt->execute([$documentId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Récupère la valeur d'un champ personnalisé
     */
    private function getCustomFieldValue(?int $documentId, string $fieldName)
    {
        if (!$documentId) {
            return null;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT dcfv.value
            FROM document_custom_field_values dcfv
            JOIN custom_fields cf ON dcfv.custom_field_id = cf.id
            WHERE dcfv.document_id = ? AND cf.name = ?
        ");
        $stmt->execute([$documentId, $fieldName]);
        $result = $stmt->fetch();
        return $result ? $result['value'] : null;
    }

    /**
     * Évalue un opérateur
     */
    private function evaluateOperator(string $operator, $documentValue, $conditionValue, array $document): bool
    {
        switch ($operator) {
            case 'equals':
                return $this->equals($documentValue, $conditionValue);

            case 'not_equals':
                return !$this->equals($documentValue, $conditionValue);

            case 'contains':
                return $this->contains($documentValue, $conditionValue);

            case 'not_contains':
                return !$this->contains($documentValue, $conditionValue);

            case 'starts_with':
                return is_string($documentValue) && is_string($conditionValue)
                    && stripos($documentValue, $conditionValue) === 0;

            case 'ends_with':
                return is_string($documentValue) && is_string($conditionValue)
                    && substr(strtolower($documentValue), -strlen($conditionValue)) === strtolower($conditionValue);

            case 'greater_than':
                return is_numeric($documentValue) && is_numeric($conditionValue)
                    && $documentValue > $conditionValue;

            case 'less_than':
                return is_numeric($documentValue) && is_numeric($conditionValue)
                    && $documentValue < $conditionValue;

            case 'between':
                if (!is_array($conditionValue) || count($conditionValue) < 2) {
                    return false;
                }
                return is_numeric($documentValue)
                    && $documentValue >= $conditionValue[0]
                    && $documentValue <= $conditionValue[1];

            case 'in':
                return $this->inList($documentValue, $conditionValue);

            case 'not_in':
                return !$this->inList($documentValue, $conditionValue);

            case 'is_empty':
                return $this->isEmpty($documentValue);

            case 'is_not_empty':
                return !$this->isEmpty($documentValue);

            case 'regex':
                return is_string($documentValue) && is_string($conditionValue)
                    && @preg_match($conditionValue, $documentValue) === 1;

            default:
                return false;
        }
    }

    /**
     * Vérifie l'égalité (gère les tags)
     */
    private function equals($documentValue, $conditionValue): bool
    {
        // Cas des tags (array d'objets)
        if (is_array($documentValue) && isset($documentValue[0]['id'])) {
            $tagIds = array_column($documentValue, 'id');
            return in_array($conditionValue, $tagIds);
        }

        // Comparaison numérique si possible
        if (is_numeric($documentValue) && is_numeric($conditionValue)) {
            return (float)$documentValue === (float)$conditionValue;
        }

        // Comparaison de chaînes (insensible à la casse)
        if (is_string($documentValue) && is_string($conditionValue)) {
            return strtolower($documentValue) === strtolower($conditionValue);
        }

        return $documentValue === $conditionValue;
    }

    /**
     * Vérifie si une valeur contient une autre
     */
    private function contains($documentValue, $conditionValue): bool
    {
        // Cas des tags
        if (is_array($documentValue) && isset($documentValue[0]['id'])) {
            $tagIds = array_column($documentValue, 'id');
            $tagNames = array_map('strtolower', array_column($documentValue, 'name'));

            if (is_numeric($conditionValue)) {
                return in_array((int)$conditionValue, $tagIds);
            }
            return in_array(strtolower($conditionValue), $tagNames);
        }

        // Cas des chaînes
        if (is_string($documentValue) && is_string($conditionValue)) {
            return stripos($documentValue, $conditionValue) !== false;
        }

        return false;
    }

    /**
     * Vérifie si une valeur est dans une liste
     */
    private function inList($documentValue, $conditionValue): bool
    {
        if (!is_array($conditionValue)) {
            $conditionValue = [$conditionValue];
        }

        // Cas des tags
        if (is_array($documentValue) && isset($documentValue[0]['id'])) {
            $tagIds = array_column($documentValue, 'id');
            return !empty(array_intersect($conditionValue, $tagIds));
        }

        // Valeur scalaire
        if (is_numeric($documentValue)) {
            return in_array((int)$documentValue, array_map('intval', $conditionValue));
        }

        return in_array($documentValue, $conditionValue);
    }

    /**
     * Vérifie si une valeur est vide
     */
    private function isEmpty($value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        if (is_array($value) && empty($value)) {
            return true;
        }

        return false;
    }

    /**
     * Retourne les opérateurs disponibles pour un type de champ
     */
    public static function getOperatorsForFieldType(string $fieldType): array
    {
        $common = [
            'equals' => 'Égal à',
            'not_equals' => 'Différent de',
            'is_empty' => 'Est vide',
            'is_not_empty' => 'N\'est pas vide'
        ];

        $text = [
            'contains' => 'Contient',
            'not_contains' => 'Ne contient pas',
            'starts_with' => 'Commence par',
            'ends_with' => 'Termine par',
            'regex' => 'Expression régulière'
        ];

        $numeric = [
            'greater_than' => 'Supérieur à',
            'less_than' => 'Inférieur à',
            'between' => 'Entre'
        ];

        $list = [
            'in' => 'Dans la liste',
            'not_in' => 'Pas dans la liste'
        ];

        switch ($fieldType) {
            case 'correspondent':
            case 'document_type':
                return array_merge($common, $list);

            case 'tag':
                return [
                    'contains' => 'A le tag',
                    'not_contains' => 'N\'a pas le tag',
                    'in' => 'A un des tags',
                    'not_in' => 'N\'a aucun des tags',
                    'is_empty' => 'Aucun tag',
                    'is_not_empty' => 'A au moins un tag'
                ];

            case 'amount':
                return array_merge($common, $numeric);

            case 'content':
                return array_merge(['contains' => 'Contient', 'not_contains' => 'Ne contient pas'], $text);

            case 'date':
                return array_merge($common, ['between' => 'Entre']);

            case 'custom_field':
            default:
                return array_merge($common, $text, $numeric, $list);
        }
    }
}

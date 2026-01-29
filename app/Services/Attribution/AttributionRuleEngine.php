<?php
/**
 * K-Docs - Attribution Rule Engine
 * Moteur d'évaluation des règles d'attribution
 */

namespace KDocs\Services\Attribution;

use KDocs\Core\Database;
use KDocs\Models\AttributionRule;
use KDocs\Models\Document;

class AttributionRuleEngine
{
    private RuleConditionEvaluator $conditionEvaluator;

    public function __construct()
    {
        $this->conditionEvaluator = new RuleConditionEvaluator();
    }

    /**
     * Évalue toutes les règles actives sur un document
     *
     * @param int $documentId ID du document
     * @return array Résultat de l'évaluation avec actions à appliquer
     */
    public function evaluate(int $documentId): array
    {
        $startTime = microtime(true);

        // Charger le document avec ses données complètes
        $document = $this->loadDocument($documentId);
        if (!$document) {
            return [
                'success' => false,
                'error' => 'Document not found',
                'rules_evaluated' => 0,
                'rules_matched' => 0,
                'actions' => []
            ];
        }

        // Récupérer les règles actives triées par priorité
        $rules = AttributionRule::getActiveRules();

        $result = [
            'success' => true,
            'document_id' => $documentId,
            'rules_evaluated' => 0,
            'rules_matched' => 0,
            'actions' => [],
            'logs' => [],
            'execution_time_ms' => 0
        ];

        foreach ($rules as $rule) {
            $result['rules_evaluated']++;

            $ruleStartTime = microtime(true);
            $evaluation = $this->evaluateRule($rule, $document);
            $executionTimeMs = (int)((microtime(true) - $ruleStartTime) * 1000);

            // Log l'exécution
            AttributionRule::logExecution(
                $rule['id'],
                $documentId,
                $evaluation['matched'],
                $evaluation['conditions'],
                $evaluation['matched'] ? $rule['actions'] : [],
                $executionTimeMs
            );

            $result['logs'][] = [
                'rule_id' => $rule['id'],
                'rule_name' => $rule['name'],
                'matched' => $evaluation['matched'],
                'conditions_evaluated' => $evaluation['conditions'],
                'execution_time_ms' => $executionTimeMs
            ];

            if ($evaluation['matched']) {
                $result['rules_matched']++;

                // Collecter les actions
                foreach ($rule['actions'] as $action) {
                    $result['actions'][] = [
                        'rule_id' => $rule['id'],
                        'rule_name' => $rule['name'],
                        'action_type' => $action['action_type'],
                        'field_name' => $action['field_name'],
                        'value' => $this->parseValue($action['value'])
                    ];
                }

                // Si stop_on_match, arrêter l'évaluation
                if ($rule['stop_on_match']) {
                    break;
                }
            }
        }

        $result['execution_time_ms'] = (int)((microtime(true) - $startTime) * 1000);

        return $result;
    }

    /**
     * Évalue une règle spécifique sur un document
     */
    private function evaluateRule(array $rule, array $document): array
    {
        $conditions = $rule['conditions'] ?? [];

        if (empty($conditions)) {
            // Règle sans conditions = toujours match
            return [
                'matched' => true,
                'conditions' => []
            ];
        }

        // Grouper les conditions par group
        $groups = [];
        foreach ($conditions as $condition) {
            $group = $condition['condition_group'] ?? 0;
            $groups[$group][] = $condition;
        }

        $conditionResults = [];
        $allGroupsMatched = true;

        // Pour chaque groupe, toutes les conditions doivent matcher (AND)
        // Entre les groupes, c'est OR
        $anyGroupMatched = false;

        foreach ($groups as $groupId => $groupConditions) {
            $groupMatched = true;
            $groupResults = [];

            foreach ($groupConditions as $condition) {
                $evaluation = $this->conditionEvaluator->evaluate($condition, $document);
                $groupResults[] = $evaluation;

                if (!$evaluation['matched']) {
                    $groupMatched = false;
                }
            }

            $conditionResults[] = [
                'group' => $groupId,
                'matched' => $groupMatched,
                'conditions' => $groupResults
            ];

            if ($groupMatched) {
                $anyGroupMatched = true;
            }
        }

        return [
            'matched' => $anyGroupMatched,
            'conditions' => $conditionResults
        ];
    }

    /**
     * Charge un document avec toutes ses données
     */
    private function loadDocument(int $documentId): ?array
    {
        $db = Database::getInstance();

        // Document de base
        $stmt = $db->prepare("
            SELECT d.*,
                   dt.label as document_type_label,
                   c.name as correspondent_name
            FROM documents d
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            WHERE d.id = ?
        ");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$document) {
            return null;
        }

        // Charger le contenu OCR si disponible
        if (empty($document['ocr_content'])) {
            $document['ocr_content'] = $this->getOcrContent($documentId);
        }

        return $document;
    }

    /**
     * Récupère le contenu OCR d'un document
     */
    private function getOcrContent(int $documentId): string
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT ocr_content FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $result = $stmt->fetch();
        return $result ? ($result['ocr_content'] ?? '') : '';
    }

    /**
     * Parse une valeur d'action (peut être JSON)
     */
    private function parseValue($value)
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
     * Teste une règle sur un ou plusieurs documents sans appliquer les actions
     *
     * @param int $ruleId ID de la règle
     * @param array $documentIds Liste des IDs de documents à tester
     * @return array Résultats des tests
     */
    public function testRule(int $ruleId, array $documentIds): array
    {
        $rule = AttributionRule::find($ruleId);
        if (!$rule) {
            return ['error' => 'Rule not found'];
        }

        $results = [];

        foreach ($documentIds as $documentId) {
            $document = $this->loadDocument($documentId);
            if (!$document) {
                $results[] = [
                    'document_id' => $documentId,
                    'error' => 'Document not found'
                ];
                continue;
            }

            $evaluation = $this->evaluateRule($rule, $document);

            $results[] = [
                'document_id' => $documentId,
                'document_title' => $document['title'] ?? 'Sans titre',
                'matched' => $evaluation['matched'],
                'conditions' => $evaluation['conditions'],
                'would_apply' => $evaluation['matched'] ? $rule['actions'] : []
            ];
        }

        return [
            'rule' => [
                'id' => $rule['id'],
                'name' => $rule['name'],
                'conditions_count' => count($rule['conditions']),
                'actions_count' => count($rule['actions'])
            ],
            'results' => $results,
            'summary' => [
                'total' => count($documentIds),
                'matched' => count(array_filter($results, fn($r) => $r['matched'] ?? false)),
                'not_matched' => count(array_filter($results, fn($r) => !($r['matched'] ?? false) && !isset($r['error']))),
                'errors' => count(array_filter($results, fn($r) => isset($r['error'])))
            ]
        ];
    }

    /**
     * Retourne les types de champs disponibles pour les conditions
     */
    public static function getFieldTypes(): array
    {
        return [
            'correspondent' => [
                'label' => 'Correspondant',
                'description' => 'Le correspondant/fournisseur du document'
            ],
            'document_type' => [
                'label' => 'Type de document',
                'description' => 'Le type de document (facture, contrat, etc.)'
            ],
            'tag' => [
                'label' => 'Tag',
                'description' => 'Les tags assignés au document'
            ],
            'amount' => [
                'label' => 'Montant',
                'description' => 'Le montant du document'
            ],
            'content' => [
                'label' => 'Contenu (OCR)',
                'description' => 'Le texte extrait du document'
            ],
            'date' => [
                'label' => 'Date du document',
                'description' => 'La date du document'
            ],
            'custom_field' => [
                'label' => 'Champ personnalisé',
                'description' => 'Un champ personnalisé défini'
            ]
        ];
    }

    /**
     * Retourne les types d'actions disponibles
     */
    public static function getActionTypes(): array
    {
        return [
            'set_field' => [
                'label' => 'Définir un champ',
                'description' => 'Définit la valeur d\'un champ (compte comptable, centre de coût, etc.)',
                'requires_field_name' => true,
                'fields' => ['compte_comptable', 'centre_cout', 'projet']
            ],
            'add_tag' => [
                'label' => 'Ajouter un tag',
                'description' => 'Ajoute un tag au document',
                'requires_field_name' => false
            ],
            'remove_tag' => [
                'label' => 'Retirer un tag',
                'description' => 'Retire un tag du document',
                'requires_field_name' => false
            ],
            'move_to_folder' => [
                'label' => 'Déplacer vers dossier',
                'description' => 'Déplace le document vers un dossier logique',
                'requires_field_name' => false
            ],
            'set_correspondent' => [
                'label' => 'Définir correspondant',
                'description' => 'Définit le correspondant du document',
                'requires_field_name' => false
            ],
            'set_document_type' => [
                'label' => 'Définir type de document',
                'description' => 'Définit le type de document',
                'requires_field_name' => false
            ]
        ];
    }
}

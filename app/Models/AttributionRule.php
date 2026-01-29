<?php
/**
 * K-Docs - Modèle Attribution Rule
 * Règles d'attribution automatique (SI/ALORS)
 */

namespace KDocs\Models;

use KDocs\Core\Database;
use PDO;

class AttributionRule
{
    /**
     * Récupère toutes les règles avec leurs conditions et actions
     */
    public static function all(): array
    {
        $db = Database::getInstance();
        $rules = $db->query("
            SELECT ar.*, u.username as created_by_username
            FROM attribution_rules ar
            LEFT JOIN users u ON ar.created_by = u.id
            ORDER BY ar.priority DESC, ar.name
        ")->fetchAll();

        // Enrichir avec conditions et actions
        foreach ($rules as &$rule) {
            $rule['conditions'] = self::getConditions($rule['id']);
            $rule['actions'] = self::getActions($rule['id']);
        }

        return $rules;
    }

    /**
     * Récupère uniquement les règles actives triées par priorité
     */
    public static function getActiveRules(): array
    {
        $db = Database::getInstance();
        $rules = $db->query("
            SELECT * FROM attribution_rules
            WHERE is_active = 1
            ORDER BY priority DESC
        ")->fetchAll();

        foreach ($rules as &$rule) {
            $rule['conditions'] = self::getConditions($rule['id']);
            $rule['actions'] = self::getActions($rule['id']);
        }

        return $rules;
    }

    /**
     * Récupère une règle par ID
     */
    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT ar.*, u.username as created_by_username
            FROM attribution_rules ar
            LEFT JOIN users u ON ar.created_by = u.id
            WHERE ar.id = ?
        ");
        $stmt->execute([$id]);
        $rule = $stmt->fetch();

        if (!$rule) {
            return null;
        }

        $rule['conditions'] = self::getConditions($id);
        $rule['actions'] = self::getActions($id);

        return $rule;
    }

    /**
     * Récupère les conditions d'une règle
     */
    public static function getConditions(int $ruleId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT * FROM attribution_rule_conditions
            WHERE rule_id = ?
            ORDER BY condition_group, id
        ");
        $stmt->execute([$ruleId]);
        return $stmt->fetchAll();
    }

    /**
     * Récupère les actions d'une règle
     */
    public static function getActions(int $ruleId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT * FROM attribution_rule_actions
            WHERE rule_id = ?
            ORDER BY id
        ");
        $stmt->execute([$ruleId]);
        return $stmt->fetchAll();
    }

    /**
     * Crée une nouvelle règle
     */
    public static function create(array $data): int
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            INSERT INTO attribution_rules (name, description, priority, is_active, stop_on_match, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $data['priority'] ?? 100,
            $data['is_active'] ?? true,
            $data['stop_on_match'] ?? true,
            $data['created_by'] ?? null
        ]);

        return (int)$db->lastInsertId();
    }

    /**
     * Met à jour une règle
     */
    public static function update(int $id, array $data): bool
    {
        $db = Database::getInstance();

        $fields = [];
        $params = [];

        foreach (['name', 'description', 'priority', 'is_active', 'stop_on_match'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $id;
        $stmt = $db->prepare("UPDATE attribution_rules SET " . implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($params);
    }

    /**
     * Supprime une règle (et ses conditions/actions via CASCADE)
     */
    public static function delete(int $id): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM attribution_rules WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Ajoute une condition à une règle
     */
    public static function addCondition(int $ruleId, array $condition): int
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            INSERT INTO attribution_rule_conditions
            (rule_id, condition_group, field_type, field_name, operator, value)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $ruleId,
            $condition['condition_group'] ?? 0,
            $condition['field_type'],
            $condition['field_name'] ?? null,
            $condition['operator'],
            is_array($condition['value']) ? json_encode($condition['value']) : $condition['value']
        ]);

        return (int)$db->lastInsertId();
    }

    /**
     * Met à jour une condition
     */
    public static function updateCondition(int $conditionId, array $data): bool
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            UPDATE attribution_rule_conditions
            SET condition_group = ?, field_type = ?, field_name = ?, operator = ?, value = ?
            WHERE id = ?
        ");

        return $stmt->execute([
            $data['condition_group'] ?? 0,
            $data['field_type'],
            $data['field_name'] ?? null,
            $data['operator'],
            is_array($data['value']) ? json_encode($data['value']) : $data['value'],
            $conditionId
        ]);
    }

    /**
     * Supprime une condition
     */
    public static function deleteCondition(int $conditionId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM attribution_rule_conditions WHERE id = ?");
        return $stmt->execute([$conditionId]);
    }

    /**
     * Supprime toutes les conditions d'une règle
     */
    public static function clearConditions(int $ruleId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM attribution_rule_conditions WHERE rule_id = ?");
        return $stmt->execute([$ruleId]);
    }

    /**
     * Ajoute une action à une règle
     */
    public static function addAction(int $ruleId, array $action): int
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            INSERT INTO attribution_rule_actions (rule_id, action_type, field_name, value)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $ruleId,
            $action['action_type'],
            $action['field_name'] ?? null,
            is_array($action['value']) ? json_encode($action['value']) : $action['value']
        ]);

        return (int)$db->lastInsertId();
    }

    /**
     * Met à jour une action
     */
    public static function updateAction(int $actionId, array $data): bool
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            UPDATE attribution_rule_actions
            SET action_type = ?, field_name = ?, value = ?
            WHERE id = ?
        ");

        return $stmt->execute([
            $data['action_type'],
            $data['field_name'] ?? null,
            is_array($data['value']) ? json_encode($data['value']) : $data['value'],
            $actionId
        ]);
    }

    /**
     * Supprime une action
     */
    public static function deleteAction(int $actionId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM attribution_rule_actions WHERE id = ?");
        return $stmt->execute([$actionId]);
    }

    /**
     * Supprime toutes les actions d'une règle
     */
    public static function clearActions(int $ruleId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM attribution_rule_actions WHERE rule_id = ?");
        return $stmt->execute([$ruleId]);
    }

    /**
     * Enregistre un log d'exécution
     */
    public static function logExecution(int $ruleId, int $documentId, bool $matched, array $conditionsEvaluated = [], array $actionsApplied = [], int $executionTimeMs = 0): int
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            INSERT INTO attribution_rule_logs
            (rule_id, document_id, matched, conditions_evaluated, actions_applied, execution_time_ms)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $ruleId,
            $documentId,
            $matched,
            json_encode($conditionsEvaluated),
            json_encode($actionsApplied),
            $executionTimeMs
        ]);

        return (int)$db->lastInsertId();
    }

    /**
     * Récupère les logs d'exécution d'une règle
     */
    public static function getLogs(int $ruleId, int $limit = 50): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT arl.*, d.title as document_title
            FROM attribution_rule_logs arl
            LEFT JOIN documents d ON arl.document_id = d.id
            WHERE arl.rule_id = ?
            ORDER BY arl.created_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $ruleId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Récupère les logs d'exécution pour un document
     */
    public static function getDocumentLogs(int $documentId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT arl.*, ar.name as rule_name
            FROM attribution_rule_logs arl
            LEFT JOIN attribution_rules ar ON arl.rule_id = ar.id
            WHERE arl.document_id = ?
            ORDER BY arl.created_at DESC
        ");
        $stmt->execute([$documentId]);
        return $stmt->fetchAll();
    }

    /**
     * Duplique une règle
     */
    public static function duplicate(int $id): ?int
    {
        $rule = self::find($id);
        if (!$rule) {
            return null;
        }

        // Créer la nouvelle règle
        $newId = self::create([
            'name' => $rule['name'] . ' (copie)',
            'description' => $rule['description'],
            'priority' => $rule['priority'],
            'is_active' => false, // Désactivée par défaut
            'stop_on_match' => $rule['stop_on_match'],
            'created_by' => $rule['created_by']
        ]);

        // Copier les conditions
        foreach ($rule['conditions'] as $condition) {
            self::addCondition($newId, $condition);
        }

        // Copier les actions
        foreach ($rule['actions'] as $action) {
            self::addAction($newId, $action);
        }

        return $newId;
    }
}

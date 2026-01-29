<?php
/**
 * K-Docs - Extraction Template Model
 * Définit les champs à extraire et comment les extraire
 */

namespace KDocs\Models;

use KDocs\Core\Database;

class ExtractionTemplate
{
    /**
     * Récupère tous les templates actifs
     */
    public static function allActive(): array
    {
        $db = Database::getInstance();
        return $db->query("
            SELECT * FROM extraction_templates
            WHERE is_active = TRUE
            ORDER BY display_order, name
        ")->fetchAll();
    }

    /**
     * Récupère un template par ID
     */
    public static function findById(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM extraction_templates WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Récupère un template par code
     */
    public static function findByCode(string $fieldCode): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM extraction_templates WHERE field_code = ?");
        $stmt->execute([$fieldCode]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Récupère les templates applicables à un document
     */
    public static function getApplicable(?int $correspondentId, ?int $documentTypeId): array
    {
        $templates = self::allActive();
        $applicable = [];

        foreach ($templates as $template) {
            // Vérifier applies_to_types
            if (!empty($template['applies_to_types'])) {
                $types = json_decode($template['applies_to_types'], true) ?: [];
                if (!empty($types) && $documentTypeId && !in_array($documentTypeId, $types)) {
                    continue;
                }
            }

            // Vérifier applies_to_correspondents
            if (!empty($template['applies_to_correspondents'])) {
                $correspondents = json_decode($template['applies_to_correspondents'], true) ?: [];
                if (!empty($correspondents) && $correspondentId && !in_array($correspondentId, $correspondents)) {
                    continue;
                }
            }

            $applicable[] = $template;
        }

        return $applicable;
    }

    /**
     * Crée un nouveau template
     */
    public static function create(array $data): int
    {
        $db = Database::getInstance();

        $fields = ['name', 'field_code', 'field_type', 'description', 'options',
                   'applies_to_types', 'applies_to_correspondents',
                   'use_history', 'use_rules', 'rules', 'use_ai', 'ai_prompt',
                   'use_regex', 'regex_pattern', 'learn_from_corrections',
                   'min_confidence_for_auto', 'show_confidence',
                   'post_action', 'post_action_config', 'is_active', 'is_required',
                   'display_order', 'created_by'];

        $values = [];
        $placeholders = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $values[] = is_array($data[$field]) ? json_encode($data[$field]) : $data[$field];
                $placeholders[] = '?';
            }
        }

        $usedFields = array_filter($fields, fn($f) => array_key_exists($f, $data));

        $sql = "INSERT INTO extraction_templates (" . implode(', ', $usedFields) . ")
                VALUES (" . implode(', ', $placeholders) . ")";

        $stmt = $db->prepare($sql);
        $stmt->execute($values);

        return (int) $db->lastInsertId();
    }

    /**
     * Met à jour un template
     */
    public static function update(int $id, array $data): bool
    {
        $db = Database::getInstance();

        $sets = [];
        $values = [];

        foreach ($data as $key => $value) {
            $sets[] = "$key = ?";
            $values[] = is_array($value) ? json_encode($value) : $value;
        }

        $values[] = $id;

        $sql = "UPDATE extraction_templates SET " . implode(', ', $sets) . " WHERE id = ?";
        $stmt = $db->prepare($sql);

        return $stmt->execute($values);
    }

    /**
     * Supprime un template
     */
    public static function delete(int $id): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM extraction_templates WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Récupère les options d'un template (pour select/multi_select)
     */
    public static function getOptions(int $templateId): array
    {
        $template = self::findById($templateId);
        if (!$template || empty($template['options'])) {
            return [];
        }

        $options = json_decode($template['options'], true);
        if (!is_array($options)) {
            return [];
        }

        // Normaliser: peut être ["val1", "val2"] ou [{"value": "val1", "label": "Label 1"}]
        $normalized = [];
        foreach ($options as $opt) {
            if (is_string($opt)) {
                $normalized[] = ['value' => $opt, 'label' => $opt];
            } elseif (is_array($opt) && isset($opt['value'])) {
                $normalized[] = $opt;
            }
        }

        return $normalized;
    }

    /**
     * Ajoute une option à un template
     */
    public static function addOption(int $templateId, string $value, ?string $label = null): bool
    {
        $template = self::findById($templateId);
        if (!$template) {
            return false;
        }

        $options = json_decode($template['options'] ?? '[]', true) ?: [];
        $options[] = ['value' => $value, 'label' => $label ?? $value];

        return self::update($templateId, ['options' => $options]);
    }

    /**
     * Parse les règles d'un template
     */
    public static function parseRules(array $template): array
    {
        if (empty($template['rules'])) {
            return [];
        }

        $rules = json_decode($template['rules'], true);
        return is_array($rules) ? $rules : [];
    }
}

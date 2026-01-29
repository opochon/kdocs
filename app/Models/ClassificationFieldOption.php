<?php
/**
 * K-Docs - Modèle Classification Field Option
 * Options dropdown pour les champs de classification (comptes, centres de coût, projets)
 */

namespace KDocs\Models;

use KDocs\Core\Database;
use PDO;

class ClassificationFieldOption
{
    /**
     * Récupère toutes les options
     */
    public static function all(): array
    {
        $db = Database::getInstance();
        return $db->query("
            SELECT * FROM classification_field_options
            ORDER BY field_code, sort_order, option_label
        ")->fetchAll();
    }

    /**
     * Récupère les options pour un champ spécifique
     */
    public static function getForField(string $fieldCode, bool $activeOnly = true): array
    {
        $db = Database::getInstance();

        $sql = "SELECT * FROM classification_field_options WHERE field_code = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY sort_order, option_label";

        $stmt = $db->prepare($sql);
        $stmt->execute([$fieldCode]);
        return $stmt->fetchAll();
    }

    /**
     * Récupère les options groupées par champ
     */
    public static function getAllGrouped(bool $activeOnly = true): array
    {
        $options = $activeOnly ? self::getActive() : self::all();

        $grouped = [];
        foreach ($options as $option) {
            $grouped[$option['field_code']][] = $option;
        }

        return $grouped;
    }

    /**
     * Récupère uniquement les options actives
     */
    public static function getActive(): array
    {
        $db = Database::getInstance();
        return $db->query("
            SELECT * FROM classification_field_options
            WHERE is_active = 1
            ORDER BY field_code, sort_order, option_label
        ")->fetchAll();
    }

    /**
     * Récupère une option par ID
     */
    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM classification_field_options WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Récupère une option par code de champ et valeur
     */
    public static function findByValue(string $fieldCode, string $value): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT * FROM classification_field_options
            WHERE field_code = ? AND option_value = ?
        ");
        $stmt->execute([$fieldCode, $value]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Crée une nouvelle option
     */
    public static function create(array $data): int
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            INSERT INTO classification_field_options
            (field_code, option_value, option_label, description, is_active, sort_order)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['field_code'],
            $data['option_value'],
            $data['option_label'],
            $data['description'] ?? null,
            $data['is_active'] ?? true,
            $data['sort_order'] ?? 0
        ]);

        return (int)$db->lastInsertId();
    }

    /**
     * Met à jour une option
     */
    public static function update(int $id, array $data): bool
    {
        $db = Database::getInstance();

        $fields = [];
        $params = [];

        foreach (['option_value', 'option_label', 'description', 'is_active', 'sort_order'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $id;
        $stmt = $db->prepare("UPDATE classification_field_options SET " . implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($params);
    }

    /**
     * Supprime une option
     */
    public static function delete(int $id): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM classification_field_options WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Retourne les codes de champs disponibles
     */
    public static function getFieldCodes(): array
    {
        return [
            'compte_comptable' => 'Compte comptable',
            'centre_cout' => 'Centre de coût',
            'projet' => 'Projet'
        ];
    }

    /**
     * Vérifie si une valeur existe pour un champ
     */
    public static function valueExists(string $fieldCode, string $value, ?int $excludeId = null): bool
    {
        $db = Database::getInstance();

        $sql = "SELECT COUNT(*) FROM classification_field_options WHERE field_code = ? AND option_value = ?";
        $params = [$fieldCode, $value];

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Importe des options depuis un tableau
     */
    public static function importBatch(string $fieldCode, array $options): int
    {
        $count = 0;

        foreach ($options as $index => $option) {
            if (is_string($option)) {
                // Format simple: juste la valeur
                $option = [
                    'option_value' => $option,
                    'option_label' => $option
                ];
            }

            // Vérifier si l'option existe déjà
            if (self::valueExists($fieldCode, $option['option_value'])) {
                continue;
            }

            self::create([
                'field_code' => $fieldCode,
                'option_value' => $option['option_value'],
                'option_label' => $option['option_label'] ?? $option['option_value'],
                'description' => $option['description'] ?? null,
                'sort_order' => $option['sort_order'] ?? ($index * 10)
            ]);

            $count++;
        }

        return $count;
    }
}

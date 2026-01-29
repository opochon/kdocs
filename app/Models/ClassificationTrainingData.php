<?php
/**
 * K-Docs - Modèle Classification Training Data
 * Données d'entraînement pour le ML
 */

namespace KDocs\Models;

use KDocs\Core\Database;
use PDO;

class ClassificationTrainingData
{
    /**
     * Récupère les données d'entraînement pour un champ
     */
    public static function getForField(string $fieldCode, int $limit = 1000): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT ctd.*, d.title as document_title
            FROM classification_training_data ctd
            LEFT JOIN documents d ON ctd.document_id = d.id
            WHERE ctd.field_code = ?
            ORDER BY ctd.confidence DESC, ctd.created_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $fieldCode, PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Récupère les données d'entraînement pour une valeur spécifique
     */
    public static function getForFieldValue(string $fieldCode, string $fieldValue, int $limit = 100): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT ctd.*, d.title as document_title
            FROM classification_training_data ctd
            LEFT JOIN documents d ON ctd.document_id = d.id
            WHERE ctd.field_code = ? AND ctd.field_value = ?
            ORDER BY ctd.confidence DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $fieldCode, PDO::PARAM_STR);
        $stmt->bindValue(2, $fieldValue, PDO::PARAM_STR);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Récupère une entrée par ID
     */
    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM classification_training_data WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Récupère les données pour un document
     */
    public static function getForDocument(int $documentId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT * FROM classification_training_data
            WHERE document_id = ?
            ORDER BY field_code
        ");
        $stmt->execute([$documentId]);
        return $stmt->fetchAll();
    }

    /**
     * Crée ou met à jour une entrée d'entraînement
     */
    public static function upsert(array $data): int
    {
        $db = Database::getInstance();

        // Vérifier si une entrée existe déjà pour ce document et ce champ
        $stmt = $db->prepare("
            SELECT id FROM classification_training_data
            WHERE document_id = ? AND field_code = ?
        ");
        $stmt->execute([$data['document_id'], $data['field_code']]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Mise à jour
            $stmt = $db->prepare("
                UPDATE classification_training_data
                SET field_value = ?, features = ?, source = ?, confidence = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $data['field_value'],
                is_array($data['features']) ? json_encode($data['features']) : $data['features'],
                $data['source'] ?? 'manual',
                $data['confidence'] ?? 1.0,
                $existing['id']
            ]);
            return (int)$existing['id'];
        }

        // Nouvelle entrée
        $stmt = $db->prepare("
            INSERT INTO classification_training_data
            (document_id, field_code, field_value, features, source, confidence)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['document_id'],
            $data['field_code'],
            $data['field_value'],
            is_array($data['features']) ? json_encode($data['features']) : $data['features'],
            $data['source'] ?? 'manual',
            $data['confidence'] ?? 1.0
        ]);

        return (int)$db->lastInsertId();
    }

    /**
     * Supprime les données d'un document
     */
    public static function deleteForDocument(int $documentId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM classification_training_data WHERE document_id = ?");
        return $stmt->execute([$documentId]);
    }

    /**
     * Récupère les valeurs distinctes pour un champ avec leur fréquence
     */
    public static function getDistinctValues(string $fieldCode): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT field_value, COUNT(*) as count, AVG(confidence) as avg_confidence
            FROM classification_training_data
            WHERE field_code = ?
            GROUP BY field_value
            ORDER BY count DESC
        ");
        $stmt->execute([$fieldCode]);
        return $stmt->fetchAll();
    }

    /**
     * Compte le nombre d'entrées d'entraînement
     */
    public static function count(?string $fieldCode = null): int
    {
        $db = Database::getInstance();

        if ($fieldCode) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM classification_training_data WHERE field_code = ?");
            $stmt->execute([$fieldCode]);
        } else {
            $stmt = $db->query("SELECT COUNT(*) FROM classification_training_data");
        }

        return (int)$stmt->fetchColumn();
    }

    /**
     * Récupère les statistiques d'entraînement
     */
    public static function getStats(): array
    {
        $db = Database::getInstance();

        $stats = [
            'total' => self::count(),
            'by_field' => [],
            'by_source' => [],
            'recent_30_days' => 0
        ];

        // Par champ
        $result = $db->query("
            SELECT field_code, COUNT(*) as count, COUNT(DISTINCT field_value) as unique_values
            FROM classification_training_data
            GROUP BY field_code
        ")->fetchAll();

        foreach ($result as $row) {
            $stats['by_field'][$row['field_code']] = [
                'count' => (int)$row['count'],
                'unique_values' => (int)$row['unique_values']
            ];
        }

        // Par source
        $result = $db->query("
            SELECT source, COUNT(*) as count
            FROM classification_training_data
            GROUP BY source
        ")->fetchAll();

        foreach ($result as $row) {
            $stats['by_source'][$row['source']] = (int)$row['count'];
        }

        // Récent
        $stats['recent_30_days'] = (int)$db->query("
            SELECT COUNT(*) FROM classification_training_data
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ")->fetchColumn();

        return $stats;
    }

    /**
     * Nettoie les anciennes données (optionnel, pour maintenance)
     */
    public static function cleanup(int $keepDays = 365): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            DELETE FROM classification_training_data
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            AND source != 'manual'
        ");
        $stmt->execute([$keepDays]);
        return $stmt->rowCount();
    }
}

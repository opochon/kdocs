<?php
/**
 * K-Docs - Modèle Snapshot
 * Gestion des snapshots système pour backup et versioning
 */

namespace KDocs\Models;

use KDocs\Core\Database;
use PDO;

class Snapshot
{
    /**
     * Récupère tous les snapshots avec pagination
     */
    public static function getAll(int $limit = 20, int $offset = 0, ?string $type = null): array
    {
        $db = Database::getInstance();

        $sql = "
            SELECT s.*, u.username as created_by_username
            FROM snapshots s
            LEFT JOIN users u ON s.created_by = u.id
        ";

        $params = [];
        if ($type) {
            $sql .= " WHERE s.snapshot_type = :type";
            $params['type'] = $type;
        }

        $sql .= " ORDER BY s.created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Compte le nombre de snapshots
     */
    public static function count(?string $type = null): int
    {
        $db = Database::getInstance();

        $sql = "SELECT COUNT(*) FROM snapshots";
        $params = [];

        if ($type) {
            $sql .= " WHERE snapshot_type = :type";
            $params['type'] = $type;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Récupère un snapshot par ID
     */
    public static function findById(int $id): ?array
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            SELECT s.*, u.username as created_by_username
            FROM snapshots s
            LEFT JOIN users u ON s.created_by = u.id
            WHERE s.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();

        if ($result && $result['metadata']) {
            $result['metadata'] = json_decode($result['metadata'], true);
        }

        return $result ?: null;
    }

    /**
     * Crée un nouveau snapshot
     */
    public static function create(array $data): int
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            INSERT INTO snapshots (
                name, description, snapshot_type, total_documents, total_size_bytes,
                total_folders, metadata, status, created_by
            ) VALUES (
                :name, :description, :snapshot_type, :total_documents, :total_size_bytes,
                :total_folders, :metadata, :status, :created_by
            )
        ");

        $stmt->execute([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'snapshot_type' => $data['snapshot_type'] ?? 'manual',
            'total_documents' => $data['total_documents'] ?? 0,
            'total_size_bytes' => $data['total_size_bytes'] ?? 0,
            'total_folders' => $data['total_folders'] ?? 0,
            'metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            'status' => $data['status'] ?? 'pending',
            'created_by' => $data['created_by'] ?? null,
        ]);

        return (int)$db->lastInsertId();
    }

    /**
     * Met à jour un snapshot
     */
    public static function update(int $id, array $data): bool
    {
        $db = Database::getInstance();

        $fields = [];
        $params = ['id' => $id];

        foreach (['name', 'description', 'status', 'error_message', 'completed_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[$field] = $data[$field];
            }
        }

        // Champs numériques
        foreach (['total_documents', 'total_size_bytes', 'total_folders'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[$field] = $data[$field];
            }
        }

        // Metadata en JSON
        if (array_key_exists('metadata', $data)) {
            $fields[] = "metadata = :metadata";
            $params['metadata'] = is_array($data['metadata']) ? json_encode($data['metadata']) : $data['metadata'];
        }

        if (empty($fields)) {
            return false;
        }

        $stmt = $db->prepare("UPDATE snapshots SET " . implode(', ', $fields) . " WHERE id = :id");
        return $stmt->execute($params);
    }

    /**
     * Supprime un snapshot et ses items
     */
    public static function delete(int $id): bool
    {
        $db = Database::getInstance();

        // Les items sont supprimés par CASCADE
        $stmt = $db->prepare("DELETE FROM snapshots WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }

    /**
     * Récupère les items d'un snapshot
     */
    public static function getItems(int $snapshotId, ?string $entityType = null, int $limit = 100, int $offset = 0): array
    {
        $db = Database::getInstance();

        $sql = "SELECT * FROM snapshot_items WHERE snapshot_id = :snapshot_id";
        $params = ['snapshot_id' => $snapshotId];

        if ($entityType) {
            $sql .= " AND entity_type = :entity_type";
            $params['entity_type'] = $entityType;
        }

        $sql .= " ORDER BY entity_type, entity_id LIMIT :limit OFFSET :offset";

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':snapshot_id', $snapshotId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        if ($entityType) {
            $stmt->bindValue(':entity_type', $entityType);
        }
        $stmt->execute();

        $items = $stmt->fetchAll();

        // Décoder les données JSON
        foreach ($items as &$item) {
            if ($item['data_snapshot']) {
                $item['data_snapshot'] = json_decode($item['data_snapshot'], true);
            }
        }

        return $items;
    }

    /**
     * Ajoute un item au snapshot
     */
    public static function addItem(int $snapshotId, string $entityType, int $entityId, array $data, string $action = 'unchanged', ?string $contentHash = null): int
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            INSERT INTO snapshot_items (snapshot_id, entity_type, entity_id, action, data_snapshot, content_hash)
            VALUES (:snapshot_id, :entity_type, :entity_id, :action, :data_snapshot, :content_hash)
        ");

        $stmt->execute([
            'snapshot_id' => $snapshotId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'data_snapshot' => json_encode($data),
            'content_hash' => $contentHash,
        ]);

        return (int)$db->lastInsertId();
    }

    /**
     * Récupère le dernier snapshot complété
     */
    public static function getLatest(?string $type = null): ?array
    {
        $db = Database::getInstance();

        $sql = "SELECT * FROM snapshots WHERE status = 'completed'";
        $params = [];

        if ($type) {
            $sql .= " AND snapshot_type = :type";
            $params['type'] = $type;
        }

        $sql .= " ORDER BY completed_at DESC LIMIT 1";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();

        if ($result && $result['metadata']) {
            $result['metadata'] = json_decode($result['metadata'], true);
        }

        return $result ?: null;
    }

    /**
     * Calcule le delta depuis le dernier snapshot
     */
    public static function calculateDelta(int $snapshotId): array
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            SELECT
                entity_type,
                action,
                COUNT(*) as count
            FROM snapshot_items
            WHERE snapshot_id = :snapshot_id
            GROUP BY entity_type, action
        ");
        $stmt->execute(['snapshot_id' => $snapshotId]);

        $delta = [];
        while ($row = $stmt->fetch()) {
            $type = $row['entity_type'];
            if (!isset($delta[$type])) {
                $delta[$type] = ['created' => 0, 'modified' => 0, 'deleted' => 0, 'unchanged' => 0];
            }
            $delta[$type][$row['action']] = (int)$row['count'];
        }

        return $delta;
    }

    /**
     * Nettoie les anciens snapshots selon la politique de rétention
     */
    public static function cleanup(int $maxCount = 30, int $retentionDays = 90): int
    {
        $db = Database::getInstance();
        $deleted = 0;

        // Supprimer par âge
        $stmt = $db->prepare("
            DELETE FROM snapshots
            WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)
            AND snapshot_type != 'backup'
        ");
        $stmt->execute(['days' => $retentionDays]);
        $deleted += $stmt->rowCount();

        // Garder seulement les N derniers
        $stmt = $db->prepare("
            DELETE FROM snapshots
            WHERE id NOT IN (
                SELECT id FROM (
                    SELECT id FROM snapshots ORDER BY created_at DESC LIMIT :max_count
                ) as keep
            )
            AND snapshot_type != 'backup'
        ");
        $stmt->bindValue(':max_count', $maxCount, PDO::PARAM_INT);
        $stmt->execute();
        $deleted += $stmt->rowCount();

        return $deleted;
    }
}

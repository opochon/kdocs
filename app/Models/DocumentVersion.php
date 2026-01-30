<?php
/**
 * K-Docs - Modèle DocumentVersion
 * Gestion des versions de documents
 */

namespace KDocs\Models;

use KDocs\Core\Database;
use PDO;

class DocumentVersion
{
    /**
     * Récupère toutes les versions d'un document
     */
    public static function getByDocument(int $documentId): array
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            SELECT dv.*, u.username as created_by_username
            FROM document_versions dv
            LEFT JOIN users u ON dv.created_by = u.id
            WHERE dv.document_id = :document_id
            ORDER BY dv.version_number DESC
        ");
        $stmt->execute(['document_id' => $documentId]);

        return $stmt->fetchAll();
    }

    /**
     * Récupère une version spécifique
     */
    public static function findById(int $id): ?array
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            SELECT dv.*, u.username as created_by_username
            FROM document_versions dv
            LEFT JOIN users u ON dv.created_by = u.id
            WHERE dv.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Récupère une version par document et numéro de version
     */
    public static function findByVersion(int $documentId, int $versionNumber): ?array
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            SELECT dv.*, u.username as created_by_username
            FROM document_versions dv
            LEFT JOIN users u ON dv.created_by = u.id
            WHERE dv.document_id = :document_id AND dv.version_number = :version_number
        ");
        $stmt->execute([
            'document_id' => $documentId,
            'version_number' => $versionNumber
        ]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Récupère la version courante d'un document
     */
    public static function getCurrent(int $documentId): ?array
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            SELECT dv.*, u.username as created_by_username
            FROM document_versions dv
            LEFT JOIN users u ON dv.created_by = u.id
            WHERE dv.document_id = :document_id AND dv.is_current = TRUE
        ");
        $stmt->execute(['document_id' => $documentId]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Crée une nouvelle version d'un document
     * Le numéro de version est auto-incrémenté par le trigger
     */
    public static function create(array $data): int
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            INSERT INTO document_versions (
                document_id, filename, file_path, file_size, mime_type, checksum,
                title, content_text, changes_summary, delta_size, created_by, comment, is_current
            ) VALUES (
                :document_id, :filename, :file_path, :file_size, :mime_type, :checksum,
                :title, :content_text, :changes_summary, :delta_size, :created_by, :comment, TRUE
            )
        ");

        $stmt->execute([
            'document_id' => $data['document_id'],
            'filename' => $data['filename'],
            'file_path' => $data['file_path'],
            'file_size' => $data['file_size'],
            'mime_type' => $data['mime_type'],
            'checksum' => $data['checksum'],
            'title' => $data['title'] ?? null,
            'content_text' => $data['content_text'] ?? null,
            'changes_summary' => $data['changes_summary'] ?? null,
            'delta_size' => $data['delta_size'] ?? null,
            'created_by' => $data['created_by'] ?? null,
            'comment' => $data['comment'] ?? null,
        ]);

        return (int)$db->lastInsertId();
    }

    /**
     * Crée la première version d'un document (initialisation)
     */
    public static function createInitial(int $documentId, array $documentData): int
    {
        // Calculer le checksum du fichier
        $checksum = '';
        if (!empty($documentData['file_path']) && file_exists($documentData['file_path'])) {
            $checksum = hash_file('sha256', $documentData['file_path']);
        }

        return self::create([
            'document_id' => $documentId,
            'filename' => $documentData['filename'],
            'file_path' => $documentData['file_path'],
            'file_size' => $documentData['file_size'] ?? 0,
            'mime_type' => $documentData['mime_type'] ?? 'application/octet-stream',
            'checksum' => $checksum,
            'title' => $documentData['title'] ?? null,
            'content_text' => $documentData['content'] ?? null,
            'created_by' => $documentData['created_by'] ?? null,
            'comment' => 'Version initiale',
        ]);
    }

    /**
     * Restaure une version précédente comme version courante
     */
    public static function restore(int $documentId, int $versionNumber, int $userId): ?int
    {
        $version = self::findByVersion($documentId, $versionNumber);
        if (!$version) {
            return null;
        }

        // Créer une nouvelle version basée sur l'ancienne
        return self::create([
            'document_id' => $documentId,
            'filename' => $version['filename'],
            'file_path' => $version['file_path'],
            'file_size' => $version['file_size'],
            'mime_type' => $version['mime_type'],
            'checksum' => $version['checksum'],
            'title' => $version['title'],
            'content_text' => $version['content_text'],
            'changes_summary' => "Restauration de la version {$versionNumber}",
            'created_by' => $userId,
            'comment' => "Restauré depuis la version {$versionNumber}",
        ]);
    }

    /**
     * Compte le nombre de versions d'un document
     */
    public static function countByDocument(int $documentId): int
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("SELECT COUNT(*) FROM document_versions WHERE document_id = :document_id");
        $stmt->execute(['document_id' => $documentId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Supprime les anciennes versions (garder les N dernières)
     */
    public static function pruneOldVersions(int $documentId, int $keepCount = 50): int
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            DELETE FROM document_versions
            WHERE document_id = :document_id
            AND id NOT IN (
                SELECT id FROM (
                    SELECT id FROM document_versions
                    WHERE document_id = :document_id2
                    ORDER BY version_number DESC
                    LIMIT :keep_count
                ) as keep
            )
        ");
        $stmt->bindValue(':document_id', $documentId, PDO::PARAM_INT);
        $stmt->bindValue(':document_id2', $documentId, PDO::PARAM_INT);
        $stmt->bindValue(':keep_count', $keepCount, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Récupère ou calcule le diff entre deux versions
     */
    public static function getDiff(int $documentId, int $fromVersion, int $toVersion, string $diffType = 'text'): ?array
    {
        $db = Database::getInstance();

        // Vérifier le cache
        $stmt = $db->prepare("
            SELECT * FROM version_diffs
            WHERE document_id = :document_id
            AND from_version = :from_version
            AND to_version = :to_version
            AND diff_type = :diff_type
        ");
        $stmt->execute([
            'document_id' => $documentId,
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
            'diff_type' => $diffType,
        ]);

        $cached = $stmt->fetch();
        if ($cached) {
            $cached['diff_stats'] = json_decode($cached['diff_stats'], true);
            return $cached;
        }

        // Calculer le diff
        $fromData = self::findByVersion($documentId, $fromVersion);
        $toData = self::findByVersion($documentId, $toVersion);

        if (!$fromData || !$toData) {
            return null;
        }

        $diff = self::computeDiff($fromData, $toData, $diffType);

        // Mettre en cache
        $stmt = $db->prepare("
            INSERT INTO version_diffs (document_id, from_version, to_version, diff_type, diff_content, diff_stats)
            VALUES (:document_id, :from_version, :to_version, :diff_type, :diff_content, :diff_stats)
        ");
        $stmt->execute([
            'document_id' => $documentId,
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
            'diff_type' => $diffType,
            'diff_content' => $diff['content'],
            'diff_stats' => json_encode($diff['stats']),
        ]);

        return [
            'diff_content' => $diff['content'],
            'diff_stats' => $diff['stats'],
            'computed_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Calcule le diff entre deux versions
     */
    private static function computeDiff(array $from, array $to, string $type): array
    {
        $stats = [
            'added' => 0,
            'removed' => 0,
            'changed' => 0,
        ];

        if ($type === 'metadata') {
            // Diff des métadonnées
            $fromMeta = ['title' => $from['title'], 'mime_type' => $from['mime_type'], 'file_size' => $from['file_size']];
            $toMeta = ['title' => $to['title'], 'mime_type' => $to['mime_type'], 'file_size' => $to['file_size']];

            $changes = [];
            foreach ($toMeta as $key => $value) {
                if ($fromMeta[$key] !== $value) {
                    $changes[$key] = ['from' => $fromMeta[$key], 'to' => $value];
                    $stats['changed']++;
                }
            }

            return ['content' => json_encode($changes), 'stats' => $stats];
        }

        if ($type === 'binary') {
            // Pour les fichiers binaires, juste comparer les checksums
            $same = $from['checksum'] === $to['checksum'];
            return [
                'content' => json_encode(['identical' => $same, 'size_diff' => $to['file_size'] - $from['file_size']]),
                'stats' => ['changed' => $same ? 0 : 1],
            ];
        }

        // Diff textuel
        $fromLines = explode("\n", $from['content_text'] ?? '');
        $toLines = explode("\n", $to['content_text'] ?? '');

        // Algorithme de diff simple (LCS-based)
        $diff = [];
        $fromCount = count($fromLines);
        $toCount = count($toLines);

        $i = 0;
        $j = 0;

        while ($i < $fromCount || $j < $toCount) {
            if ($i >= $fromCount) {
                $diff[] = ['type' => 'add', 'line' => $toLines[$j]];
                $stats['added']++;
                $j++;
            } elseif ($j >= $toCount) {
                $diff[] = ['type' => 'remove', 'line' => $fromLines[$i]];
                $stats['removed']++;
                $i++;
            } elseif ($fromLines[$i] === $toLines[$j]) {
                $diff[] = ['type' => 'same', 'line' => $fromLines[$i]];
                $i++;
                $j++;
            } else {
                // Chercher la ligne dans le reste
                $foundInTo = array_search($fromLines[$i], array_slice($toLines, $j), true);
                $foundInFrom = array_search($toLines[$j], array_slice($fromLines, $i), true);

                if ($foundInTo !== false && ($foundInFrom === false || $foundInTo <= $foundInFrom)) {
                    $diff[] = ['type' => 'add', 'line' => $toLines[$j]];
                    $stats['added']++;
                    $j++;
                } else {
                    $diff[] = ['type' => 'remove', 'line' => $fromLines[$i]];
                    $stats['removed']++;
                    $i++;
                }
            }
        }

        return ['content' => json_encode($diff), 'stats' => $stats];
    }

    /**
     * Récupère les statistiques de versioning pour un document
     */
    public static function getStats(int $documentId): array
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            SELECT
                COUNT(*) as total_versions,
                MIN(created_at) as first_version_at,
                MAX(created_at) as last_version_at,
                SUM(file_size) as total_storage,
                AVG(file_size) as avg_file_size
            FROM document_versions
            WHERE document_id = :document_id
        ");
        $stmt->execute(['document_id' => $documentId]);

        return $stmt->fetch() ?: [
            'total_versions' => 0,
            'first_version_at' => null,
            'last_version_at' => null,
            'total_storage' => 0,
            'avg_file_size' => 0,
        ];
    }
}

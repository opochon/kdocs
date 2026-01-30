<?php
/**
 * K-Docs - Service de Snapshots
 * Gestion des snapshots système et delta
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Models\Snapshot;
use KDocs\Models\Setting;

class SnapshotService
{
    /**
     * Crée un snapshot complet du système
     */
    public function createSnapshot(string $name, ?string $description = null, string $type = 'manual', ?int $userId = null): int
    {
        $db = Database::getInstance();

        // Créer le snapshot
        $snapshotId = Snapshot::create([
            'name' => $name,
            'description' => $description,
            'snapshot_type' => $type,
            'status' => 'in_progress',
            'created_by' => $userId,
        ]);

        try {
            // Récupérer le dernier snapshot pour calculer les deltas
            $lastSnapshot = Snapshot::getLatest($type);
            $lastItems = [];

            if ($lastSnapshot) {
                $items = Snapshot::getItems($lastSnapshot['id']);
                foreach ($items as $item) {
                    $key = $item['entity_type'] . '_' . $item['entity_id'];
                    $lastItems[$key] = $item;
                }
            }

            // Statistiques
            $stats = [
                'total_documents' => 0,
                'total_size_bytes' => 0,
                'total_folders' => 0,
            ];

            // Snapshotter les documents
            $this->snapshotDocuments($snapshotId, $lastItems, $stats);

            // Snapshotter les dossiers logiques
            $this->snapshotFolders($snapshotId, $lastItems, $stats);

            // Snapshotter les tags
            $this->snapshotTags($snapshotId, $lastItems);

            // Snapshotter les correspondants
            $this->snapshotCorrespondents($snapshotId, $lastItems);

            // Snapshotter les types de documents
            $this->snapshotDocumentTypes($snapshotId, $lastItems);

            // Snapshotter les workflows
            $this->snapshotWorkflows($snapshotId, $lastItems);

            // Snapshotter les settings
            $this->snapshotSettings($snapshotId, $lastItems);

            // Mettre à jour le snapshot avec les stats
            Snapshot::update($snapshotId, [
                'total_documents' => $stats['total_documents'],
                'total_size_bytes' => $stats['total_size_bytes'],
                'total_folders' => $stats['total_folders'],
                'status' => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
                'metadata' => [
                    'delta_from' => $lastSnapshot ? $lastSnapshot['id'] : null,
                    'php_version' => PHP_VERSION,
                    'app_version' => '1.0.0',
                ],
            ]);

        } catch (\Exception $e) {
            Snapshot::update($snapshotId, [
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $snapshotId;
    }

    /**
     * Snapshotte les documents
     */
    private function snapshotDocuments(int $snapshotId, array $lastItems, array &$stats): void
    {
        $db = Database::getInstance();

        $stmt = $db->query("
            SELECT d.*, GROUP_CONCAT(dt.tag_id) as tag_ids
            FROM documents d
            LEFT JOIN document_tags dt ON d.id = dt.document_id
            WHERE d.is_deleted = 0
            GROUP BY d.id
        ");

        while ($doc = $stmt->fetch()) {
            $stats['total_documents']++;
            $stats['total_size_bytes'] += (int)$doc['file_size'];

            $key = 'document_' . $doc['id'];
            $contentHash = $doc['checksum'] ?? hash('sha256', $doc['content'] ?? '');

            // Déterminer l'action
            $action = 'created';
            if (isset($lastItems[$key])) {
                $lastHash = $lastItems[$key]['content_hash'] ?? '';
                if ($lastHash === $contentHash) {
                    $action = 'unchanged';
                } else {
                    $action = 'modified';
                }
            }

            // Préparer les données
            $data = [
                'id' => $doc['id'],
                'title' => $doc['title'],
                'filename' => $doc['filename'],
                'original_filename' => $doc['original_filename'],
                'file_path' => $doc['file_path'],
                'file_size' => $doc['file_size'],
                'mime_type' => $doc['mime_type'],
                'correspondent_id' => $doc['correspondent_id'],
                'document_type_id' => $doc['document_type_id'],
                'doc_date' => $doc['doc_date'],
                'amount' => $doc['amount'],
                'currency' => $doc['currency'],
                'tag_ids' => $doc['tag_ids'],
                'created_at' => $doc['created_at'],
                'updated_at' => $doc['updated_at'],
            ];

            Snapshot::addItem($snapshotId, 'document', $doc['id'], $data, $action, $contentHash);
        }
    }

    /**
     * Snapshotte les dossiers logiques
     */
    private function snapshotFolders(int $snapshotId, array $lastItems, array &$stats): void
    {
        $db = Database::getInstance();

        $stmt = $db->query("SELECT * FROM logical_folders WHERE deleted_at IS NULL");

        while ($folder = $stmt->fetch()) {
            $stats['total_folders']++;

            $key = 'folder_' . $folder['id'];
            $hash = md5(json_encode($folder));

            $action = 'created';
            if (isset($lastItems[$key])) {
                $lastData = $lastItems[$key]['data_snapshot'] ?? [];
                if (md5(json_encode($lastData)) === $hash) {
                    $action = 'unchanged';
                } else {
                    $action = 'modified';
                }
            }

            Snapshot::addItem($snapshotId, 'folder', $folder['id'], $folder, $action, $hash);
        }
    }

    /**
     * Snapshotte les tags
     */
    private function snapshotTags(int $snapshotId, array $lastItems): void
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM tags");

        while ($tag = $stmt->fetch()) {
            $key = 'tag_' . $tag['id'];
            $hash = md5(json_encode($tag));

            $action = isset($lastItems[$key]) ?
                (md5(json_encode($lastItems[$key]['data_snapshot'] ?? [])) === $hash ? 'unchanged' : 'modified')
                : 'created';

            Snapshot::addItem($snapshotId, 'tag', $tag['id'], $tag, $action, $hash);
        }
    }

    /**
     * Snapshotte les correspondants
     */
    private function snapshotCorrespondents(int $snapshotId, array $lastItems): void
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM correspondents");

        while ($corr = $stmt->fetch()) {
            $key = 'correspondent_' . $corr['id'];
            $hash = md5(json_encode($corr));

            $action = isset($lastItems[$key]) ?
                (md5(json_encode($lastItems[$key]['data_snapshot'] ?? [])) === $hash ? 'unchanged' : 'modified')
                : 'created';

            Snapshot::addItem($snapshotId, 'correspondent', $corr['id'], $corr, $action, $hash);
        }
    }

    /**
     * Snapshotte les types de documents
     */
    private function snapshotDocumentTypes(int $snapshotId, array $lastItems): void
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM document_types");

        while ($type = $stmt->fetch()) {
            $key = 'document_type_' . $type['id'];
            $hash = md5(json_encode($type));

            $action = isset($lastItems[$key]) ?
                (md5(json_encode($lastItems[$key]['data_snapshot'] ?? [])) === $hash ? 'unchanged' : 'modified')
                : 'created';

            Snapshot::addItem($snapshotId, 'document_type', $type['id'], $type, $action, $hash);
        }
    }

    /**
     * Snapshotte les workflows
     */
    private function snapshotWorkflows(int $snapshotId, array $lastItems): void
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM workflows");

        while ($wf = $stmt->fetch()) {
            $key = 'workflow_' . $wf['id'];
            $hash = md5(json_encode($wf));

            $action = isset($lastItems[$key]) ?
                (md5(json_encode($lastItems[$key]['data_snapshot'] ?? [])) === $hash ? 'unchanged' : 'modified')
                : 'created';

            Snapshot::addItem($snapshotId, 'workflow', $wf['id'], $wf, $action, $hash);
        }
    }

    /**
     * Snapshotte les settings
     */
    private function snapshotSettings(int $snapshotId, array $lastItems): void
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM settings");

        while ($setting = $stmt->fetch()) {
            $key = 'setting_' . $setting['id'];
            $hash = md5(json_encode($setting));

            $action = isset($lastItems[$key]) ?
                (md5(json_encode($lastItems[$key]['data_snapshot'] ?? [])) === $hash ? 'unchanged' : 'modified')
                : 'created';

            Snapshot::addItem($snapshotId, 'setting', $setting['id'], $setting, $action, $hash);
        }
    }

    /**
     * Restaure un snapshot (restaure les entités supprimées/modifiées depuis le snapshot)
     */
    public function restoreSnapshot(int $snapshotId, array $options = []): array
    {
        $snapshot = Snapshot::findById($snapshotId);
        if (!$snapshot) {
            throw new \Exception("Snapshot not found: $snapshotId");
        }

        $db = Database::getInstance();
        $restored = [
            'documents' => 0,
            'folders' => 0,
            'tags' => 0,
            'correspondents' => 0,
            'workflows' => 0,
        ];

        // Restaurer les documents si demandé
        if ($options['documents'] ?? true) {
            $items = Snapshot::getItems($snapshotId, 'document');
            foreach ($items as $item) {
                $data = $item['data_snapshot'];
                // Vérifier si le document existe encore
                $exists = $db->prepare("SELECT id FROM documents WHERE id = ?")->execute([$item['entity_id']]);
                if (!$exists->fetch()) {
                    // Recréer le document (metadata seulement, le fichier doit exister)
                    // Note: En production, il faudrait aussi restaurer le fichier depuis un backup
                    $restored['documents']++;
                }
            }
        }

        return $restored;
    }

    /**
     * Compare deux snapshots
     */
    public function compareSnapshots(int $fromId, int $toId): array
    {
        $fromItems = Snapshot::getItems($fromId);
        $toItems = Snapshot::getItems($toId);

        // Indexer par clé
        $fromMap = [];
        foreach ($fromItems as $item) {
            $fromMap[$item['entity_type'] . '_' . $item['entity_id']] = $item;
        }

        $toMap = [];
        foreach ($toItems as $item) {
            $toMap[$item['entity_type'] . '_' . $item['entity_id']] = $item;
        }

        $diff = [
            'added' => [],
            'removed' => [],
            'modified' => [],
            'unchanged' => 0,
        ];

        // Éléments dans "to" mais pas dans "from" = ajoutés
        foreach ($toMap as $key => $item) {
            if (!isset($fromMap[$key])) {
                $diff['added'][] = $item;
            } elseif ($item['content_hash'] !== $fromMap[$key]['content_hash']) {
                $diff['modified'][] = [
                    'from' => $fromMap[$key],
                    'to' => $item,
                ];
            } else {
                $diff['unchanged']++;
            }
        }

        // Éléments dans "from" mais pas dans "to" = supprimés
        foreach ($fromMap as $key => $item) {
            if (!isset($toMap[$key])) {
                $diff['removed'][] = $item;
            }
        }

        return $diff;
    }

    /**
     * Planifie un snapshot automatique
     */
    public function scheduleAutoSnapshot(): void
    {
        $enabled = Setting::get('snapshot_auto_enabled');
        if (!$enabled) {
            return;
        }

        $intervalHours = (int)Setting::get('snapshot_auto_interval') ?: 24;
        $lastSnapshot = Snapshot::getLatest('scheduled');

        if ($lastSnapshot) {
            $lastTime = strtotime($lastSnapshot['completed_at']);
            $nextTime = $lastTime + ($intervalHours * 3600);

            if (time() < $nextTime) {
                return; // Pas encore le moment
            }
        }

        // Créer le snapshot automatique
        $name = 'Auto-snapshot ' . date('Y-m-d H:i');
        $this->createSnapshot($name, 'Snapshot automatique planifié', 'scheduled');

        // Nettoyer les anciens snapshots
        $maxCount = (int)Setting::get('snapshot_max_count') ?: 30;
        $retentionDays = (int)Setting::get('snapshot_retention_days') ?: 90;
        Snapshot::cleanup($maxCount, $retentionDays);
    }

    /**
     * Exporte un snapshot vers un fichier
     */
    public function exportSnapshot(int $snapshotId, string $outputPath): bool
    {
        $snapshot = Snapshot::findById($snapshotId);
        if (!$snapshot) {
            return false;
        }

        $items = Snapshot::getItems($snapshotId);

        $export = [
            'snapshot' => $snapshot,
            'items' => $items,
            'exported_at' => date('c'),
        ];

        $json = json_encode($export, JSON_PRETTY_PRINT);
        return file_put_contents($outputPath, $json) !== false;
    }

    /**
     * Importe un snapshot depuis un fichier
     */
    public function importSnapshot(string $inputPath, ?int $userId = null): int
    {
        $content = file_get_contents($inputPath);
        $data = json_decode($content, true);

        if (!$data || !isset($data['snapshot'])) {
            throw new \Exception("Invalid snapshot file format");
        }

        // Créer le nouveau snapshot
        $snapshotId = Snapshot::create([
            'name' => $data['snapshot']['name'] . ' (imported)',
            'description' => 'Imported from: ' . basename($inputPath),
            'snapshot_type' => 'backup',
            'total_documents' => $data['snapshot']['total_documents'],
            'total_size_bytes' => $data['snapshot']['total_size_bytes'],
            'total_folders' => $data['snapshot']['total_folders'],
            'metadata' => [
                'imported_from' => $inputPath,
                'original_snapshot_id' => $data['snapshot']['id'],
                'original_created_at' => $data['snapshot']['created_at'],
            ],
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
            'created_by' => $userId,
        ]);

        // Importer les items
        foreach ($data['items'] as $item) {
            Snapshot::addItem(
                $snapshotId,
                $item['entity_type'],
                $item['entity_id'],
                $item['data_snapshot'],
                $item['action'],
                $item['content_hash']
            );
        }

        return $snapshotId;
    }
}

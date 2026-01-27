<?php
namespace KDocs\Services;
use KDocs\Core\Database;
use KDocs\Core\Config;

class FilesystemIndexer
{
    private string $basePath;
    private array $allowedExtensions;
    private array $ignoreFolders;
    private $db;
    private string $progressFile;
    private int $totalItems = 0;
    private int $processedItems = 0;

    public function __construct()
    {
        $config = Config::load();
        $storageConfig = $config['storage'] ?? [];
        $basePath = Config::get('storage.base_path', __DIR__ . '/../../storage/documents');
        $resolved = realpath($basePath);
        $this->basePath = rtrim($resolved ?: $basePath, '/\\');
        $extensions = $storageConfig['allowed_extensions'] ?? ['pdf', 'jpg', 'jpeg', 'png', 'tiff', 'tif', 'doc', 'docx'];
        $this->allowedExtensions = is_array($extensions) ? $extensions : array_map('trim', explode(',', $extensions));
        $ignoreFolders = $storageConfig['ignore_folders'] ?? ['.git', 'node_modules', 'vendor', '__MACOSX', 'Thumbs.db'];
        $this->ignoreFolders = is_array($ignoreFolders) ? $ignoreFolders : array_map('trim', explode(',', $ignoreFolders));
        $this->db = Database::getInstance();
        $this->progressFile = dirname(__DIR__, 2) . '/storage/.indexing_progress.json';
    }

    /**
     * Compte le nombre total d'elements a indexer (pour la barre de progression)
     */
    public function countItems(): int
    {
        if (!is_dir($this->basePath)) return 0;
        return $this->countDirectory($this->basePath);
    }

    private function countDirectory(string $path): int
    {
        $count = 1; // Le dossier lui-meme
        $items = @scandir($path);
        if ($items === false) return $count;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $this->ignoreFolders)) continue;
            $itemPath = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($itemPath)) {
                $count += $this->countDirectory($itemPath);
            } else {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if (in_array($ext, $this->allowedExtensions)) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Indexation complete avec suivi de progression
     */
    public function indexAll(bool $trackProgress = false): array
    {
        if (!is_dir($this->basePath)) {
            return ['error' => "Chemin inexistant: {$this->basePath}"];
        }

        $stats = ['folders' => 0, 'files' => 0, 'new' => 0, 'updated' => 0];

        if ($trackProgress) {
            $this->totalItems = $this->countItems();
            $this->processedItems = 0;
            $this->updateProgress('running', $stats, 'Demarrage...');
        }

        $this->indexDirectory('', $stats, null, $trackProgress);
        $this->setLastIndexTime();

        if ($trackProgress) {
            $this->updateProgress('completed', $stats, 'Termine');
        }

        return $stats;
    }

    /**
     * Met a jour le fichier de progression
     */
    private function updateProgress(string $status, array $stats, string $currentItem = ''): void
    {
        $progress = [
            'status' => $status,
            'started_at' => $this->getProgressData()['started_at'] ?? time(),
            'updated_at' => time(),
            'total' => $this->totalItems,
            'processed' => $this->processedItems,
            'percent' => $this->totalItems > 0 ? round(($this->processedItems / $this->totalItems) * 100, 1) : 0,
            'stats' => $stats,
            'current_item' => $currentItem
        ];

        @file_put_contents($this->progressFile, json_encode($progress, JSON_PRETTY_PRINT));
    }

    /**
     * Recupere les donnees de progression actuelles
     */
    public function getProgressData(): array
    {
        if (!file_exists($this->progressFile)) {
            return ['status' => 'idle'];
        }

        $data = @json_decode(file_get_contents($this->progressFile), true);
        if (!$data) {
            return ['status' => 'idle'];
        }

        // Si le processus a ete interrompu (pas de mise a jour depuis 30s)
        if (isset($data['status']) && $data['status'] === 'running') {
            if (time() - ($data['updated_at'] ?? 0) > 30) {
                $data['status'] = 'stale';
            }
        }

        return $data;
    }

    /**
     * Reinitialise la progression
     */
    public function resetProgress(): void
    {
        @unlink($this->progressFile);
    }

    /**
     * Initialise une nouvelle session de progression
     */
    public function initProgress(): void
    {
        $progress = [
            'status' => 'starting',
            'started_at' => time(),
            'updated_at' => time(),
            'total' => 0,
            'processed' => 0,
            'percent' => 0,
            'stats' => ['folders' => 0, 'files' => 0, 'new' => 0, 'updated' => 0],
            'current_item' => 'Initialisation...'
        ];
        @file_put_contents($this->progressFile, json_encode($progress, JSON_PRETTY_PRINT));
    }

    private function indexDirectory(string $relativePath, array &$stats, ?int $parentId = null, bool $trackProgress = false): void
    {
        $fullPath = $this->basePath . ($relativePath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) : '');
        if (!is_dir($fullPath)) return;

        try {
            $folderId = $this->upsertFolder($relativePath, $parentId);
            $stats['folders']++;
            $this->processedItems++;

            if ($trackProgress && $this->processedItems % 10 === 0) {
                $this->updateProgress('running', $stats, $relativePath ?: '[Racine]');
            }
        } catch (\Exception $e) { return; }

        $items = @scandir($fullPath);
        if ($items === false) return;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $this->ignoreFolders)) continue;
            $itemPath = $relativePath ? $relativePath . '/' . $item : $item;
            $itemFullPath = $fullPath . DIRECTORY_SEPARATOR . $item;

            if (is_dir($itemFullPath)) {
                $this->indexDirectory($itemPath, $stats, $folderId, $trackProgress);
            } else {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if (in_array($ext, $this->allowedExtensions)) {
                    try {
                        $isNew = $this->upsertDocument($itemPath, $folderId, $itemFullPath);
                        $stats['files']++;
                        $isNew ? $stats['new']++ : $stats['updated']++;
                        $this->processedItems++;

                        if ($trackProgress && $this->processedItems % 5 === 0) {
                            $this->updateProgress('running', $stats, $item);
                        }
                    } catch (\Exception $e) {}
                }
            }
        }
    }

    private function upsertFolder(string $relativePath, ?int $parentId): int
    {
        $name = $relativePath ? basename($relativePath) : '[Racine]';
        $depth = $relativePath ? substr_count($relativePath, '/') + 1 : 0;

        $stmt = $this->db->prepare("SELECT id FROM document_folders WHERE path = ?");
        $stmt->execute([$relativePath]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $this->db->prepare("UPDATE document_folders SET name = ?, parent_id = ?, depth = ?, last_scanned = NOW() WHERE id = ?");
            $stmt->execute([$name, $parentId, $depth, $existing['id']]);
            return (int)$existing['id'];
        }

        $stmt = $this->db->prepare("INSERT INTO document_folders (path, name, parent_id, depth, last_scanned) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$relativePath, $name, $parentId, $depth]);
        return (int)$this->db->lastInsertId();
    }

    private function upsertDocument(string $relativePath, int $folderId, string $fullPath): bool
    {
        $filename = basename($relativePath);
        $filesize = @filesize($fullPath);
        if ($filesize === false) throw new \Exception("Impossible de lire la taille");

        $checksum = @md5_file($fullPath);
        if ($checksum === false) throw new \Exception("Impossible de calculer le checksum");

        $mimeType = @mime_content_type($fullPath) ?: 'application/octet-stream';

        $stmt = $this->db->prepare("SELECT id, checksum FROM documents WHERE relative_path = ?");
        $stmt->execute([$relativePath]);
        $existing = $stmt->fetch();

        if ($existing) {
            if ($existing['checksum'] === $checksum) return false;
            $stmt = $this->db->prepare("UPDATE documents SET checksum = ?, file_size = ?, file_path = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$checksum, $filesize, $fullPath, $existing['id']]);
            return false;
        }

        $stmt = $this->db->prepare("INSERT INTO documents (filename, original_filename, file_path, relative_path, folder_id, file_size, mime_type, checksum, is_indexed, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, FALSE, NOW())");
        $stmt->execute([$filename, $filename, $fullPath, $relativePath, $folderId, $filesize, $mimeType, $checksum]);
        return true;
    }

    private function getLastIndexTime(): ?int
    {
        try {
            $stmt = $this->db->query("SELECT value FROM settings WHERE `key` = 'filesystem_last_index'");
            $result = $stmt->fetch();
            return $result ? (int)$result['value'] : null;
        } catch (\Exception $e) { return null; }
    }

    private function setLastIndexTime(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS settings (`key` VARCHAR(100) PRIMARY KEY, value TEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
            $stmt = $this->db->prepare("INSERT INTO settings (`key`, value) VALUES ('filesystem_last_index', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
            $stmt->execute([(string)time()]);
        } catch (\Exception $e) {}
    }
}

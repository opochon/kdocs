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
    
    public function __construct()
    {
        $config = Config::load();
        $storageConfig = $config['storage'] ?? [];
        // Utiliser Config::get pour récupérer base_path (inclut les settings DB)
        $basePath = Config::get('storage.base_path', __DIR__ . '/../../storage/documents');
        // Résoudre le chemin relatif en chemin absolu
        $resolved = realpath($basePath);
        $this->basePath = rtrim($resolved ?: $basePath, '/\\');
        $this->allowedExtensions = $storageConfig['allowed_extensions'] ?? ['pdf', 'jpg', 'jpeg', 'png', 'tiff', 'tif', 'doc', 'docx'];
        $this->ignoreFolders = $storageConfig['ignore_folders'] ?? ['.git', 'node_modules', 'vendor', '__MACOSX', 'Thumbs.db'];
        $this->db = Database::getInstance();
    }
    
    public function autoIndexIfNeeded(): void
    {
        $lastIndex = $this->getLastIndexTime();
        if ($lastIndex === null || (time() - $lastIndex) > 3600) {
            $this->indexAll();
        }
    }
    
    public function indexAll(): array
    {
        if (!is_dir($this->basePath)) {
            return ['error' => "Chemin inexistant: {$this->basePath}"];
        }
        $stats = ['folders' => 0, 'files' => 0, 'new' => 0, 'updated' => 0];
        $this->indexDirectory('', $stats);
        $this->setLastIndexTime();
        return $stats;
    }
    
    private function indexDirectory(string $relativePath, array &$stats, ?int $parentId = null): void
    {
        $fullPath = $this->basePath . ($relativePath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) : '');
        if (!is_dir($fullPath)) return;
        
        try {
            $folderId = $this->upsertFolder($relativePath, $parentId);
            $stats['folders']++;
        } catch (\Exception $e) { return; }
        
        $items = @scandir($fullPath);
        if ($items === false) return;
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $this->ignoreFolders)) continue;
            $itemPath = $relativePath ? $relativePath . '/' . $item : $item;
            $itemFullPath = $fullPath . DIRECTORY_SEPARATOR . $item;
            
            if (is_dir($itemFullPath)) {
                $this->indexDirectory($itemPath, $stats, $folderId);
            } else {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if (in_array($ext, $this->allowedExtensions)) {
                    try {
                        $isNew = $this->upsertDocument($itemPath, $folderId, $itemFullPath);
                        $stats['files']++;
                        $isNew ? $stats['new']++ : $stats['updated']++;
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
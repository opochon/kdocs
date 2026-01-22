<?php
/**
 * K-Docs - Stockage local (filesystem)
 */

namespace KDocs\Services\Storage;

use KDocs\Core\Config;

class LocalStorage implements StorageInterface
{
    private string $basePath;
    private array $allowedExtensions;
    private array $ignoreFolders;
    
    public function __construct()
    {
        $config = Config::load();
        $storageConfig = $config['storage'] ?? [];
        $basePath = Config::get('storage.base_path', __DIR__ . '/../../../storage/documents');
        $resolved = realpath($basePath);
        $this->basePath = rtrim($resolved ?: $basePath, '/\\');
        $this->allowedExtensions = $storageConfig['allowed_extensions'] ?? ['pdf', 'jpg', 'jpeg', 'png', 'tiff', 'tif', 'doc', 'docx'];
        $this->ignoreFolders = $storageConfig['ignore_folders'] ?? ['.git', 'node_modules', 'vendor', '__MACOSX', 'Thumbs.db'];
    }
    
    public function readDirectory(string $relativePath = '', bool $includeSubfolders = false): array
    {
        $fullPath = $this->basePath . ($relativePath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) : '');
        
        if (!is_dir($fullPath)) {
            return ['folders' => [], 'files' => [], 'error' => "Dossier inexistant: $relativePath"];
        }
        
        $folders = [];
        $files = [];
        
        $items = @scandir($fullPath);
        if ($items === false) {
            return ['folders' => [], 'files' => [], 'error' => "Impossible de lire le dossier"];
        }
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $this->ignoreFolders)) {
                continue;
            }
            
            $itemPath = $relativePath ? $relativePath . '/' . $item : $item;
            $itemFullPath = $fullPath . DIRECTORY_SEPARATOR . $item;
            
            if (is_dir($itemFullPath)) {
                $folderInfo = [
                    'name' => $item,
                    'path' => $itemPath,
                    'full_path' => $itemFullPath,
                    'modified' => @filemtime($itemFullPath) ?: null,
                ];
                
                if ($includeSubfolders) {
                    $subContent = $this->readDirectory($itemPath, true);
                    $folderInfo['file_count'] = count($subContent['files']);
                    $folderInfo['subfolders'] = $subContent['folders'];
                } else {
                    $folderInfo['file_count'] = $this->countFilesInDirectory($itemFullPath);
                }
                
                $folders[] = $folderInfo;
            } else {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if (in_array($ext, $this->allowedExtensions)) {
                    $files[] = [
                        'name' => $item,
                        'path' => $itemPath,
                        'full_path' => $itemFullPath,
                        'size' => @filesize($itemFullPath) ?: 0,
                        'modified' => @filemtime($itemFullPath) ?: null,
                        'extension' => $ext,
                        'mime_type' => @mime_content_type($itemFullPath) ?: 'application/octet-stream',
                    ];
                }
            }
        }
        
        usort($folders, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        usort($files, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        
        return ['folders' => $folders, 'files' => $files];
    }
    
    public function getFileInfo(string $relativePath): ?array
    {
        $fullPath = $this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        
        if (!file_exists($fullPath) || is_dir($fullPath)) {
            return null;
        }
        
        return [
            'name' => basename($relativePath),
            'path' => $relativePath,
            'full_path' => $fullPath,
            'size' => @filesize($fullPath) ?: 0,
            'modified' => @filemtime($fullPath) ?: null,
            'extension' => strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)),
            'mime_type' => @mime_content_type($fullPath) ?: 'application/octet-stream',
            'checksum' => @md5_file($fullPath) ?: null,
        ];
    }
    
    public function downloadFile(string $relativePath, string $localPath): bool
    {
        $fileInfo = $this->getFileInfo($relativePath);
        if (!$fileInfo) {
            return false;
        }
        
        // Pour le stockage local, c'est juste une copie
        $sourcePath = $fileInfo['full_path'];
        $destDir = dirname($localPath);
        
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
        }
        
        return @copy($sourcePath, $localPath);
    }
    
    public function checkFileModified(string $relativePath, ?string $knownChecksum = null, ?int $knownModified = null): bool
    {
        $fileInfo = $this->getFileInfo($relativePath);
        if (!$fileInfo) {
            return false;
        }
        
        if ($knownChecksum && $fileInfo['checksum'] !== $knownChecksum) {
            return true;
        }
        
        if ($knownModified && $fileInfo['modified'] && $fileInfo['modified'] > $knownModified) {
            return true;
        }
        
        return false;
    }
    
    public function getBasePath(): string
    {
        return $this->basePath;
    }
    
    private function countFilesInDirectory(string $dirPath): int
    {
        $count = 0;
        $items = @scandir($dirPath);
        if ($items === false) return 0;
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $this->ignoreFolders)) continue;
            $itemPath = $dirPath . DIRECTORY_SEPARATOR . $item;
            if (is_file($itemPath)) {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if (in_array($ext, $this->allowedExtensions)) {
                    $count++;
                }
            }
        }
        return $count;
    }
}

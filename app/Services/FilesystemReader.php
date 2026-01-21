<?php
/**
 * K-Docs - Service de lecture directe du filesystem
 * Lit directement le filesystem sans indexation préalable
 */

namespace KDocs\Services;

use KDocs\Core\Config;

class FilesystemReader
{
    private string $basePath;
    private array $allowedExtensions;
    private array $ignoreFolders;
    
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
    }
    
    /**
     * Lit le contenu d'un dossier directement depuis le filesystem
     * 
     * @param string $relativePath Chemin relatif depuis la racine (vide pour racine)
     * @param bool $includeSubfolders Inclure les sous-dossiers récursivement
     * @return array ['folders' => [], 'files' => []]
     */
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
                    // Compter rapidement les fichiers dans ce dossier
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
        
        // Trier : dossiers puis fichiers, par nom
        usort($folders, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        usort($files, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        
        return ['folders' => $folders, 'files' => $files];
    }
    
    /**
     * Compte rapidement les fichiers dans un dossier (non récursif)
     */
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
    
    /**
     * Vérifie si un fichier existe et retourne ses infos
     */
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
    
    /**
     * Vérifie les modifications d'un fichier (compare checksum et date)
     */
    public function checkFileModified(string $relativePath, ?string $knownChecksum = null, ?int $knownModified = null): bool
    {
        $fileInfo = $this->getFileInfo($relativePath);
        if (!$fileInfo) {
            return false; // Fichier n'existe plus
        }
        
        if ($knownChecksum && $fileInfo['checksum'] !== $knownChecksum) {
            return true; // Checksum différent = modifié
        }
        
        if ($knownModified && $fileInfo['modified'] && $fileInfo['modified'] > $knownModified) {
            return true; // Date de modification plus récente
        }
        
        return false;
    }
}

<?php
/**
 * K-Docs - Service de scan du filesystem
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use PDO;

class FilesystemScanner
{
    private string $basePath;
    private array $allowedExtensions;
    private array $ignoreFolders;
    private $db;
    
    public function __construct(array $config, $database)
    {
        $this->basePath = rtrim($config['storage']['base_path'], '/\\');
        $this->allowedExtensions = $config['storage']['allowed_extensions'] ?? ['pdf'];
        $this->ignoreFolders = $config['storage']['ignore_folders'] ?? [];
        $this->db = $database;
    }
    
    /**
     * Scanne l'arborescence complète et synchronise avec la DB
     */
    public function scanAll(): array
    {
        $stats = ['folders' => 0, 'files' => 0, 'new' => 0, 'updated' => 0, 'errors' => []];
        
        if (!is_dir($this->basePath)) {
            $stats['errors'][] = "Le chemin de base n'existe pas : {$this->basePath}";
            return $stats;
        }
        
        $this->scanDirectory('', $stats);
        return $stats;
    }
    
    /**
     * Scanne un dossier spécifique
     */
    public function scanDirectory(string $relativePath, array &$stats, ?int $parentId = null): void
    {
        $fullPath = $this->basePath . ($relativePath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) : '');
        
        if (!is_dir($fullPath)) {
            return;
        }
        
        // Créer ou mettre à jour le dossier en DB
        try {
            $folderId = $this->upsertFolder($relativePath, $parentId);
            $stats['folders']++;
        } catch (\Exception $e) {
            $stats['errors'][] = "Erreur dossier {$relativePath}: " . $e->getMessage();
            return;
        }
        
        $items = @scandir($fullPath);
        if ($items === false) {
            $stats['errors'][] = "Impossible de lire le dossier : {$fullPath}";
            return;
        }
        
        $fileCount = 0;
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            if (in_array($item, $this->ignoreFolders)) continue;
            
            $itemPath = $relativePath ? $relativePath . '/' . $item : $item;
            $itemFullPath = $fullPath . DIRECTORY_SEPARATOR . $item;
            
            if (is_dir($itemFullPath)) {
                // Récursion pour sous-dossiers
                $this->scanDirectory($itemPath, $stats, $folderId);
            } else {
                // Fichier
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if (in_array($ext, $this->allowedExtensions)) {
                    try {
                        $isNew = $this->upsertDocument($itemPath, $folderId, $itemFullPath);
                        $stats['files']++;
                        if ($isNew) {
                            $stats['new']++;
                        } else {
                            $stats['updated']++;
                        }
                        $fileCount++;
                    } catch (\Exception $e) {
                        $stats['errors'][] = "Erreur fichier {$itemPath}: " . $e->getMessage();
                    }
                }
            }
        }
        
        // Mettre à jour le compteur de fichiers du dossier
        $this->updateFolderFileCount($folderId, $fileCount);
    }
    
    /**
     * Crée ou met à jour un dossier en DB
     */
    private function upsertFolder(string $relativePath, ?int $parentId): int
    {
        $name = $relativePath ? basename($relativePath) : '[Racine]';
        $depth = $relativePath ? substr_count($relativePath, '/') + 1 : 0;
        
        // Vérifier si existe déjà
        $stmt = $this->db->prepare("SELECT id FROM document_folders WHERE path = ?");
        $stmt->execute([$relativePath]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Mise à jour
            $stmt = $this->db->prepare("
                UPDATE document_folders SET 
                    name = ?, parent_id = ?, depth = ?, last_scanned = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$name, $parentId, $depth, $existing['id']]);
            return (int)$existing['id'];
        }
        
        // Création
        $stmt = $this->db->prepare("
            INSERT INTO document_folders (path, name, parent_id, depth, last_scanned)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$relativePath, $name, $parentId, $depth]);
        
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Crée ou met à jour un document en DB
     */
    private function upsertDocument(string $relativePath, int $folderId, string $fullPath): bool
    {
        $filename = basename($relativePath);
        $filesize = @filesize($fullPath);
        if ($filesize === false) {
            throw new \Exception("Impossible de lire la taille du fichier");
        }
        
        $checksum = @md5_file($fullPath);
        if ($checksum === false) {
            throw new \Exception("Impossible de calculer le checksum");
        }
        
        $mimeType = @mime_content_type($fullPath) ?: 'application/octet-stream';
        
        // Vérifie si le document existe déjà
        $stmt = $this->db->prepare("SELECT id, checksum FROM documents WHERE relative_path = ?");
        $stmt->execute([$relativePath]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            if ($existing['checksum'] === $checksum) {
                return false; // Pas de changement
            }
            // Mise à jour
            $stmt = $this->db->prepare("
                UPDATE documents SET 
                    checksum = ?, file_size = ?, file_path = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$checksum, $filesize, $fullPath, $existing['id']]);
            return false;
        }
        
        // Nouveau document
        $stmt = $this->db->prepare("
            INSERT INTO documents 
            (filename, original_filename, file_path, relative_path, folder_id, 
             file_size, mime_type, checksum, is_indexed, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, FALSE, NOW())
        ");
        $stmt->execute([
            $filename, $filename, $fullPath, $relativePath, $folderId,
            $filesize, $mimeType, $checksum
        ]);
        
        return true;
    }
    
    private function updateFolderFileCount(int $folderId, int $count): void
    {
        $stmt = $this->db->prepare("UPDATE document_folders SET file_count = file_count + ? WHERE id = ?");
        $stmt->execute([$count, $folderId]);
    }
}

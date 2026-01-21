<?php
/**
 * K-Docs - Service de mapping documents filesystem <-> DB
 * Le scan sert à savoir quel document est où, pas à créer une image
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Core\Config;

class DocumentMapper
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
    
    /**
     * Scan pour mapper les fichiers du filesystem avec la DB
     * Détecte les nouveaux fichiers, les modifications, et les fichiers supprimés
     * 
     * @return array Statistiques du scan
     */
    public function scanForMapping(): array
    {
        if (!is_dir($this->basePath)) {
            return ['error' => "Chemin inexistant: {$this->basePath}"];
        }
        
        $stats = [
            'new' => 0,
            'modified' => 0,
            'unchanged' => 0,
            'deleted_from_fs' => 0,
            'folders_scanned' => 0
        ];
        
        $this->scanDirectory('', $stats);
        
        // Marquer les fichiers qui n'existent plus dans le filesystem
        $this->markMissingFiles();
        
        return $stats;
    }
    
    private function scanDirectory(string $relativePath, array &$stats, ?int $parentId = null): void
    {
        $fullPath = $this->basePath . ($relativePath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) : '');
        if (!is_dir($fullPath)) return;
        
        $stats['folders_scanned']++;
        
        // Créer/mettre à jour le dossier en DB
        $folderId = $this->upsertFolder($relativePath, $parentId);
        
        $items = @scandir($fullPath);
        if ($items === false) return;
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $this->ignoreFolders)) continue;
            $itemPath = $relativePath ? $relativePath . '/' . $item : $item;
            $itemFullPath = $fullPath . DIRECTORY_SEPARATOR . $item;
            
            if (is_dir($itemFullPath)) {
                $this->scanDirectory($itemPath, $stats, $folderId);
            } else {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if (in_array($ext, $this->allowedExtensions)) {
                    $result = $this->mapDocument($itemPath, $folderId, $itemFullPath);
                    $stats[$result]++;
                }
            }
        }
    }
    
    private function mapDocument(string $relativePath, int $folderId, string $fullPath): string
    {
        $filename = basename($relativePath);
        $filesize = @filesize($fullPath);
        if ($filesize === false) return 'unchanged';
        
        $checksum = @md5_file($fullPath);
        if ($checksum === false) return 'unchanged';
        
        $fileModified = @filemtime($fullPath) ?: null;
        $mimeType = @mime_content_type($fullPath) ?: 'application/octet-stream';
        
        // Chercher le document en DB
        $stmt = $this->db->prepare("SELECT id, checksum, file_modified_at FROM documents WHERE relative_path = ? AND deleted_at IS NULL");
        $stmt->execute([$relativePath]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Document existe, vérifier s'il a été modifié
            if ($existing['checksum'] !== $checksum || ($fileModified && $existing['file_modified_at'] != $fileModified)) {
                // Modifié : mettre à jour
                $updateStmt = $this->db->prepare("
                    UPDATE documents SET 
                        checksum = ?, 
                        file_size = ?, 
                        file_path = ?,
                        file_modified_at = ?,
                        updated_at = NOW(),
                        is_indexed = FALSE
                    WHERE id = ?
                ");
                $updateStmt->execute([$checksum, $filesize, $fullPath, $fileModified, $existing['id']]);
                return 'modified';
            }
            return 'unchanged';
        }
        
        // Nouveau document : créer l'entrée en DB
        $insertStmt = $this->db->prepare("
            INSERT INTO documents 
            (filename, original_filename, file_path, relative_path, folder_id, 
             file_size, mime_type, checksum, file_modified_at, is_indexed, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, FALSE, NOW())
        ");
        $insertStmt->execute([
            $filename, $filename, $fullPath, $relativePath, $folderId,
            $filesize, $mimeType, $checksum, $fileModified
        ]);
        return 'new';
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
    
    /**
     * Marque les fichiers qui n'existent plus dans le filesystem
     */
    private function markMissingFiles(): void
    {
        // Récupérer tous les documents non supprimés qui ont un relative_path
        $stmt = $this->db->query("
            SELECT id, relative_path, file_path 
            FROM documents 
            WHERE relative_path IS NOT NULL 
            AND relative_path != '' 
            AND deleted_at IS NULL
        ");
        $documents = $stmt->fetchAll();
        
        foreach ($documents as $doc) {
            $fullPath = $this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $doc['relative_path']);
            if (!file_exists($fullPath)) {
                // Fichier n'existe plus dans le filesystem
                // On ne le supprime pas, mais on le marque comme manquant
                $updateStmt = $this->db->prepare("UPDATE documents SET file_path = NULL WHERE id = ?");
                $updateStmt->execute([$doc['id']]);
            }
        }
    }
}

<?php
/**
 * K-Docs - Service d'indexation intelligent (SIMPLIFIÉ)
 * 
 * Utilise mtime + size pour éviter de recalculer les checksums inutilement
 * Délègue les queues à QueueService (n0nag0n/simple-job-queue)
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Core\Config;
use KDocs\Services\Storage\StorageFactory;

class IndexingService
{
    private string $basePath;
    private $db;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        $storage = StorageFactory::create();
        $this->basePath = $storage->getBasePath();
    }
    
    /**
     * Indexe un dossier - VERSION SIMPLE
     * Utilise mtime + size pour éviter de recalculer les checksums
     * 
     * @param string $relativePath Chemin relatif du dossier
     * @return array Statistiques ['new' => int, 'skipped' => int, 'updated' => int, 'error' => string?]
     */
    public function indexFolder(string $relativePath): array
    {
        $fullPath = $this->basePath . ($relativePath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) : '');
        
        if (!is_dir($fullPath)) {
            return ['error' => 'Dossier inexistant: ' . $fullPath];
        }
        
        // Lire l'index existant (fichier .index)
        $indexPath = $fullPath . DIRECTORY_SEPARATOR . '.index';
        $existingIndex = file_exists($indexPath) 
            ? json_decode(file_get_contents($indexPath), true) 
            : ['files' => [], 'version' => 2];
        
        // S'assurer que 'files' existe
        if (!isset($existingIndex['files'])) {
            $existingIndex['files'] = [];
        }
        
        $stats = ['new' => 0, 'skipped' => 0, 'updated' => 0, 'errors' => 0];
        $newIndex = [
            'version' => 2,
            'files' => [],
            'last_scan' => time(),
            'file_count' => 0,
            'db_count' => 0
        ];
        
        // Scanner les fichiers (ignorer les dossiers et fichiers cachés)
        $files = glob($fullPath . DIRECTORY_SEPARATOR . '*');
        
        foreach ($files as $file) {
            if (is_dir($file)) continue;
            
            $filename = basename($file);
            
            // Ignorer les fichiers système
            if ($filename[0] === '.' || $filename === 'Thumbs.db') continue;
            
            $mtime = @filemtime($file);
            $size = @filesize($file);
            
            if ($mtime === false || $size === false) {
                $stats['errors']++;
                continue;
            }
            
            // Vérifier si le fichier a changé (comparaison rapide)
            $existing = $existingIndex['files'][$filename] ?? null;
            
            if ($existing && isset($existing['mtime']) && isset($existing['size']) && 
                $existing['mtime'] === $mtime && $existing['size'] === $size) {
                // Fichier inchangé : garder l'entrée existante
                $newIndex['files'][$filename] = $existing;
                $stats['skipped']++;
                continue;
            }
            
            // Fichier nouveau ou modifié : calculer le checksum
            $checksum = @md5_file($file);
            
            if ($checksum === false) {
                $stats['errors']++;
                continue;
            }
            
            // Vérifier/créer en DB
            $docId = $this->findOrCreateDocument($file, $relativePath, $filename, $checksum, $mtime, $size);
            
            if ($docId) {
                $newIndex['files'][$filename] = [
                    'mtime' => $mtime,
                    'size' => $size,
                    'checksum' => $checksum,
                    'db_id' => $docId,
                    'indexed_at' => time()
                ];
                
                if ($existing) {
                    $stats['updated']++;
                } else {
                    $stats['new']++;
                    
                    // Ajouter à la queue pour traitement ultérieur (OCR, thumbnail)
                    if (class_exists('\KDocs\Services\QueueService')) {
                        QueueService::queueThumbnail($docId);
                        QueueService::queueOCR($docId);
                    }
                }
            } else {
                $stats['errors']++;
            }
        }
        
        // Compter en DB pour sync
        $newIndex['file_count'] = count($newIndex['files']);
        $newIndex['db_count'] = $this->countInDb($relativePath);
        
        // Sauvegarder l'index
        $dir = dirname($indexPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        
        file_put_contents($indexPath, json_encode($newIndex, JSON_PRETTY_PRINT));
        
        return $stats;
    }
    
    /**
     * Trouve ou crée un document en DB
     */
    private function findOrCreateDocument(string $filePath, string $relativePath, string $filename, string $checksum, int $mtime, int $size): ?int
    {
        try {
            // Chercher par checksum d'abord
            $stmt = $this->db->prepare("
                SELECT id FROM documents 
                WHERE checksum = ? AND deleted_at IS NULL 
                LIMIT 1
            ");
            $stmt->execute([$checksum]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($existing) {
                // Mettre à jour le chemin si nécessaire
                $docRelativePath = $relativePath ? $relativePath . '/' . $filename : $filename;
                $updateStmt = $this->db->prepare("
                    UPDATE documents SET 
                        file_path = ?,
                        relative_path = ?,
                        file_modified_at = FROM_UNIXTIME(?),
                        updated_at = NOW()
                    WHERE id = ? AND (file_path != ? OR relative_path != ?)
                ");
                $updateStmt->execute([$filePath, $docRelativePath, $mtime, $existing['id'], $filePath, $docRelativePath]);
                
                return (int)$existing['id'];
            }
            
            // Créer nouveau document
            $docRelativePath = $relativePath ? $relativePath . '/' . $filename : $filename;
            $mimeType = @mime_content_type($filePath) ?: 'application/octet-stream';
            
            $stmt = $this->db->prepare("
                INSERT INTO documents 
                (filename, original_filename, file_path, relative_path, 
                 file_size, mime_type, checksum, file_modified_at, 
                 is_indexed, ocr_status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), FALSE, 'pending', NOW())
            ");
            $stmt->execute([
                $filename, $filename, $filePath, $docRelativePath,
                $size, $mimeType, $checksum, $mtime
            ]);
            
            return (int)$this->db->lastInsertId();
            
        } catch (\Exception $e) {
            error_log("IndexingService::findOrCreateDocument - Erreur: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Compte les documents en DB pour un chemin
     */
    private function countInDb(string $relativePath): int
    {
        try {
            $normalizedPath = str_replace('\\', '/', $relativePath);
            $searchPattern = $normalizedPath ? $normalizedPath . '/%' : '%';
            $exactPath = $normalizedPath ?: '';
            
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM documents 
                WHERE (
                    (relative_path LIKE ? AND relative_path NOT LIKE ?)
                    OR relative_path = ?
                )
                AND deleted_at IS NULL
                AND (status IS NULL OR status != 'pending')
            ");
            
            $excludeSubfolders = $normalizedPath ? $normalizedPath . '/%/%' : '%/%/%';
            $stmt->execute([$searchPattern, $excludeSubfolders, $exactPath]);
            
            return (int)$stmt->fetchColumn();
        } catch (\Exception $e) {
            error_log("IndexingService::countInDb - Erreur: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Lit le fichier .index d'un dossier (pour compatibilité)
     */
    public function readIndex(string $relativePath): ?array
    {
        $fullPath = $this->basePath . ($relativePath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) : '');
        $indexPath = $fullPath . DIRECTORY_SEPARATOR . '.index';
        
        if (!file_exists($indexPath)) {
            return null;
        }
        
        $content = @file_get_contents($indexPath);
        if ($content === false) {
            return null;
        }
        
        $data = json_decode($content, true);
        
        // Compatibilité version 1
        if ($data && !isset($data['version'])) {
            $data['version'] = 1;
        }
        
        return $data ?: null;
    }
    
    /**
     * Vérifie si un dossier est en cours d'indexation
     */
    public function isIndexing(string $relativePath): bool
    {
        $fullPath = $this->basePath . ($relativePath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) : '');
        $indexingPath = $fullPath . DIRECTORY_SEPARATOR . '.indexing';
        return file_exists($indexingPath);
    }
    
    /**
     * Écrit le fichier .indexing (progression)
     */
    public function writeIndexingProgress(string $relativePath, int $total, int $current, string $status = 'scanning'): bool
    {
        $fullPath = $this->basePath . ($relativePath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) : '');
        $indexingPath = $fullPath . DIRECTORY_SEPARATOR . '.indexing';
        $dir = dirname($indexingPath);
        
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        
        $data = [
            'path' => $relativePath,
            'total' => $total,
            'current' => $current,
            'status' => $status,
            'started_at' => $_SERVER['REQUEST_TIME'] ?? time(),
            'updated_at' => time(),
        ];
        
        return @file_put_contents($indexingPath, json_encode($data)) !== false;
    }
    
    /**
     * Supprime le fichier .indexing
     */
    public function removeIndexing(string $relativePath): bool
    {
        $fullPath = $this->basePath . ($relativePath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) : '');
        $indexingPath = $fullPath . DIRECTORY_SEPARATOR . '.indexing';
        return @unlink($indexingPath);
    }
}

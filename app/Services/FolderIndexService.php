<?php
/**
 * K-Docs - Service de gestion des fichiers .index pour les dossiers
 * Gère les fichiers .index (métadonnées) et .indexing (progression)
 */

namespace KDocs\Services;

use KDocs\Services\Storage\StorageFactory;

class FolderIndexService
{
    private $storage;
    private $basePath;
    
    public function __construct()
    {
        $this->storage = StorageFactory::create();
        $this->basePath = $this->storage->getBasePath();
    }
    
    /**
     * Lit le fichier .index d'un dossier
     * Compatible avec version 1 (ancienne) et version 2 (nouvelle avec fichiers détaillés)
     * 
     * @param string $relativePath Chemin relatif du dossier
     * @return array|null ['file_count' => int, 'indexed_at' => timestamp, 'db_count' => int, 'files' => [...], 'version' => 1|2] ou null si inexistant
     */
    public function readIndex(string $relativePath): ?array
    {
        $fullPath = $this->getIndexFilePath($relativePath);
        
        if (!file_exists($fullPath)) {
            return null;
        }
        
        $content = @file_get_contents($fullPath);
        if ($content === false) {
            return null;
        }
        
        $data = json_decode($content, true);
        
        // Compatibilité : si version 1 (ancienne), ajouter version
        if ($data && !isset($data['version'])) {
            $data['version'] = 1;
        }
        
        return $data ?: null;
    }
    
    /**
     * Écrit le fichier .index d'un dossier
     * 
     * @param string $relativePath Chemin relatif du dossier
     * @param int $fileCount Nombre de fichiers physiques
     * @param int $dbCount Nombre de documents en DB
     * @return bool Succès
     */
    public function writeIndex(string $relativePath, int $fileCount, int $dbCount): bool
    {
        $fullPath = $this->getIndexFilePath($relativePath);
        $dir = dirname($fullPath);
        
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        
        $data = [
            'file_count' => $fileCount,
            'db_count' => $dbCount,
            'indexed_at' => time(),
            'indexed_date' => date('Y-m-d H:i:s')
        ];
        
        return @file_put_contents($fullPath, json_encode($data)) !== false;
    }
    
    /**
     * Lit le fichier .indexing d'un dossier (progression en cours)
     * 
     * @param string $relativePath Chemin relatif du dossier
     * @return array|null ['total' => int, 'current' => int, 'processed' => int, ...] ou null
     */
    public function readIndexing(string $relativePath): ?array
    {
        $fullPath = $this->getIndexingFilePath($relativePath);
        
        if (!file_exists($fullPath)) {
            return null;
        }
        
        $content = @file_get_contents($fullPath);
        if ($content === false) {
            return null;
        }
        
        $data = json_decode($content, true);
        return $data ?: null;
    }
    
    /**
     * Vérifie si un dossier est en cours d'indexation
     * 
     * @param string $relativePath Chemin relatif du dossier
     * @return bool
     */
    public function isIndexing(string $relativePath): bool
    {
        $fullPath = $this->getIndexingFilePath($relativePath);
        return file_exists($fullPath);
    }
    
    /**
     * Supprime le fichier .indexing (indexation terminée)
     * 
     * @param string $relativePath Chemin relatif du dossier
     * @return bool Succès
     */
    public function removeIndexing(string $relativePath): bool
    {
        $fullPath = $this->getIndexingFilePath($relativePath);
        return @unlink($fullPath);
    }
    
    /**
     * Met à jour le fichier .indexing avec la progression
     * 
     * @param string $relativePath Chemin relatif du dossier
     * @param int $total Nombre total de fichiers
     * @param int $current Index du fichier actuel
     * @param int $processed Nombre de fichiers traités
     * @return bool Succès
     */
    public function writeIndexingProgress(string $relativePath, int $total, int $current, int $processed): bool
    {
        $fullPath = $this->getIndexingFilePath($relativePath);
        $dir = dirname($fullPath);
        
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        
        $data = [
            'path' => $relativePath,
            'total' => $total,
            'current' => $current,
            'processed' => $processed,
            'started_at' => time(),
            'updated_at' => time(),
        ];
        
        return @file_put_contents($fullPath, json_encode($data)) !== false;
    }
    
    /**
     * Retourne le chemin complet du fichier .index
     */
    private function getIndexFilePath(string $relativePath): string
    {
        $folderPath = $this->basePath . ($relativePath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) : '');
        return $folderPath . DIRECTORY_SEPARATOR . '.index';
    }
    
    /**
     * Retourne le chemin complet du fichier .indexing
     */
    private function getIndexingFilePath(string $relativePath): string
    {
        $folderPath = $this->basePath . ($relativePath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) : '');
        return $folderPath . DIRECTORY_SEPARATOR . '.indexing';
    }
}

<?php
/**
 * K-Docs - Service de lecture directe du filesystem
 * Lit directement le filesystem sans indexation préalable
 * Supporte filesystem local et KDrive via WebDAV
 */

namespace KDocs\Services;

use KDocs\Services\Storage\StorageFactory;
use KDocs\Services\Storage\StorageInterface;

class FilesystemReader
{
    private StorageInterface $storage;
    
    public function __construct()
    {
        // Utiliser la factory pour créer l'instance de stockage appropriée
        $this->storage = StorageFactory::create();
    }
    
    /**
     * Lit le contenu d'un dossier directement depuis le stockage
     * 
     * @param string $relativePath Chemin relatif depuis la racine (vide pour racine)
     * @param bool $includeSubfolders Inclure les sous-dossiers récursivement
     * @return array ['folders' => [], 'files' => []]
     */
    public function readDirectory(string $relativePath = '', bool $includeSubfolders = false): array
    {
        return $this->storage->readDirectory($relativePath, $includeSubfolders);
    }
    
    /**
     * Vérifie si un fichier existe et retourne ses infos
     */
    public function getFileInfo(string $relativePath): ?array
    {
        return $this->storage->getFileInfo($relativePath);
    }
    
    /**
     * Télécharge un fichier depuis le stockage distant vers un chemin local
     * 
     * @param string $relativePath Chemin relatif du fichier dans le stockage
     * @param string $localPath Chemin local où sauvegarder le fichier
     * @return bool Succès de l'opération
     */
    public function downloadFile(string $relativePath, string $localPath): bool
    {
        return $this->storage->downloadFile($relativePath, $localPath);
    }
    
    /**
     * Vérifie les modifications d'un fichier (compare checksum et date)
     */
    public function checkFileModified(string $relativePath, ?string $knownChecksum = null, ?int $knownModified = null): bool
    {
        return $this->storage->checkFileModified($relativePath, $knownChecksum, $knownModified);
    }
    
    /**
     * Retourne le chemin de base du stockage
     */
    public function getBasePath(): string
    {
        return $this->storage->getBasePath();
    }
}

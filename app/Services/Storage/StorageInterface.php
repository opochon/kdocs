<?php
/**
 * K-Docs - Interface pour les systèmes de stockage
 * Permet d'abstraire filesystem local, KDrive, etc.
 */

namespace KDocs\Services\Storage;

interface StorageInterface
{
    /**
     * Lit le contenu d'un dossier
     * 
     * @param string $relativePath Chemin relatif depuis la racine (vide pour racine)
     * @param bool $includeSubfolders Inclure les sous-dossiers récursivement
     * @return array ['folders' => [], 'files' => []]
     */
    public function readDirectory(string $relativePath = '', bool $includeSubfolders = false): array;
    
    /**
     * Vérifie si un fichier existe et retourne ses infos
     * 
     * @param string $relativePath Chemin relatif du fichier
     * @return array|null Infos du fichier ou null si inexistant
     */
    public function getFileInfo(string $relativePath): ?array;
    
    /**
     * Télécharge un fichier depuis le stockage distant vers un chemin local
     * 
     * @param string $relativePath Chemin relatif du fichier dans le stockage
     * @param string $localPath Chemin local où sauvegarder le fichier
     * @return bool Succès de l'opération
     */
    public function downloadFile(string $relativePath, string $localPath): bool;
    
    /**
     * Vérifie les modifications d'un fichier
     * 
     * @param string $relativePath Chemin relatif du fichier
     * @param string|null $knownChecksum Checksum connu
     * @param int|null $knownModified Timestamp de modification connu
     * @return bool True si le fichier a été modifié
     */
    public function checkFileModified(string $relativePath, ?string $knownChecksum = null, ?int $knownModified = null): bool;
    
    /**
     * Retourne le chemin de base du stockage
     * 
     * @return string Chemin de base
     */
    public function getBasePath(): string;
}

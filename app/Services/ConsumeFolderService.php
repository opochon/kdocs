<?php
/**
 * K-Docs - Service de Consume Folder
 * Surveille un dossier et importe automatiquement les nouveaux fichiers
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Core\Config;

class ConsumeFolderService
{
    private string $consumePath;
    private DocumentProcessor $processor;
    private $db;
    
    public function __construct()
    {
        $config = Config::load();
        // Chemin du dossier consume (par défaut storage/consume)
        $this->consumePath = Config::get('storage.consume', __DIR__ . '/../../storage/consume');
        
        // Résoudre le chemin relatif en chemin absolu
        $resolved = realpath($this->consumePath);
        if (!$resolved) {
            // Créer le dossier s'il n'existe pas
            @mkdir($this->consumePath, 0755, true);
            $resolved = realpath($this->consumePath);
        }
        $this->consumePath = rtrim($resolved ?: $this->consumePath, '/\\');
        
        $this->processor = new DocumentProcessor();
        $this->db = Database::getInstance();
    }
    
    /**
     * Scanner le dossier consume et traiter les nouveaux fichiers
     * Supporte filesystem local et KDrive
     * 
     * @return array Résultats du traitement ['processed' => int, 'errors' => array]
     */
    public function scan(): array
    {
        $results = ['processed' => 0, 'errors' => []];
        
        $config = Config::load();
        $storageType = Config::get('storage.type', 'local');
        $allowedExtensions = $config['storage']['allowed_extensions'] ?? ['pdf', 'png', 'jpg', 'jpeg', 'tiff', 'tif'];
        
        if ($storageType === 'kdrive') {
            // Scanner KDrive
            return $this->scanKDrive($allowedExtensions);
        }
        
        // Scanner filesystem local
        if (!is_dir($this->consumePath)) {
            @mkdir($this->consumePath, 0755, true);
            return $results;
        }
        
        // Scanner tous les fichiers du dossier consume
        $files = glob($this->consumePath . '/*.*');
        
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExtensions)) {
                continue;
            }
            
            try {
                $this->processFile($file);
                $results['processed']++;
            } catch (\Exception $e) {
                $results['errors'][] = basename($file) . ': ' . $e->getMessage();
            }
        }
        
        return $results;
    }
    
    /**
     * Scanner KDrive pour les nouveaux fichiers
     */
    private function scanKDrive(array $allowedExtensions): array
    {
        $results = ['processed' => 0, 'errors' => []];
        
        try {
            $filesystemReader = new \KDocs\Services\FilesystemReader();
            $config = Config::load();
            
            // Chemin du dossier consume dans KDrive
            $consumePath = Config::get('storage.consume', 'consume');
            
            // Lister les fichiers dans le dossier consume KDrive
            $content = $filesystemReader->readDirectory($consumePath, false);
            
            if (isset($content['error'])) {
                $results['errors'][] = "Erreur lecture KDrive: " . $content['error'];
                return $results;
            }
            
            foreach ($content['files'] as $file) {
                $ext = strtolower($file['extension'] ?? '');
                if (!in_array($ext, $allowedExtensions)) {
                    continue;
                }
                
                try {
                    // Télécharger temporairement depuis KDrive
                    $tempDir = $config['storage']['temp'] ?? __DIR__ . '/../../storage/temp';
                    if (!is_dir($tempDir)) {
                        @mkdir($tempDir, 0755, true);
                    }
                    
                    $tempPath = $tempDir . DIRECTORY_SEPARATOR . uniqid() . '_' . $file['name'];
                    
                    if ($filesystemReader->downloadFile($file['path'], $tempPath)) {
                        $this->processFile($tempPath);
                        $results['processed']++;
                        // Supprimer le fichier temporaire après traitement
                        @unlink($tempPath);
                    } else {
                        $results['errors'][] = "Impossible de télécharger: " . $file['name'];
                    }
                } catch (\Exception $e) {
                    $results['errors'][] = $file['name'] . ': ' . $e->getMessage();
                }
            }
        } catch (\Exception $e) {
            $results['errors'][] = "Erreur scan KDrive: " . $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Traiter un fichier du dossier consume
     * 
     * @param string $filePath Chemin complet du fichier à traiter
     * @throws \Exception En cas d'erreur lors du traitement
     */
    private function processFile(string $filePath): void
    {
        $filename = basename($filePath);
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        // Générer hash pour éviter doublons
        $hash = md5_file($filePath);
        
        // Vérifier si déjà importé (par checksum)
        $stmt = $this->db->prepare("SELECT id FROM documents WHERE checksum = ?");
        $stmt->execute([$hash]);
        if ($stmt->fetch()) {
            // Doublon, supprimer le fichier du consume folder
            @unlink($filePath);
            return;
        }
        
        // Copier vers storage/documents
        $config = Config::load();
        $basePath = Config::get('storage.base_path', __DIR__ . '/../../storage/documents');
        $resolved = realpath($basePath);
        $destDir = rtrim($resolved ?: $basePath, '/\\');
        
        // Créer le dossier s'il n'existe pas
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
        }
        
        // Générer un nom de fichier unique
        $newFilename = uniqid() . '_' . $filename;
        $destPath = $destDir . DIRECTORY_SEPARATOR . $newFilename;
        
        if (!copy($filePath, $destPath)) {
            throw new \Exception("Impossible de copier le fichier vers " . $destPath);
        }
        
        // Déterminer le MIME type
        $mimeTypes = [
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'tiff' => 'image/tiff',
            'tif' => 'image/tiff'
        ];
        $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';
        
        // Créer le document en DB
        $title = pathinfo($filename, PATHINFO_FILENAME);
        $stmt = $this->db->prepare("
            INSERT INTO documents (
                title, 
                original_filename, 
                filename,
                file_path, 
                mime_type, 
                checksum, 
                file_size,
                created_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $fileSize = filesize($filePath);
        $stmt->execute([
            $title,
            $filename,
            $newFilename,
            $destPath,
            $mimeType,
            $hash,
            $fileSize
        ]);
        
        $documentId = $this->db->lastInsertId();
        
        // Lancer le traitement complet (OCR → Matching → Thumbnail → Workflows)
        try {
            $this->processor->process($documentId);
        } catch (\Exception $e) {
            // Logger l'erreur mais ne pas bloquer l'import
            error_log("Erreur traitement document {$documentId}: " . $e->getMessage());
        }
        
        // Supprimer le fichier original du consume folder
        @unlink($filePath);
    }
    
    /**
     * Retourne le chemin du dossier consume
     * 
     * @return string
     */
    public function getConsumePath(): string
    {
        return $this->consumePath;
    }
}

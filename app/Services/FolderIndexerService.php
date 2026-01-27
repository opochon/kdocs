<?php
/**
 * K-Docs - Service d'indexation des fichiers d'un dossier
 * Indexe les fichiers physiques non présents en DB
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Models\Document;

class FolderIndexerService
{
    private string $basePath;
    private array $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'tiff', 'tif', 'doc', 'docx'];
    private FolderIndexService $indexService;
    
    public function __construct()
    {
        $fsReader = new FilesystemReader();
        $this->basePath = $fsReader->getBasePath();
        $this->indexService = new FolderIndexService();
    }
    
    /**
     * Indexe tous les fichiers non indexés d'un dossier
     * @param string $relativePath Chemin relatif du dossier
     * @param bool $async Si true, ne bloque pas (pour appel depuis queue)
     * @return array Statistiques d'indexation
     */
    public function indexFolder(string $relativePath, bool $async = false): array
    {
        $relativePath = trim($relativePath, '/');
        $fullPath = $this->basePath . ($relativePath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) : '');
        
        if (!is_dir($fullPath)) {
            return ['success' => false, 'error' => 'Dossier inexistant: ' . $fullPath];
        }
        
        // Vérifier si déjà en cours d'indexation
        if ($this->indexService->isIndexing($relativePath)) {
            return ['success' => false, 'error' => 'Indexation déjà en cours', 'status' => 'already_indexing'];
        }
        
        $db = Database::getInstance();
        
        // Lister les fichiers physiques
        $physicalFiles = $this->scanPhysicalFiles($fullPath);
        $total = count($physicalFiles);
        
        if ($total === 0) {
            // Mettre à jour le .index (dossier vide)
            $this->indexService->writeIndex($relativePath, 0, 0);
            return ['success' => true, 'indexed' => 0, 'skipped' => 0, 'total' => 0];
        }
        
        // Récupérer les fichiers déjà en DB (par checksum ou nom de fichier)
        $existingFiles = $this->getExistingFiles($db, $relativePath, $physicalFiles);
        
        // Créer le fichier .indexing
        $this->indexService->writeIndexingProgress($relativePath, $total, 0, 0);
        
        $indexed = 0;
        $skipped = 0;
        $errors = 0;
        
        try {
            foreach ($physicalFiles as $index => $file) {
                $filename = $file['name'];
                $filePath = $file['path'];
                
                // Mettre à jour la progression
                $this->indexService->writeIndexingProgress($relativePath, $total, $index + 1, $indexed);
                
                // Vérifier si déjà indexé (par checksum ou nom)
                $checksum = md5_file($filePath);
                
                // Si le fichier existe déjà par checksum, mettre à jour son relative_path
                if (isset($existingFiles[$checksum])) {
                    // Mettre à jour le relative_path du document existant
                    $fullRelativePath = $relativePath ? $relativePath . '/' . $filename : $filename;
                    $updateStmt = $db->prepare("UPDATE documents SET relative_path = ?, file_path = ?, updated_at = NOW() WHERE checksum = ? AND deleted_at IS NULL");
                    $updateStmt->execute([$fullRelativePath, $filePath, $checksum]);
                    $indexed++; // Compter comme indexé car le chemin a été mis à jour
                    continue;
                }
                
                // Vérifier par nom de fichier (ne pas skipper, peut être un fichier différent avec le même nom)
                // On indexe quand même car le contenu peut être différent
                
                // Créer le document en DB
                try {
                    $documentId = $this->createDocument($filePath, $filename, $relativePath, $checksum);
                    if ($documentId) {
                        $indexed++;
                        
                        // Traiter le document (OCR, thumbnail, etc.) si DocumentProcessor disponible
                        $this->processDocument($documentId);
                    } else {
                        $errors++;
                    }
                } catch (\Exception $e) {
                    error_log("FolderIndexerService: Erreur indexation $filename: " . $e->getMessage());
                    $errors++;
                }
            }
            
            // Compter les documents en DB pour ce dossier
            $dbCount = $this->countDocumentsInFolder($db, $relativePath);
            
            // Supprimer .indexing et créer .index
            $this->indexService->removeIndexing($relativePath);
            $this->indexService->writeIndex($relativePath, $total, $dbCount);
            
            return [
                'success' => true,
                'indexed' => $indexed,
                'skipped' => $skipped,
                'errors' => $errors,
                'total' => $total,
                'db_count' => $dbCount
            ];
            
        } catch (\Exception $e) {
            // En cas d'erreur, nettoyer le .indexing
            $this->indexService->removeIndexing($relativePath);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Scanner les fichiers physiques d'un dossier
     */
    private function scanPhysicalFiles(string $fullPath): array
    {
        $files = [];
        $items = @scandir($fullPath);
        
        if ($items === false) {
            return [];
        }
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item[0] === '.') {
                continue;
            }
            
            $itemPath = $fullPath . DIRECTORY_SEPARATOR . $item;
            
            if (!is_file($itemPath)) {
                continue;
            }
            
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (!in_array($ext, $this->allowedExtensions)) {
                continue;
            }
            
            $files[] = [
                'name' => $item,
                'path' => $itemPath,
                'size' => filesize($itemPath),
                'extension' => $ext
            ];
        }
        
        return $files;
    }
    
    /**
     * Récupérer les fichiers déjà en DB pour ce dossier
     */
    private function getExistingFiles(\PDO $db, string $relativePath, array $physicalFiles): array
    {
        $existing = [];
        
        // Par checksum
        $checksums = [];
        foreach ($physicalFiles as $file) {
            $checksums[] = md5_file($file['path']);
        }
        
        if (!empty($checksums)) {
            $placeholders = implode(',', array_fill(0, count($checksums), '?'));
            $stmt = $db->prepare("SELECT checksum, original_filename FROM documents WHERE checksum IN ($placeholders) AND deleted_at IS NULL");
            $stmt->execute($checksums);
            
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                if ($row['checksum']) {
                    $existing[$row['checksum']] = true;
                }
                if ($row['original_filename']) {
                    $existing[$row['original_filename']] = true;
                }
            }
        }
        
        // Par relative_path
        $pathPrefix = $relativePath ? $relativePath . '/' : '';
        $stmt = $db->prepare("SELECT original_filename, relative_path FROM documents WHERE relative_path LIKE ? AND deleted_at IS NULL");
        $stmt->execute([$pathPrefix . '%']);
        
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if ($row['original_filename']) {
                $existing[$row['original_filename']] = true;
            }
        }
        
        return $existing;
    }
    
    /**
     * Créer un document en DB
     */
    private function createDocument(string $filePath, string $filename, string $relativePath, string $checksum): ?int
    {
        $db = Database::getInstance();
        
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        $fileSize = filesize($filePath);
        $title = pathinfo($filename, PATHINFO_FILENAME);
        
        // Construire le relative_path complet (dossier + fichier)
        $fullRelativePath = $relativePath ? $relativePath . '/' . $filename : $filename;
        
        $stmt = $db->prepare("
            INSERT INTO documents (
                title, filename, original_filename, file_path, file_size, 
                mime_type, checksum, relative_path, status, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'indexed', NOW(), NOW())
        ");
        
        $result = $stmt->execute([
            $title,
            $filename,
            $filename,
            $filePath,
            $fileSize,
            $mimeType,
            $checksum,
            $fullRelativePath
        ]);
        
        if ($result) {
            return (int) $db->lastInsertId();
        }
        
        return null;
    }
    
    /**
     * Traiter un document (OCR, thumbnail, etc.)
     */
    private function processDocument(int $documentId): void
    {
        try {
            if (class_exists('\KDocs\Services\DocumentProcessor')) {
                $processor = new DocumentProcessor();
                $processor->processDocument($documentId);
            }
        } catch (\Exception $e) {
            // Ignorer les erreurs de traitement
            error_log("FolderIndexerService: Erreur traitement document $documentId: " . $e->getMessage());
        }
    }
    
    /**
     * Compter les documents en DB pour un dossier
     */
    private function countDocumentsInFolder(\PDO $db, string $relativePath): int
    {
        $pathPrefix = $relativePath ? $relativePath . '/' : '';
        
        if ($relativePath === '') {
            $stmt = $db->query("SELECT COUNT(*) FROM documents WHERE deleted_at IS NULL AND (relative_path IS NULL OR relative_path = '' OR relative_path NOT LIKE '%/%')");
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM documents WHERE deleted_at IS NULL AND relative_path LIKE ? AND relative_path NOT LIKE ?");
            $stmt->execute([$pathPrefix . '%', $pathPrefix . '%/%']);
        }
        
        return (int) $stmt->fetchColumn();
    }
    
    /**
     * Déclencher l'indexation d'un dossier en arrière-plan (via queue)
     */
    public static function queueIndexing(string $relativePath): bool
    {
        try {
            if (class_exists('\KDocs\Services\QueueService')) {
                return QueueService::queueFolderIndexing($relativePath);
            }
            
            // Fallback: créer un fichier de queue
            $queueDir = __DIR__ . '/../../storage/folder_index_queue';
            if (!is_dir($queueDir)) {
                @mkdir($queueDir, 0755, true);
            }
            
            $hash = md5($relativePath ?: '/');
            $existingQueues = glob($queueDir . '/index_' . $hash . '_*.json');
            
            if (!empty($existingQueues)) {
                return true; // Déjà en queue
            }
            
            $queueFile = $queueDir . '/index_' . $hash . '_' . time() . '.json';
            return @file_put_contents($queueFile, json_encode([
                'path' => $relativePath,
                'created_at' => time()
            ])) !== false;
            
        } catch (\Exception $e) {
            error_log("FolderIndexerService::queueIndexing error: " . $e->getMessage());
            return false;
        }
    }
}

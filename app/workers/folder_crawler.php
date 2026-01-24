<?php
/**
 * K-Docs - Worker de crawl des dossiers
 * Crawle un dossier à la fois pour éviter de bloquer l'utilisateur
 */

require_once __DIR__ . '/../../app/autoload.php';

use KDocs\Core\Database;
use KDocs\Services\FilesystemReader;
use KDocs\Services\DocumentMapper;
use KDocs\Services\FolderIndexService;

class FolderCrawler
{
    private $db;
    private $fsReader;
    private $crawlDir;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->fsReader = new FilesystemReader();
        $this->crawlDir = __DIR__ . '/../../storage/crawl_queue';
        
        if (!is_dir($this->crawlDir)) {
            @mkdir($this->crawlDir, 0755, true);
        }
    }
    
    /**
     * Traite une tâche de crawl
     */
    public function processTask(string $taskFile): bool
    {
        $task = json_decode(file_get_contents($taskFile), true);
        if (!$task || !isset($task['path'])) {
            @unlink($taskFile);
            return false;
        }
        
        $relativePath = $task['path'];
        
        try {
            // Obtenir le chemin complet du dossier
            $basePath = $this->fsReader->getBasePath();
            $fullPath = $basePath . ($relativePath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) : '');
            
            // Créer le fichier .indexing pour indiquer que l'indexation est en cours
            $indexingFile = $fullPath . DIRECTORY_SEPARATOR . '.indexing';
            
            // Lire le contenu du dossier
            $content = $this->fsReader->readDirectory($relativePath, false);
            
            if (isset($content['error'])) {
                error_log("FolderCrawler: Erreur lecture $relativePath: " . $content['error']);
                @unlink($indexingFile);
                @unlink($taskFile);
                return false;
            }
            
            // Traiter les fichiers (trier par date modifiée, plus récent d'abord)
            $files = $content['files'] ?? [];
            $totalFiles = count($files);
            usort($files, function($a, $b) {
                $timeA = $a['modified'] ?? 0;
                $timeB = $b['modified'] ?? 0;
                return $timeB <=> $timeA; // Plus récent d'abord
            });
            
            // Créer le fichier .indexing AVANT de commencer le traitement
            $indexService = new \KDocs\Services\FolderIndexService();
            $indexService->writeIndexingProgress($relativePath, $totalFiles, 0, 0);
            
            $mapper = new DocumentMapper();
            $processed = 0;
            $skipped = 0;
            $currentFile = 0;
            
            foreach ($files as $file) {
                $currentFile++;
                
                try {
                    // Mettre à jour le fichier .indexing avec la progression
                    $indexService->writeIndexingProgress($relativePath, $totalFiles, $currentFile, $processed);
                    
                    // Vérifier si le fichier existe déjà dans la DB (par checksum ou chemin)
                    $filePath = $file['full_path'] ?? ($relativePath . '/' . $file['name']);
                    $checksum = @md5_file($filePath);
                    
                    if ($checksum) {
                        $stmt = $this->db->prepare("
                            SELECT id FROM documents 
                            WHERE checksum = ? 
                            AND deleted_at IS NULL
                            LIMIT 1
                        ");
                        $stmt->execute([$checksum]);
                        
                        if ($stmt->fetch()) {
                            $skipped++;
                            continue; // Déjà dans la DB
                        }
                    }
                    
                    // Mapper le fichier (créer ou mettre à jour le document)
                    $result = $mapper->mapFile($filePath, $relativePath);
                    
                    if ($result === 'new' || $result === 'updated') {
                        $processed++;
                    } else {
                        $skipped++;
                    }
                    
                } catch (\Exception $e) {
                    error_log("FolderCrawler: Erreur traitement fichier {$file['name']}: " . $e->getMessage());
                    continue;
                }
            }
            
            // Compter les documents dans la DB pour ce chemin
            $dbCount = $this->countDocumentsInPath($relativePath);
            
            // Créer le fichier .index avec les résultats finaux (remplace .indexed)
            $indexService->writeIndex($relativePath, $totalFiles, $dbCount);
            
            // Supprimer le fichier .indexing (indexation terminée)
            $indexService->removeIndexing($relativePath);
            
            error_log("FolderCrawler: Traité $relativePath - $processed nouveaux/mis à jour, $skipped ignorés, $dbCount documents dans DB");
            
            // Supprimer la tâche
            @unlink($taskFile);
            return true;
            
        } catch (\Exception $e) {
            error_log("FolderCrawler: Erreur traitement tâche $relativePath: " . $e->getMessage());
            // Supprimer le fichier .indexing en cas d'erreur
            if (isset($indexService)) {
                $indexService->removeIndexing($relativePath);
            }
            @unlink($taskFile);
            return false;
        }
    }
    
    /**
     * Compte les documents dans la DB pour un chemin donné
     */
    private function countDocumentsInPath(string $relativePath): int
    {
        try {
            $normalizedPath = str_replace('\\', '/', $relativePath);
            $searchPath1 = '%/' . $normalizedPath . '/%';
            $searchPath2 = '%/' . $normalizedPath . '%';
            $searchPath3 = $normalizedPath . '/%';
            
            $stmt = $this->db->prepare("
                SELECT COUNT(*) 
                FROM documents 
                WHERE deleted_at IS NULL 
                AND (status IS NULL OR status != 'pending')
                AND (
                    file_path LIKE ? 
                    OR file_path LIKE ?
                    OR relative_path = ?
                    OR relative_path LIKE ?
                )
            ");
            $stmt->execute([
                $searchPath1,
                $searchPath2,
                $normalizedPath,
                $searchPath3
            ]);
            return (int)$stmt->fetchColumn();
        } catch (\Exception $e) {
            error_log("FolderCrawler: Erreur comptage documents pour $relativePath: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Traite toutes les tâches en attente (une à la fois)
     */
    public function processQueue(): int
    {
        $tasks = glob($this->crawlDir . '/crawl_*.json');
        if (empty($tasks)) {
            return 0;
        }
        
        // Trier par priorité et date de création
        usort($tasks, function($a, $b) {
            $taskA = json_decode(file_get_contents($a), true);
            $taskB = json_decode(file_get_contents($b), true);
            
            // Priorité haute d'abord
            if (($taskA['priority'] ?? 'normal') === 'high' && ($taskB['priority'] ?? 'normal') !== 'high') {
                return -1;
            }
            if (($taskB['priority'] ?? 'normal') === 'high' && ($taskA['priority'] ?? 'normal') !== 'high') {
                return 1;
            }
            
            // Plus ancien d'abord
            return ($taskA['created_at'] ?? 0) <=> ($taskB['created_at'] ?? 0);
        });
        
        // Traiter une seule tâche à la fois
        $taskFile = $tasks[0];
        if ($this->processTask($taskFile)) {
            return 1;
        }
        
        return 0;
    }
    
    /**
     * Crawl périodique de tous les fichiers (plus récent d'abord)
     */
    public function crawlAll(): void
    {
        try {
            // Récupérer tous les fichiers du système de fichiers, triés par date
            $allFiles = $this->getAllFilesSorted();
            
            $mapper = new DocumentMapper();
            $processed = 0;
            $skipped = 0;
            
            foreach ($allFiles as $file) {
                try {
                    $checksum = @md5_file($file['full_path']);
                    
                    if ($checksum) {
                        $stmt = $this->db->prepare("
                            SELECT id FROM documents 
                            WHERE checksum = ? 
                            AND deleted_at IS NULL
                            LIMIT 1
                        ");
                        $stmt->execute([$checksum]);
                        
                        if ($stmt->fetch()) {
                            $skipped++;
                            continue;
                        }
                    }
                    
                    $result = $mapper->mapFile($file['full_path'], $file['relative_path']);
                    
                    if ($result === 'new' || $result === 'updated') {
                        $processed++;
                    } else {
                        $skipped++;
                    }
                    
                } catch (\Exception $e) {
                    error_log("FolderCrawler: Erreur traitement fichier {$file['name']}: " . $e->getMessage());
                    continue;
                }
            }
            
            error_log("FolderCrawler: Crawl complet - $processed nouveaux/mis à jour, $skipped ignorés");
            
        } catch (\Exception $e) {
            error_log("FolderCrawler: Erreur crawl complet: " . $e->getMessage());
        }
    }
    
    /**
     * Récupère tous les fichiers triés par date (plus récent d'abord)
     */
    private function getAllFilesSorted(): array
    {
        $allFiles = [];
        $this->collectFilesRecursive('', $allFiles);
        
        usort($allFiles, function($a, $b) {
            $timeA = $a['modified'] ?? 0;
            $timeB = $b['modified'] ?? 0;
            return $timeB <=> $timeA; // Plus récent d'abord
        });
        
        return $allFiles;
    }
    
    /**
     * Collecte récursivement tous les fichiers
     */
    private function collectFilesRecursive(string $relativePath, array &$allFiles, int $depth = 0, int $maxDepth = 20): void
    {
        if ($depth > $maxDepth) {
            return;
        }
        
        try {
            $content = $this->fsReader->readDirectory($relativePath, false);
            
            if (isset($content['error'])) {
                return;
            }
            
            // Ajouter les fichiers
            foreach (($content['files'] ?? []) as $file) {
                $file['relative_path'] = $relativePath;
                $allFiles[] = $file;
            }
            
            // Parcourir récursivement les sous-dossiers
            foreach (($content['folders'] ?? []) as $folder) {
                $subPath = $relativePath ? $relativePath . '/' . $folder['name'] : $folder['name'];
                $this->collectFilesRecursive($subPath, $allFiles, $depth + 1, $maxDepth);
            }
            
        } catch (\Exception $e) {
            error_log("FolderCrawler: Erreur collecte récursive $relativePath: " . $e->getMessage());
        }
    }
}

// Exécution en ligne de commande
if (php_sapi_name() === 'cli') {
    $crawler = new FolderCrawler();
    
    // Traiter une tâche de la queue
    $processed = $crawler->processQueue();
    
    if ($processed === 0 && isset($argv[1]) && $argv[1] === '--full') {
        // Crawl complet si demandé
        $crawler->crawlAll();
    }
}

<?php
/**
 * K-Docs - Worker d'indexation intelligent
 * 
 * Caractéristiques :
 * - Comparaison rapide (mtime + size) avant checksum
 * - Pas de ré-indexation des fichiers inchangés
 * - Pauses configurables pour ne pas saturer
 * - Gestion propre des ressources
 */

require_once __DIR__ . '/../../app/autoload.php';

use KDocs\Core\Database;
use KDocs\Services\IndexingService;
use KDocs\Services\FilesystemReader;
use KDocs\Services\DocumentMapper;

class SmartIndexer
{
    private $db;
    private IndexingService $indexing;
    private FilesystemReader $fsReader;
    private DocumentMapper $mapper;
    private string $basePath;
    private array $config;
    private string $queueDir;
    
    // Statistiques de la session
    private array $stats = [
        'scanned' => 0,
        'skipped' => 0,
        'new' => 0,
        'updated' => 0,
        'errors' => 0,
    ];
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->indexing = new IndexingService();
        $this->fsReader = new FilesystemReader();
        $this->mapper = new DocumentMapper();
        $this->basePath = $this->fsReader->getBasePath();
        $this->config = $this->indexing->getConfig();
        $this->queueDir = __DIR__ . '/../../storage/crawl_queue';
        
        // Appliquer les limites de ressources
        $this->indexing->applyResourceLimits();
    }
    
    /**
     * Traite la prochaine queue
     */
    public function processNextQueue(): bool
    {
        // Nettoyer les queues expirées d'abord
        $cleaned = $this->indexing->cleanExpiredQueues();
        if ($cleaned > 0) {
            $this->log("Nettoyé $cleaned queue(s) expirée(s)");
        }
        
        // Trouver la prochaine queue (priorité haute d'abord)
        $taskFile = $this->getNextTask();
        
        if (!$taskFile) {
            return false;
        }
        
        $task = json_decode(file_get_contents($taskFile), true);
        
        if (!$task || !isset($task['path'])) {
            @unlink($taskFile);
            return false;
        }
        
        $relativePath = $task['path'];
        
        try {
            $this->log("Début indexation: $relativePath");
            
            // Créer le fichier .indexing
            $this->indexing->writeIndexingProgress($relativePath, 0, 0, 'starting');
            
            // Indexer le dossier
            $this->indexDirectory($relativePath);
            
            // Supprimer le .indexing et mettre à jour le .index
            $this->finalizeIndex($relativePath);
            
            $this->log("Fin indexation: $relativePath - Scanné: {$this->stats['scanned']}, Skippé: {$this->stats['skipped']}, Nouveaux: {$this->stats['new']}, Erreurs: {$this->stats['errors']}");
            
            // Supprimer la tâche
            @unlink($taskFile);
            
            return true;
            
        } catch (\Exception $e) {
            $this->log("ERREUR: " . $e->getMessage());
            $this->indexing->removeIndexing($relativePath);
            @unlink($taskFile);
            return false;
        }
    }
    
    /**
     * Indexe un dossier avec comparaison intelligente
     */
    private function indexDirectory(string $relativePath): void
    {
        $fullPath = $this->basePath . ($relativePath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) : '');
        
        if (!is_dir($fullPath)) {
            throw new \Exception("Dossier inexistant: $fullPath");
        }
        
        // Lire le .index existant (pour comparaison)
        $existingIndex = $this->indexing->readIndex($relativePath);
        $existingFiles = $existingIndex['files'] ?? [];
        
        // Lister les fichiers du dossier (sans sous-dossiers)
        $content = $this->fsReader->readDirectory($relativePath, false);
        
        if (isset($content['error'])) {
            throw new \Exception("Erreur lecture dossier: " . $content['error']);
        }
        
        $files = $content['files'] ?? [];
        $totalFiles = count($files);
        
        $this->log("$totalFiles fichiers à analyser dans: $relativePath");
        
        // Nouveau fichier .index
        $newIndex = [
            'version' => 2,
            'file_count' => 0,
            'db_count' => 0,
            'files' => [],
        ];
        
        $batchCount = 0;
        
        foreach ($files as $i => $file) {
            $filename = $file['name'];
            $filePath = $file['full_path'] ?? ($fullPath . DIRECTORY_SEPARATOR . $filename);
            
            $this->stats['scanned']++;
            
            // Mise à jour progression (tous les 10 fichiers ou selon config)
            $updateInterval = max(1, (int)($this->config['progress_update_interval'] ?? 5));
            if ($i % $updateInterval === 0 || $i === $totalFiles - 1) {
                $this->indexing->writeIndexingProgress($relativePath, $totalFiles, $i + 1, 'scanning');
            }
            
            // COMPARAISON RAPIDE : mtime + size
            $currentMtime = @filemtime($filePath);
            $currentSize = @filesize($filePath);
            
            if ($currentMtime === false || $currentSize === false) {
                $this->stats['errors']++;
                continue;
            }
            
            // Vérifier si le fichier est dans l'index existant
            $needsProcessing = true;
            
            if (isset($existingFiles[$filename])) {
                $indexed = $existingFiles[$filename];
                
                // Comparaison RAPIDE (pas de checksum)
                if (isset($indexed['mtime']) && isset($indexed['size']) && 
                    $indexed['mtime'] === $currentMtime && $indexed['size'] === $currentSize) {
                    // Fichier inchangé : garder l'entrée existante
                    $newIndex['files'][$filename] = $indexed;
                    $this->stats['skipped']++;
                    $needsProcessing = false;
                }
            }
            
            if ($needsProcessing) {
                // Fichier nouveau ou modifié : calculer le checksum
                $checksum = @md5_file($filePath);
                
                if ($checksum === false) {
                    $this->stats['errors']++;
                    continue;
                }
                
                // Vérifier en DB par checksum
                $dbRecord = $this->findDocumentByChecksum($checksum);
                
                if ($dbRecord) {
                    // Document existe en DB avec ce checksum
                    $newIndex['files'][$filename] = [
                        'mtime' => $currentMtime,
                        'size' => $currentSize,
                        'checksum' => $checksum,
                        'db_id' => $dbRecord['id'],
                        'indexed_at' => time(),
                    ];
                    
                    // Mettre à jour le chemin si nécessaire
                    $this->updateDocumentPath($dbRecord['id'], $relativePath, $filename, $filePath);
                    $this->stats['skipped']++;
                    
                } else {
                    // Nouveau document : utiliser DocumentMapper pour créer
                    $docRelativePath = $relativePath ? $relativePath . '/' . $filename : $filename;
                    $result = $this->mapper->mapFile($filePath, $docRelativePath);
                    
                    if ($result === 'new' || $result === 'updated') {
                        // Récupérer l'ID du document créé/mis à jour
                        $stmt = $this->db->prepare("SELECT id FROM documents WHERE checksum = ? AND deleted_at IS NULL LIMIT 1");
                        $stmt->execute([$checksum]);
                        $doc = $stmt->fetch(\PDO::FETCH_ASSOC);
                        
                        if ($doc) {
                            $newIndex['files'][$filename] = [
                                'mtime' => $currentMtime,
                                'size' => $currentSize,
                                'checksum' => $checksum,
                                'db_id' => $doc['id'],
                                'indexed_at' => time(),
                            ];
                            
                            if ($result === 'new') {
                                $this->stats['new']++;
                            } else {
                                $this->stats['updated']++;
                            }
                        } else {
                            $this->stats['errors']++;
                        }
                    } elseif ($result === 'skipped') {
                        // Fichier déjà en DB mais pas dans l'index : récupérer l'ID
                        $stmt = $this->db->prepare("SELECT id FROM documents WHERE checksum = ? AND deleted_at IS NULL LIMIT 1");
                        $stmt->execute([$checksum]);
                        $doc = $stmt->fetch(\PDO::FETCH_ASSOC);
                        
                        if ($doc) {
                            $newIndex['files'][$filename] = [
                                'mtime' => $currentMtime,
                                'size' => $currentSize,
                                'checksum' => $checksum,
                                'db_id' => $doc['id'],
                                'indexed_at' => time(),
                            ];
                            $this->stats['skipped']++;
                        } else {
                            $this->stats['errors']++;
                        }
                    } else {
                        $this->stats['errors']++;
                    }
                }
            }
            
            // Pause entre fichiers
            $this->indexing->pauseBetweenFiles();
            
            // Pause après batch
            $batchCount++;
            if ($batchCount >= $this->config['batch_size']) {
                $this->indexing->pauseAfterBatch();
                $batchCount = 0;
            }
        }
        
        // Mettre à jour les compteurs
        $newIndex['file_count'] = count($newIndex['files']);
        $newIndex['db_count'] = $this->countDocumentsInPath($relativePath);
        
        // Écrire le nouveau .index
        $this->indexing->writeIndex($relativePath, $newIndex);
    }
    
    /**
     * Cherche un document par checksum
     */
    private function findDocumentByChecksum(string $checksum): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, file_path, relative_path 
            FROM documents 
            WHERE checksum = ? AND deleted_at IS NULL 
            LIMIT 1
        ");
        $stmt->execute([$checksum]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Met à jour le chemin d'un document si nécessaire
     */
    private function updateDocumentPath(int $docId, string $relativePath, string $filename, string $fullPath): void
    {
        $docRelativePath = $relativePath ? $relativePath . '/' . $filename : $filename;
        
        $stmt = $this->db->prepare("
            UPDATE documents SET 
                file_path = ?,
                relative_path = ?,
                updated_at = NOW()
            WHERE id = ? AND (file_path != ? OR relative_path != ?)
        ");
        $stmt->execute([$fullPath, $docRelativePath, $docId, $fullPath, $docRelativePath]);
    }
    
    /**
     * Compte les documents en DB pour un chemin
     */
    private function countDocumentsInPath(string $relativePath): int
    {
        try {
            $normalizedPath = str_replace('\\', '/', $relativePath);
            $searchPattern = $normalizedPath ? $normalizedPath . '/%' : '%';
            $exactPath = $normalizedPath ?: '';
            
            $stmt = $this->db->prepare("
                // Compter TOUS les documents (y compris pending) pour comparaison avec fichiers physiques
                SELECT COUNT(*) FROM documents 
                WHERE (
                    (relative_path LIKE ? AND relative_path NOT LIKE ?)
                    OR relative_path = ?
                )
                AND deleted_at IS NULL
            ");
            
            $excludeSubfolders = $normalizedPath ? $normalizedPath . '/%/%' : '%/%/%';
            $stmt->execute([$searchPattern, $excludeSubfolders, $exactPath]);
            
            return (int)$stmt->fetchColumn();
        } catch (\Exception $e) {
            $this->log("Erreur comptage documents: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Finalise l'indexation
     */
    private function finalizeIndex(string $relativePath): void
    {
        $this->indexing->removeIndexing($relativePath);
    }
    
    /**
     * Trouve la prochaine tâche à traiter
     */
    private function getNextTask(): ?string
    {
        if (!is_dir($this->queueDir)) {
            return null;
        }
        
        $tasks = glob($this->queueDir . '/crawl_*.json');
        
        if (empty($tasks)) {
            return null;
        }
        
        // Trier par priorité puis par date
        usort($tasks, function($a, $b) {
            $taskA = json_decode(file_get_contents($a), true) ?: [];
            $taskB = json_decode(file_get_contents($b), true) ?: [];
            
            $prioA = ($taskA['priority'] ?? 'normal') === 'high' ? 0 : 1;
            $prioB = ($taskB['priority'] ?? 'normal') === 'high' ? 0 : 1;
            
            if ($prioA !== $prioB) {
                return $prioA - $prioB;
            }
            
            return ($taskA['created_at'] ?? 0) - ($taskB['created_at'] ?? 0);
        });
        
        return $tasks[0];
    }
    
    private function log(string $message): void
    {
        error_log("[SmartIndexer] " . $message);
    }
}

// Exécution CLI
if (php_sapi_name() === 'cli') {
    $indexer = new SmartIndexer();
    
    // Traiter une queue
    $processed = $indexer->processNextQueue();
    
    exit($processed ? 0 : 1);
}

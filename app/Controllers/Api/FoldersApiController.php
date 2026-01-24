<?php
/**
 * K-Docs - API Controller pour les dossiers filesystem
 */

namespace KDocs\Controllers\Api;

use KDocs\Services\FilesystemReader;
use KDocs\Core\Database;
use KDocs\Core\Config;
use KDocs\Services\DocumentMapper;
use KDocs\Services\FolderIndexService;
use KDocs\Services\IndexingService;
use KDocs\Services\QueueService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class FoldersApiController
{
    /**
     * Récupère les sous-dossiers d'un dossier parent - VERSION CORRIGÉE
     * Reçoit le PATH directement, plus de recherche récursive
     */
    public function getChildren(Request $request, Response $response): Response
    {
        try {
            $queryParams = $request->getQueryParams();
            $parentPath = $queryParams['path'] ?? '';
            
            // Normaliser le chemin
            $parentPath = trim($parentPath, '/');
            if ($parentPath === 'root' || $parentPath === '/') {
                $parentPath = '';
            }
            
            $fsReader = new FilesystemReader();
            $basePath = $fsReader->getBasePath();
            $fullParentPath = $basePath . ($parentPath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $parentPath) : '');
            
            // Vérifier que le dossier existe
            if (!is_dir($fullParentPath)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'error' => 'Dossier inexistant',
                    'folders' => []
                ]);
            }
            
            $folders = [];
            $items = @scandir($fullParentPath);
            
            if ($items === false) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'error' => 'Impossible de lire le dossier',
                    'folders' => []
                ]);
            }
            
            $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'tiff', 'tif', 'doc', 'docx'];
            
            foreach ($items as $item) {
                // Ignorer les fichiers cachés et spéciaux
                if ($item === '.' || $item === '..' || $item[0] === '.') {
                    continue;
                }
                
                $itemFullPath = $fullParentPath . DIRECTORY_SEPARATOR . $item;
                
                // Seulement les dossiers
                if (!is_dir($itemFullPath)) {
                    continue;
                }
                
                $folderPath = $parentPath ? $parentPath . '/' . $item : $item;
                
                // Compter les fichiers (rapide, juste scandir)
                $fileCount = 0;
                $hasChildren = false;
                $subItems = @scandir($itemFullPath);
                
                if ($subItems !== false) {
                    foreach ($subItems as $subItem) {
                        if ($subItem === '.' || $subItem === '..' || $subItem[0] === '.') {
                            continue;
                        }
                        
                        $subItemPath = $itemFullPath . DIRECTORY_SEPARATOR . $subItem;
                        
                        if (is_dir($subItemPath)) {
                            $hasChildren = true;
                        } elseif (is_file($subItemPath)) {
                            // Vérifier l'extension
                            $ext = strtolower(pathinfo($subItem, PATHINFO_EXTENSION));
                            if (in_array($ext, $allowedExtensions)) {
                                $fileCount++;
                            }
                        }
                    }
                }
                
                // Lire le fichier .index si disponible (pour db_count)
                $indexPath = $itemFullPath . DIRECTORY_SEPARATOR . '.index';
                $dbCount = 0;
                $needsSync = false;
                $isIndexing = file_exists($itemFullPath . DIRECTORY_SEPARATOR . '.indexing');
                
                if (file_exists($indexPath)) {
                    $indexData = @json_decode(file_get_contents($indexPath), true);
                    if ($indexData) {
                        $dbCount = $indexData['db_count'] ?? 0;
                        // Utiliser le file_count du .index s'il est plus récent
                        if (isset($indexData['file_count'])) {
                            $needsSync = ($indexData['file_count'] != $dbCount);
                        }
                    }
                } else {
                    // Pas de .index = pas encore indexé
                    $needsSync = ($fileCount > 0);
                }
                
                $folders[] = [
                    'id' => md5($folderPath),
                    'path' => $folderPath,
                    'name' => $item,
                    'file_count' => $fileCount,
                    'db_count' => $dbCount,
                    'has_children' => $hasChildren,
                    'needs_sync' => $needsSync,
                    'is_indexing' => $isIndexing,
                ];
            }
            
            // Trier par nom
            usort($folders, fn($a, $b) => strcasecmp($a['name'], $b['name']));
            
            return $this->jsonResponse($response, [
                'success' => true,
                'folders' => $folders,
                'parent_path' => $parentPath ?: '/',
            ]);
            
        } catch (\Exception $e) {
            error_log("FoldersApiController::getChildren - Erreur: " . $e->getMessage());
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => $e->getMessage(),
                'folders' => []
            ]);
        }
    }
    
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
    
    /**
     * Vérifie rapidement si un dossier a des sous-dossiers
     */
    private function hasSubfolders(FilesystemReader $fsReader, string $path): bool
    {
        try {
            $basePath = $fsReader->getBasePath();
            $fullPath = $basePath . ($path ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path) : '');
            
            if (!is_dir($fullPath)) {
                return false;
            }
            
            $handle = opendir($fullPath);
            if (!$handle) {
                return false;
            }
            
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') continue;
                if ($entry[0] === '.') continue; // Ignorer fichiers cachés (.index, .indexing)
                
                if (is_dir($fullPath . DIRECTORY_SEPARATOR . $entry)) {
                    closedir($handle);
                    return true;
                }
            }
            
            closedir($handle);
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    private function jsonSuccess(Response $response, array $data): Response
    {
        $response->getBody()->write(json_encode(array_merge(['success' => true], $data)));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    private function jsonError(Response $response, string $error, int $status): Response
    {
        $response->getBody()->write(json_encode(['success' => false, 'error' => $error]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
    
    /**
     * Charge récursivement tous les dossiers avec leur profondeur
     * Version optimisée : ne fait PAS le comptage DB ni déclenche les crawls (fait par le frontend de manière asynchrone)
     */
    private function loadFoldersRecursive(FilesystemReader $fsReader, $db, string $relativePath, int $depth, int $maxDepth = 20): array
    {
        if ($depth > $maxDepth) {
            return []; // Limiter la profondeur
        }
        
        $folders = [];
        
        try {
            $content = $fsReader->readDirectory($relativePath, false);
            
            if (isset($content['error'])) {
                error_log("FoldersApiController: Erreur lecture dossier $relativePath: " . $content['error']);
                return [];
            }
            
            foreach (($content['folders'] ?? []) as $folder) {
                try {
                    $folderPath = $relativePath ? $relativePath . '/' . $folder['name'] : $folder['name'];
                    $pathId = md5($folderPath);
                    
                    // Compter les fichiers physiques dans ce dossier
                    $physicalFileCount = $folder['file_count'] ?? 0;
                    
                    // NE PAS faire le comptage DB ici (trop lent) - sera fait par le frontend de manière asynchrone
                    // NE PAS déclencher les crawls ici - sera fait par le frontend après affichage
                    
                    // Vérifier si ce dossier a des sous-dossiers
                    $hasChildren = false;
                    $children = [];
                    try {
                        $subContent = $fsReader->readDirectory($folderPath, false);
                        $hasChildren = !empty($subContent['folders']) && !isset($subContent['error']);
                        
                        // Charger récursivement les sous-dossiers
                        if ($hasChildren) {
                            $children = $this->loadFoldersRecursive($fsReader, $db, $folderPath, $depth + 1, $maxDepth);
                        }
                    } catch (\Exception $e) {
                        error_log("FoldersApiController: Erreur lecture sous-dossier $folderPath: " . $e->getMessage());
                    }
                    
                    $folders[] = [
                        'id' => $pathId,
                        'path' => $folderPath,
                        'name' => $folder['name'],
                        'file_count' => $physicalFileCount,
                        'db_file_count' => null, // Sera chargé par le frontend de manière asynchrone
                        'has_children' => $hasChildren,
                        'depth' => $depth,
                        'needs_crawl' => false, // Sera déterminé par le frontend après comptage DB
                        'children' => $children,
                    ];
                } catch (\Exception $e) {
                    error_log("FoldersApiController: Erreur traitement dossier {$folder['name']}: " . $e->getMessage());
                    continue;
                }
            }
            
            // Trier par nom
            usort($folders, function($a, $b) {
                return strcmp($a['name'], $b['name']);
            });
            
        } catch (\Exception $e) {
            error_log("FoldersApiController: Erreur lecture récursive dossier $relativePath: " . $e->getMessage());
            error_log("FoldersApiController: Stack trace: " . $e->getTraceAsString());
        }
        
        return $folders;
    }
    
    /**
     * Compte les documents dans la DB pour un chemin donné
     */
    private function countDocumentsInPath($db, string $relativePath): int
    {
        try {
            // Normaliser le chemin pour la recherche
            $normalizedPath = str_replace('\\', '/', $relativePath);
            
            // Chercher les documents dont le file_path contient ce chemin
            // ou dont le relative_path correspond
            $searchPath1 = '%/' . $normalizedPath . '/%';
            $searchPath2 = '%/' . $normalizedPath . '%';
            $searchPath3 = $normalizedPath . '/%';
            
            $stmt = $db->prepare("
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
            error_log("FoldersApiController: Erreur comptage documents pour $relativePath: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Déclenche le crawl d'un dossier en arrière-plan
     */
    private function triggerCrawl(string $relativePath): void
    {
        try {
            // Créer un fichier de tâche pour le worker
            $crawlDir = __DIR__ . '/../../../storage/crawl_queue';
            if (!is_dir($crawlDir)) {
                @mkdir($crawlDir, 0755, true);
            }
            
            // Vérifier si une queue existe déjà pour ce chemin (éviter les doublons)
            $pathHash = md5($relativePath);
            $existingFiles = glob($crawlDir . '/crawl_' . $pathHash . '_*.json');
            
            // Si une queue existe déjà et a moins de 30 secondes, ne pas en créer une nouvelle
            if (!empty($existingFiles)) {
                $latestFile = max($existingFiles);
                $existingTask = json_decode(file_get_contents($latestFile), true);
                if ($existingTask && isset($existingTask['created_at'])) {
                    $age = time() - $existingTask['created_at'];
                    if ($age < 30) {
                        // Queue récente, ne pas créer de doublon
                        return;
                    }
                }
            }
            
            $taskFile = $crawlDir . '/crawl_' . $pathHash . '_' . time() . '.json';
            file_put_contents($taskFile, json_encode([
                'path' => $relativePath,
                'created_at' => time(),
                'priority' => 'high' // Crawl immédiat pour désynchronisation détectée
            ]));
        } catch (\Exception $e) {
            error_log("Erreur déclenchement crawl pour $relativePath: " . $e->getMessage());
        }
    }
    
    /**
     * Déclenche le crawl d'un dossier via API - VERSION CORRIGÉE
     * Ne crawle pas les dossiers vides
     */
    public function triggerCrawlApi(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true);
            $relativePath = $data['path'] ?? '';
            $priority = $data['priority'] ?? 'normal';
            
            if (empty($relativePath)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'error' => 'Chemin manquant'
                ], 400);
            }
            
            // Vérifier que le dossier contient des fichiers
            $fsReader = new FilesystemReader();
            $basePath = $fsReader->getBasePath();
            $fullPath = $basePath . ($relativePath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) : '');
            
            if (!is_dir($fullPath)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'error' => 'Dossier inexistant'
                ]);
            }
            
            // Compter les fichiers
            $fileCount = 0;
            $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'tiff', 'tif', 'doc', 'docx'];
            $items = @scandir($fullPath);
            
            if ($items !== false) {
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..' || $item[0] === '.') continue;
                    if (is_file($fullPath . DIRECTORY_SEPARATOR . $item)) {
                        $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                        if (in_array($ext, $allowedExtensions)) {
                            $fileCount++;
                        }
                    }
                }
            }
            
            // Ne pas crawler si vide
            if ($fileCount === 0) {
                return $this->jsonResponse($response, [
                    'success' => true,
                    'message' => 'Dossier vide, rien à indexer',
                    'status' => 'skipped'
                ]);
            }
            
            // Utiliser QueueService si disponible
            if (class_exists('\KDocs\Services\QueueService')) {
                // Vérifier si un job existe déjà pour ce chemin
                if (QueueService::hasJobForPath($relativePath)) {
                    return $this->jsonResponse($response, [
                        'success' => true,
                        'message' => 'Déjà en queue',
                        'status' => 'queued'
                    ]);
                }
                
                // Vérifier le nombre de jobs actifs (limite configurable)
                $maxQueues = Config::get('indexing.max_concurrent_queues', 2);
                $activeJobs = QueueService::countActiveJobs('indexing') + QueueService::countActiveJobs('indexing_high');
                
                if ($activeJobs >= $maxQueues && $priority !== 'high') {
                    return $this->jsonResponse($response, [
                        'success' => true,
                        'message' => 'Trop de queues actives, réessayez plus tard',
                        'status' => 'rejected',
                        'active_queues' => $activeJobs,
                        'max_queues' => $maxQueues
                    ]);
                }
                
                // Ajouter le job à la queue
                $added = QueueService::queueIndexing($relativePath, $priority);
                
                return $this->jsonResponse($response, [
                    'success' => $added,
                    'message' => $added ? 'Queue ajoutée' : 'Erreur ajout queue',
                    'status' => $added ? 'queued' : 'error'
                ]);
            } else {
                // Fallback : utiliser l'ancien système de fichiers JSON
                $crawlDir = __DIR__ . '/../../storage/crawl_queue';
                if (!is_dir($crawlDir)) {
                    @mkdir($crawlDir, 0755, true);
                }
                
                $pathHash = md5($relativePath);
                $existingQueues = glob($crawlDir . '/crawl_' . $pathHash . '_*.json');
                
                if (!empty($existingQueues)) {
                    return $this->jsonResponse($response, [
                        'success' => true,
                        'message' => 'Déjà en queue',
                        'status' => 'queued'
                    ]);
                }
                
                // Créer la queue
                $taskFile = $crawlDir . '/crawl_' . $pathHash . '_' . time() . '.json';
                $result = @file_put_contents($taskFile, json_encode([
                    'path' => $relativePath,
                    'created_at' => time(),
                    'file_count' => $fileCount
                ]));
                
                return $this->jsonResponse($response, [
                    'success' => $result !== false,
                    'message' => $result !== false ? 'Queue créée' : 'Erreur création queue',
                    'status' => $result !== false ? 'queued' : 'error'
                ]);
            }
            
        } catch (\Exception $e) {
            error_log("FoldersApiController::triggerCrawlApi - Erreur: " . $e->getMessage());
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Récupère les comptages DB pour plusieurs dossiers (batch)
     */
    public function getFolderCounts(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true);
            $paths = $data['paths'] ?? [];
            
            if (empty($paths) || !is_array($paths)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Liste de chemins manquante',
                    'counts' => []
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            
            $db = Database::getInstance();
            $counts = [];
            
            foreach ($paths as $path) {
                try {
                    $dbCount = $this->countDocumentsInPath($db, $path);
                    $counts[$path] = $dbCount;
                } catch (\Exception $e) {
                    error_log("FoldersApiController::getFolderCounts - Erreur pour $path: " . $e->getMessage());
                    $counts[$path] = 0;
                }
            }
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'counts' => $counts
            ]));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            error_log("FoldersApiController::getFolderCounts - Erreur: " . $e->getMessage());
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Erreur lors de la récupération des comptages: ' . $e->getMessage(),
                'counts' => []
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
    
    /**
     * Récupère le statut d'indexation d'un dossier (depuis .indexing ou .indexed)
     */
    public function getIndexingStatus(Request $request, Response $response): Response
    {
        try {
            $queryParams = $request->getQueryParams();
            $relativePath = $queryParams['path'] ?? '';
            
            if (empty($relativePath)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Chemin manquant',
                    'status' => null
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            
            $fsReader = new FilesystemReader();
            $basePath = $fsReader->getBasePath();
            $fullPath = $basePath . ($relativePath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) : '');
            
            // Vérifier d'abord .indexing (en cours)
            $indexingFile = $fullPath . DIRECTORY_SEPARATOR . '.indexing';
            if (file_exists($indexingFile)) {
                $data = json_decode(file_get_contents($indexingFile), true);
                if ($data) {
                    $response->getBody()->write(json_encode([
                        'success' => true,
                        'status' => 'indexing',
                        'data' => $data
                    ]));
                    return $response->withHeader('Content-Type', 'application/json');
                }
            }
            
            // Sinon vérifier .index (terminé, remplace .indexed)
            $indexFile = $fullPath . DIRECTORY_SEPARATOR . '.index';
            if (file_exists($indexFile)) {
                $data = json_decode(file_get_contents($indexFile), true);
                if ($data) {
                    $response->getBody()->write(json_encode([
                        'success' => true,
                        'status' => 'indexed',
                        'data' => $data
                    ]));
                    return $response->withHeader('Content-Type', 'application/json');
                }
            }
            
            // Aucun fichier trouvé
            $response->getBody()->write(json_encode([
                'success' => true,
                'status' => 'none',
                'data' => null
            ]));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            error_log("FoldersApiController::getIndexingStatus - Erreur: " . $e->getMessage());
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Erreur lors de la récupération du statut: ' . $e->getMessage(),
                'status' => null
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
    
    /**
     * Récupère le statut des queues de crawl en cours
     */
    public function getCrawlStatus(Request $request, Response $response): Response
    {
        try {
            $crawlDir = __DIR__ . '/../../../storage/crawl_queue';
            
            if (!is_dir($crawlDir)) {
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'queues' => []
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            }
            
            $taskFiles = glob($crawlDir . '/crawl_*.json');
            $queuesByPath = []; // Regrouper par chemin pour éviter les doublons
            
            foreach ($taskFiles as $taskFile) {
                $task = json_decode(file_get_contents($taskFile), true);
                if ($task && isset($task['path'])) {
                    $path = $task['path'];
                    // Garder seulement la queue la plus récente pour chaque chemin
                    if (!isset($queuesByPath[$path]) || 
                        ($task['created_at'] ?? 0) > ($queuesByPath[$path]['created_at'] ?? 0)) {
                        $queuesByPath[$path] = [
                            'path' => $path,
                            'created_at' => $task['created_at'] ?? time(),
                            'priority' => $task['priority'] ?? 'normal',
                            'count' => 1 // Compter le nombre de fichiers pour ce chemin
                        ];
                    } else {
                        // Incrémenter le compteur si c'est le même chemin
                        $queuesByPath[$path]['count']++;
                    }
                }
            }
            
            // Convertir en tableau et trier par priorité puis par date
            $queues = array_values($queuesByPath);
            usort($queues, function($a, $b) {
                if (($a['priority'] ?? 'normal') === 'high' && ($b['priority'] ?? 'normal') !== 'high') {
                    return -1;
                }
                if (($b['priority'] ?? 'normal') === 'high' && ($a['priority'] ?? 'normal') !== 'high') {
                    return 1;
                }
                return ($a['created_at'] ?? 0) <=> ($b['created_at'] ?? 0);
            });
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'queues' => $queues
            ]));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            error_log("FoldersApiController::getCrawlStatus - Erreur: " . $e->getMessage());
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Erreur lors de la récupération du statut: ' . $e->getMessage(),
                'queues' => []
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}

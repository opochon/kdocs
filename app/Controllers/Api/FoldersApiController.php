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
     * Récupère les documents d'un dossier (incluant les pending ET fichiers physiques non indexés)
     * Endpoint: GET /api/folders/documents?path=xxx
     */
    public function getDocuments(Request $request, Response $response): Response
    {
        try {
            $queryParams = $request->getQueryParams();
            $relativePath = trim($queryParams['path'] ?? '', '/');
            $page = max(1, (int)($queryParams['page'] ?? 1));
            $limit = min(100, max(10, (int)($queryParams['limit'] ?? 50)));
            $offset = ($page - 1) * $limit;
            $includePending = ($queryParams['include_pending'] ?? 'true') !== 'false';
            $includePhysical = ($queryParams['include_physical'] ?? 'true') !== 'false';
            
            $db = Database::getInstance();
            $fsReader = new FilesystemReader();
            $basePath = $fsReader->getBasePath();
            $fullPath = $basePath . ($relativePath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) : '');
            
            // 1. Récupérer les documents depuis la DB
            $pathPrefix = $relativePath . '/';
            $directPattern = $pathPrefix . '%';
            $excludeSubfolders = $pathPrefix . '%/%';
            
            $statusCondition = $includePending ? '' : "AND (d.status IS NULL OR d.status != 'pending')";
            
            $dbDocuments = [];
            $dbFilenames = []; // Pour éviter les doublons
            
            if ($relativePath === '') {
                $sql = "
                    SELECT d.*, dt.label as document_type_label, c.name as correspondent_name
                    FROM documents d
                    LEFT JOIN document_types dt ON d.document_type_id = dt.id
                    LEFT JOIN correspondents c ON d.correspondent_id = c.id
                    WHERE d.deleted_at IS NULL
                    $statusCondition
                    AND (d.relative_path IS NULL OR d.relative_path = '' OR d.relative_path NOT LIKE '%/%')
                    ORDER BY d.created_at DESC
                ";
                $dbDocuments = $db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
            } else {
                $sql = "
                    SELECT d.*, dt.label as document_type_label, c.name as correspondent_name
                    FROM documents d
                    LEFT JOIN document_types dt ON d.document_type_id = dt.id
                    LEFT JOIN correspondents c ON d.correspondent_id = c.id
                    WHERE d.deleted_at IS NULL
                    $statusCondition
                    AND d.relative_path IS NOT NULL
                    AND d.relative_path != ''
                    AND d.relative_path LIKE ?
                    AND d.relative_path NOT LIKE ?
                    ORDER BY d.created_at DESC
                ";
                $stmt = $db->prepare($sql);
                $stmt->execute([$directPattern, $excludeSubfolders]);
                $dbDocuments = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            }
            
            // Collecter les noms de fichiers en DB (par relative_path complet et original_filename)
            foreach ($dbDocuments as $doc) {
                // Par original_filename
                $dbFilenames[strtolower($doc['original_filename'] ?? $doc['filename'])] = true;
                // Par relative_path (extrait le nom du fichier)
                if (!empty($doc['relative_path'])) {
                    $rp = $doc['relative_path'];
                    $rpFilename = basename(str_replace('\\', '/', $rp));
                    $dbFilenames[strtolower($rpFilename)] = true;
                }
            }
            
            // 2. Scanner les fichiers physiques non indexés
            $physicalDocuments = [];
            if ($includePhysical && is_dir($fullPath)) {
                $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'tiff', 'tif', 'doc', 'docx'];
                $items = @scandir($fullPath);
                
                if ($items !== false) {
                    foreach ($items as $item) {
                        if ($item === '.' || $item === '..' || $item[0] === '.') continue;
                        
                        $itemPath = $fullPath . DIRECTORY_SEPARATOR . $item;
                        if (!is_file($itemPath)) continue;
                        
                        $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                        if (!in_array($ext, $allowedExtensions)) continue;
                        
                        // Vérifier si déjà en DB
                        if (isset($dbFilenames[strtolower($item)])) continue;
                        
                        // Fichier physique non indexé
                        $physicalDocuments[] = [
                            'id' => null,
                            'title' => pathinfo($item, PATHINFO_FILENAME),
                            'filename' => $item,
                            'original_filename' => $item,
                            'file_path' => $itemPath,
                            'file_size' => filesize($itemPath),
                            'mime_type' => mime_content_type($itemPath) ?: 'application/octet-stream',
                            'document_type_id' => null,
                            'document_type_label' => null,
                            'correspondent_id' => null,
                            'correspondent_name' => null,
                            'document_date' => null,
                            'amount' => null,
                            'currency' => 'CHF',
                            'created_at' => date('Y-m-d H:i:s', filemtime($itemPath)),
                            'updated_at' => null,
                            'status' => 'not_indexed',
                            'relative_path' => $relativePath . '/' . $item,
                            'is_physical' => true
                        ];
                    }
                }
            }
            
            // 3. Fusionner et paginer
            $allDocuments = array_merge($dbDocuments, $physicalDocuments);
            $total = count($allDocuments);
            
            // Trier par date de création décroissante
            usort($allDocuments, function($a, $b) {
                return strtotime($b['created_at'] ?? 'now') - strtotime($a['created_at'] ?? 'now');
            });
            
            // Pagination
            $documents = array_slice($allDocuments, $offset, $limit);
            
            // Générer les URLs
            $appBasePath = Config::basePath();
            foreach ($documents as &$doc) {
                if ($doc['id']) {
                    $doc['thumbnail_url'] = $appBasePath . '/documents/' . $doc['id'] . '/thumbnail';
                    $doc['view_url'] = $appBasePath . '/documents/' . $doc['id'];
                } else {
                    $doc['thumbnail_url'] = null;
                    $doc['view_url'] = null;
                }
            }
            
            return $this->jsonResponse($response, [
                'success' => true,
                'documents' => $documents,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'total_pages' => ceil($total / $limit)
                ],
                'path' => $relativePath,
                'stats' => [
                    'db_count' => count($dbDocuments),
                    'physical_count' => count($physicalDocuments)
                ]
            ]);
            
        } catch (\Exception $e) {
            error_log("FoldersApiController::getDocuments - Erreur: " . $e->getMessage());
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => $e->getMessage(),
                'documents' => []
            ], 500);
        }
    }
    
    /**
     * Déclenche l'indexation d'un dossier (crée les documents en DB)
     * Endpoint: POST /api/folders/index
     */
    public function indexFolder(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true);
            $relativePath = trim($data['path'] ?? '', '/');
            $async = $data['async'] ?? true;
            
            $indexer = new \KDocs\Services\FolderIndexerService();
            
            if ($async) {
                // Ajouter à la queue et retourner immédiatement
                $queued = \KDocs\Services\FolderIndexerService::queueIndexing($relativePath);
                
                // Créer le fichier .indexing pour indiquer que c'est en attente
                $indexService = new \KDocs\Services\FolderIndexService();
                if (!$indexService->isIndexing($relativePath)) {
                    $indexService->writeIndexingProgress($relativePath, 0, 0, 0);
                }
                
                return $this->jsonResponse($response, [
                    'success' => true,
                    'status' => 'queued',
                    'message' => 'Indexation ajoutée à la queue'
                ]);
            } else {
                // Indexation synchrone (pour tests)
                $result = $indexer->indexFolder($relativePath, false);
                return $this->jsonResponse($response, $result);
            }
            
        } catch (\Exception $e) {
            error_log("FoldersApiController::indexFolder - Erreur: " . $e->getMessage());
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Récupère l'état de toutes les indexations en cours
     * Endpoint: GET /api/folders/indexing-all
     */
    public function getAllIndexingStatus(Request $request, Response $response): Response
    {
        try {
            $fsReader = new FilesystemReader();
            $basePath = $fsReader->getBasePath();
            
            $indexingFolders = [];
            $indexService = new \KDocs\Services\FolderIndexService();
            
            // Scanner récursivement pour trouver tous les .indexing
            $this->findIndexingFiles($basePath, '', $indexingFolders, $indexService);
            
            // Vérifier aussi les queues en attente
            $queueDir = __DIR__ . '/../../storage/folder_index_queue';
            if (is_dir($queueDir)) {
                $queueFiles = glob($queueDir . '/index_*.json');
                foreach ($queueFiles as $queueFile) {
                    $queueData = @json_decode(file_get_contents($queueFile), true);
                    if ($queueData && isset($queueData['path'])) {
                        $path = $queueData['path'];
                        if (!isset($indexingFolders[$path])) {
                            $indexingFolders[$path] = [
                                'path' => $path,
                                'status' => 'queued',
                                'total' => 0,
                                'current' => 0,
                                'processed' => 0,
                                'queued_at' => $queueData['created_at'] ?? time()
                            ];
                        }
                    }
                }
            }
            
            return $this->jsonResponse($response, [
                'success' => true,
                'indexing' => array_values($indexingFolders),
                'count' => count($indexingFolders)
            ]);
            
        } catch (\Exception $e) {
            error_log("FoldersApiController::getAllIndexingStatus - Erreur: " . $e->getMessage());
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => $e->getMessage(),
                'indexing' => []
            ], 500);
        }
    }
    
    /**
     * Recherche récursive des fichiers .indexing
     */
    private function findIndexingFiles(string $basePath, string $relativePath, array &$results, $indexService, int $depth = 0): void
    {
        if ($depth > 10) return; // Limite de profondeur
        
        $fullPath = $basePath . ($relativePath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) : '');
        
        if (!is_dir($fullPath)) return;
        
        // Vérifier si ce dossier a un .indexing
        $indexingFile = $fullPath . DIRECTORY_SEPARATOR . '.indexing';
        if (file_exists($indexingFile)) {
            $data = @json_decode(file_get_contents($indexingFile), true);
            $results[$relativePath] = [
                'path' => $relativePath ?: '/',
                'status' => 'indexing',
                'total' => $data['total'] ?? 0,
                'current' => $data['current'] ?? 0,
                'processed' => $data['processed'] ?? 0,
                'started_at' => $data['started_at'] ?? null,
                'updated_at' => $data['updated_at'] ?? null
            ];
        }
        
        // Scanner les sous-dossiers
        $items = @scandir($fullPath);
        if ($items === false) return;
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item[0] === '.') continue;
            
            $itemPath = $fullPath . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $subPath = $relativePath ? $relativePath . '/' . $item : $item;
                $this->findIndexingFiles($basePath, $subPath, $results, $indexService, $depth + 1);
            }
        }
    }
    
    /**
     * Récupère le statut des queues de crawl en cours
     * Vérifie les deux sources : fichiers JSON ET table job_queue_jobs
     */
    public function getCrawlStatus(Request $request, Response $response): Response
    {
        try {
            $queuesByPath = []; // Regrouper par chemin pour éviter les doublons
            
            // 1. Lire les queues depuis les fichiers JSON (ancien système)
            $crawlDir = __DIR__ . '/../../../storage/crawl_queue';
            if (is_dir($crawlDir)) {
                $taskFiles = glob($crawlDir . '/crawl_*.json');
                
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
                                'source' => 'file',
                                'status' => 'pending'
                            ];
                        }
                    }
                }
            }
            
            // 2. Lire les queues depuis la table job_queue_jobs (nouveau système)
            try {
                $db = Database::getInstance();
                $stmt = $db->query("
                    SELECT payload, pipeline, reserved_at, created_at 
                    FROM job_queue_jobs 
                    WHERE pipeline IN ('indexing', 'indexing_high')
                    ORDER BY created_at DESC
                ");
                $jobs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                
                foreach ($jobs as $job) {
                    $payload = json_decode($job['payload'], true);
                    if ($payload && isset($payload['path'])) {
                        $path = $payload['path'];
                        $isActive = !empty($job['reserved_at']);
                        
                        // Si le chemin n'existe pas encore, ou si ce job est plus récent
                        if (!isset($queuesByPath[$path])) {
                            $queuesByPath[$path] = [
                                'path' => $path,
                                'created_at' => $payload['created_at'] ?? strtotime($job['created_at']),
                                'priority' => ($job['pipeline'] === 'indexing_high') ? 'high' : 'normal',
                                'source' => 'database',
                                'status' => $isActive ? 'active' : 'pending'
                            ];
                        } elseif ($isActive) {
                            // Mettre à jour le statut si ce job est actif
                            $queuesByPath[$path]['status'] = 'active';
                        }
                    }
                }
            } catch (\Exception $e) {
                // Ignorer les erreurs de lecture DB (table peut ne pas exister)
                error_log("getCrawlStatus: DB read error: " . $e->getMessage());
            }
            
            // 3. Vérifier aussi les fichiers .indexing dans les dossiers
            $fsReader = new FilesystemReader();
            $basePath = $fsReader->getBasePath();
            
            foreach ($queuesByPath as $path => &$queue) {
                $fullPath = $basePath . ($path ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path) : '');
                $indexingFile = $fullPath . DIRECTORY_SEPARATOR . '.indexing';
                
                if (file_exists($indexingFile)) {
                    $indexingData = @json_decode(file_get_contents($indexingFile), true);
                    if ($indexingData) {
                        $queue['status'] = 'indexing';
                        $queue['progress'] = [
                            'total' => $indexingData['total'] ?? 0,
                            'current' => $indexingData['current'] ?? 0,
                            'processed' => $indexingData['processed'] ?? 0
                        ];
                    }
                }
            }
            unset($queue); // Important : libérer la référence
            
            // Convertir en tableau et trier par priorité puis par date
            $queues = array_values($queuesByPath);
            usort($queues, function($a, $b) {
                // Actifs en premier
                if (($a['status'] ?? '') === 'active' && ($b['status'] ?? '') !== 'active') {
                    return -1;
                }
                if (($b['status'] ?? '') === 'active' && ($a['status'] ?? '') !== 'active') {
                    return 1;
                }
                // Puis priorité haute
                if (($a['priority'] ?? 'normal') === 'high' && ($b['priority'] ?? 'normal') !== 'high') {
                    return -1;
                }
                if (($b['priority'] ?? 'normal') === 'high' && ($a['priority'] ?? 'normal') !== 'high') {
                    return 1;
                }
                // Puis par date
                return ($a['created_at'] ?? 0) <=> ($b['created_at'] ?? 0);
            });
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'queues' => $queues,
                'total_pending' => count(array_filter($queues, fn($q) => ($q['status'] ?? '') !== 'active')),
                'total_active' => count(array_filter($queues, fn($q) => ($q['status'] ?? '') === 'active'))
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
    
    /**
     * Retourne l'arborescence complète des dossiers (pour le modal de déplacement)
     * Endpoint: GET /api/folders/tree
     */
    public function getTree(Request $request, Response $response): Response
    {
        try {
            $queryParams = $request->getQueryParams();
            $excludePath = $queryParams['exclude'] ?? '';
            
            $service = new \KDocs\Services\FolderService();
            $folders = $service->getAllFolders($excludePath);
            
            return $this->jsonResponse($response, [
                'success' => true,
                'folders' => $folders
            ]);
        } catch (\Exception $e) {
            error_log("FoldersApiController::getTree - Erreur: " . $e->getMessage());
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Renomme un dossier
     * Endpoint: POST /api/folders/rename
     */
    public function renameFolder(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true);
            $path = $data['path'] ?? '';
            $newName = $data['newName'] ?? $data['new_name'] ?? '';
            
            if (empty($path)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'error' => 'Chemin manquant'
                ], 400);
            }
            
            if (empty($newName)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'error' => 'Nouveau nom manquant'
                ], 400);
            }
            
            // Récupérer l'ID utilisateur depuis la session
            $userId = $_SESSION['user_id'] ?? 1;
            
            $service = new \KDocs\Services\FolderService();
            $result = $service->rename($path, $newName, $userId);
            
            return $this->jsonResponse($response, $result, $result['success'] ? 200 : 400);
        } catch (\Exception $e) {
            error_log("FoldersApiController::renameFolder - Erreur: " . $e->getMessage());
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Déplace un dossier vers une autre destination
     * Endpoint: POST /api/folders/move
     */
    public function moveFolder(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true);
            $path = $data['path'] ?? $data['source_path'] ?? '';
            $destination = $data['destination'] ?? $data['target_path'] ?? '';
            
            if (empty($path)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'error' => 'Chemin source manquant'
                ], 400);
            }
            
            // Récupérer l'ID utilisateur depuis la session
            $userId = $_SESSION['user_id'] ?? 1;
            
            $service = new \KDocs\Services\FolderService();
            $result = $service->move($path, $destination, $userId);
            
            return $this->jsonResponse($response, $result, $result['success'] ? 200 : 400);
        } catch (\Exception $e) {
            error_log("FoldersApiController::moveFolder - Erreur: " . $e->getMessage());
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Supprime un dossier (déplace vers la corbeille)
     * Endpoint: POST /api/folders/delete
     */
    public function deleteFolder(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true);
            $path = $data['path'] ?? '';
            
            if (empty($path)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'error' => 'Chemin manquant'
                ], 400);
            }
            
            // Récupérer l'ID utilisateur depuis la session
            $userId = $_SESSION['user_id'] ?? 1;
            
            $service = new \KDocs\Services\FolderService();
            $result = $service->moveToTrash($path, $userId);
            
            return $this->jsonResponse($response, $result, $result['success'] ? 200 : 400);
        } catch (\Exception $e) {
            error_log("FoldersApiController::deleteFolder - Erreur: " . $e->getMessage());
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Retourne le HTML de l'arborescence (pour rechargement AJAX)
     * Endpoint: GET /api/folders/tree-html
     */
    public function getTreeHtml(Request $request, Response $response): Response
    {
        try {
            $queryParams = $request->getQueryParams();
            $currentFolderId = $queryParams['folder'] ?? null;
            $currentFolderPath = $queryParams['path'] ?? null;
            
            $fsReader = new FilesystemReader();
            $basePath = $fsReader->getBasePath();
            $baseUrl = Config::basePath();
            
            $helper = new \KDocs\Helpers\FolderTreeHelper(
                $basePath,
                $baseUrl,
                $currentFolderId,
                10,
                $currentFolderPath
            );
            
            // Utiliser renderTreeOnly() pour ne pas dupliquer les scripts/modals
            $html = $helper->renderTreeOnly();
            
            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
            
        } catch (\Exception $e) {
            error_log("FoldersApiController::getTreeHtml - Erreur: " . $e->getMessage());
            $response->getBody()->write('<div class="text-red-500 p-2">Erreur: ' . htmlspecialchars($e->getMessage()) . '</div>');
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus(500);
        }
    }
}

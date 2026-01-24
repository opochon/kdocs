<?php
/**
 * K-Docs - API Controller pour les dossiers filesystem
 */

namespace KDocs\Controllers\Api;

use KDocs\Services\FilesystemReader;
use KDocs\Core\Database;
use KDocs\Services\DocumentMapper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class FoldersApiController
{
    /**
     * Récupère les sous-dossiers d'un dossier parent
     */
    public function getChildren(Request $request, Response $response): Response
    {
        try {
            $queryParams = $request->getQueryParams();
            $parentId = $queryParams['parent_id'] ?? null;
            
            if ($parentId === null || $parentId === md5('/')) {
                // Retourner seulement les dossiers de premier niveau (pas récursif)
                $fsReader = new FilesystemReader();
                $content = $fsReader->readDirectory('', false);
                
                if (isset($content['error'])) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'error' => $content['error'],
                        'folders' => []
                    ]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
                }
                
                $folders = [];
                foreach (($content['folders'] ?? []) as $folder) {
                    $folderPath = $folder['name'];
                    
                    // Vérifier si ce dossier a des sous-dossiers (sans les charger)
                    $hasChildren = false;
                    try {
                        $subContent = $fsReader->readDirectory($folderPath, false);
                        $hasChildren = !empty($subContent['folders']) && !isset($subContent['error']);
                    } catch (\Exception $e) {
                        error_log("Erreur vérification sous-dossiers $folderPath: " . $e->getMessage());
                    }
                    
                    $folders[] = [
                        'id' => md5($folderPath),
                        'path' => $folderPath,
                        'name' => $folder['name'],
                        'file_count' => $folder['file_count'] ?? 0,
                        'has_children' => $hasChildren,
                        'depth' => 0,
                    ];
                }
                
                // Trier par nom
                usort($folders, function($a, $b) {
                    return strcmp($a['name'], $b['name']);
                });
                
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'folders' => $folders,
                    'parent_id' => md5('/'),
                    'parent_path' => '/'
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            }
            
            // Trouver le chemin du dossier parent de manière optimisée
            // Au lieu de parcourir récursivement tous les dossiers, on utilise une approche plus directe
            $fsReader = new FilesystemReader();
            
            // Si c'est la racine, on le sait directement
            if ($parentId === md5('/')) {
                $parentPath = '';
            } else {
                // Pour les autres dossiers, on doit trouver le chemin
                // On utilise une recherche récursive mais limitée et optimisée
                $parentPath = null;
                $maxDepth = 5; // Limiter à 5 niveaux pour la performance
                
                $findPath = function($relativePath = '', $depth = 0) use (&$findPath, $fsReader, $parentId, $maxDepth, &$parentPath) {
                    if ($depth > $maxDepth || $parentPath !== null) {
                        return; // Arrêter si trouvé ou trop profond
                    }
                    
                    // Vérifier si ce chemin correspond
                    $normalizedForHash = ($relativePath === '' || $relativePath === '/') ? '/' : $relativePath;
                    $pathHash = md5($normalizedForHash);
                    if ($pathHash === $parentId) {
                        $parentPath = ($relativePath === '' || $relativePath === '/') ? '' : $relativePath;
                        return;
                    }
                    
                    // Si pas trouvé, chercher dans les sous-dossiers
                    try {
                        $content = $fsReader->readDirectory($relativePath, false);
                        if (!isset($content['error']) && !empty($content['folders'])) {
                            foreach ($content['folders'] as $folder) {
                                if ($parentPath !== null) break; // Arrêter si trouvé
                                $folderPath = $relativePath ? $relativePath . '/' . $folder['name'] : $folder['name'];
                                $findPath($folderPath, $depth + 1);
                            }
                        }
                    } catch (\Exception $e) {
                        // Ignorer les erreurs silencieusement
                    }
                };
                
                $findPath();
                
                if ($parentPath === null) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'error' => 'Dossier parent introuvable',
                        'folders' => []
                    ]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
                }
            }
            
            // Lire les sous-dossiers
            $content = $fsReader->readDirectory($parentPath, false);
            
            // Vérifier les erreurs
            if (isset($content['error'])) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => $content['error'],
                    'folders' => []
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }
            
            $folders = [];
            foreach (($content['folders'] ?? []) as $folder) {
                try {
                    $folderPath = $parentPath ? $parentPath . '/' . $folder['name'] : $folder['name'];
                    $normalizedPath = $folderPath;
                    $pathId = md5($normalizedPath);
                    
                    // Vérifier si ce dossier a des sous-dossiers de manière optimisée
                    // On fait cette vérification seulement si nécessaire (pas pour tous les dossiers)
                    // Pour améliorer les performances, on assume qu'un dossier peut avoir des enfants
                    // Le frontend vérifiera au besoin lors du clic
                    $hasChildren = true; // Optimisation : assumer qu'il peut y avoir des enfants
                    
                    $folders[] = [
                        'id' => $pathId,
                        'path' => $normalizedPath,
                        'name' => $folder['name'],
                        'file_count' => $folder['file_count'] ?? 0,
                        'has_children' => $hasChildren,
                    ];
                } catch (\Exception $e) {
                    error_log("Erreur traitement dossier: " . $e->getMessage());
                    continue;
                }
            }
            
            // Trier par nom
            usort($folders, function($a, $b) {
                return strcmp($a['name'], $b['name']);
            });
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'folders' => $folders,
                'parent_id' => $parentId,
                'parent_path' => $parentPath === '' ? '/' : $parentPath
            ]));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            error_log("FoldersApiController::getChildren - Erreur: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Erreur lors du chargement des dossiers: ' . $e->getMessage(),
                'folders' => []
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
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
     * Déclenche le crawl d'un dossier via API
     */
    public function triggerCrawlApi(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true);
            $relativePath = $data['path'] ?? '';
            
            if (empty($relativePath)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Chemin manquant'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            
            $this->triggerCrawl($relativePath);
            
            // Traiter immédiatement une tâche si possible (non-bloquant)
            if (function_exists('exec') && strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
                // Sur Linux/Mac, exécuter en arrière-plan
                $workerPath = __DIR__ . '/../workers/folder_crawler.php';
                exec("php $workerPath > /dev/null 2>&1 &");
            }
            // Sur Windows, le worker sera traité par le cron/tâche planifiée
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Crawl déclenché'
            ]));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            error_log("FoldersApiController::triggerCrawlApi - Erreur: " . $e->getMessage());
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Erreur lors du déclenchement du crawl: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
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
            
            // Sinon vérifier .indexed (terminé)
            $indexedFile = $fullPath . DIRECTORY_SEPARATOR . '.indexed';
            if (file_exists($indexedFile)) {
                $data = json_decode(file_get_contents($indexedFile), true);
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

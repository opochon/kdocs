<?php
/**
 * K-Docs - Contrôleur de gestion des documents
 */

namespace KDocs\Controllers;

use KDocs\Models\Document;
use KDocs\Models\LogicalFolder;
use KDocs\Core\Config;
use KDocs\Core\Database;
use KDocs\Services\FilesystemScanner;
use KDocs\Services\FilesystemIndexer;
use KDocs\Services\FilesystemReader;
use KDocs\Services\ThumbnailGenerator;
use KDocs\Services\DocumentProcessor;
use KDocs\Services\TrashService;
use KDocs\Services\SearchParser;
use KDocs\Services\WebhookService;
use KDocs\Services\AuditService;
use KDocs\Models\SavedSearch;
use KDocs\Services\SearchService;
use KDocs\Search\SearchQueryBuilder;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DocumentsController
{
    /**
     * Helper pour rendre un template
     */
    private function renderTemplate(string $templatePath, array $data = []): string
    {
        extract($data);
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }

    /**
     * Liste des documents
     */
    public function index(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentsController::index', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'queryParams' => $request->getQueryParams()
        ], 'B');
        // #endregion
        
        $user = $request->getAttribute('user');
        
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentsController::index', 'User attribute', [
            'userFound' => $user !== null,
            'userId' => $user['id'] ?? null
        ], 'D');
        // #endregion
        $queryParams = $request->getQueryParams();
        $page = (int)($queryParams['page'] ?? 1);
        $search = trim($queryParams['search'] ?? '');
        $logicalFolderId = !empty($queryParams['logical_folder']) ? (int)$queryParams['logical_folder'] : null;
        $folderId = !empty($queryParams['folder']) ? $queryParams['folder'] : null; // Peut être un hash MD5, pas forcément un int
        $typeId = !empty($queryParams['type']) ? (int)$queryParams['type'] : null;
        $correspondentId = !empty($queryParams['correspondent']) ? (int)$queryParams['correspondent'] : null;
        $tagId = !empty($queryParams['tag']) ? (int)$queryParams['tag'] : null;
        
        // Tri et ordre (Priorité 1.1)
        $sort = $queryParams['sort'] ?? 'created_at';
        $order = strtoupper($queryParams['order'] ?? 'desc');
        $allowedSorts = ['created_at', 'title', 'filename', 'document_date', 'amount'];
        $sort = in_array($sort, $allowedSorts) ? $sort : 'created_at';
        $order = in_array($order, ['ASC', 'DESC']) ? $order : 'DESC';
        
        $limit = 50; // Plus de documents en vue grille
        $offset = ($page - 1) * $limit;
        
        $db = Database::getInstance();
        
        // Si un dossier logique est sélectionné, utiliser LogicalFolder
        if ($logicalFolderId) {
            $logicalFolder = LogicalFolder::findById($logicalFolderId);
            if ($logicalFolder) {
                $documents = LogicalFolder::getDocuments($logicalFolderId, $limit, $offset);
                $total = LogicalFolder::countDocuments($logicalFolderId);
                
                // Appliquer la recherche si présente
                if (!empty($search)) {
                    $filtered = [];
                    foreach ($documents as $doc) {
                        if (stripos($doc['title'] ?? '', $search) !== false || 
                            stripos($doc['original_filename'] ?? '', $search) !== false ||
                            stripos($doc['filename'] ?? '', $search) !== false) {
                            $filtered[] = $doc;
                        }
                    }
                    $documents = $filtered;
                    $total = count($filtered);
                }
            } else {
                $documents = [];
                $total = 0;
            }
        } elseif ($folderId) {
            // Si un dossier filesystem est sélectionné, lire directement depuis le filesystem
            // Le folderId est un hash MD5 du chemin de dossier
            $fsReader = new FilesystemReader();
            
            // Construire la liste de tous les dossiers avec leurs chemins pour trouver le chemin sélectionné
            $allFolderPaths = [];
            
            // Fonction récursive pour lire tous les dossiers
            $readAllFolders = function($relativePath = '') use (&$readAllFolders, $fsReader, &$allFolderPaths) {
                $content = $fsReader->readDirectory($relativePath, false);
                
                foreach ($content['folders'] as $folder) {
                    $folderPath = $relativePath ? $relativePath . '/' . $folder['name'] : $folder['name'];
                    $allFolderPaths[] = $folderPath;
                    
                    // Lire récursivement les sous-dossiers
                    $readAllFolders($folderPath);
                }
            };
            
            // Ajouter la racine d'abord
            $allFolderPaths[] = '/';
            $allFolderPaths[] = ''; // Racine vide aussi
            
            // Lire tous les dossiers
            $readAllFolders();
            
            // Trouver le chemin correspondant au hash
            $folderPath = null;
            foreach ($allFolderPaths as $path) {
                // Normaliser le chemin pour le hash
                $normalizedForHash = ($path === '' || $path === '/') ? '/' : $path;
                $pathHash = md5($normalizedForHash);
                if ($pathHash === $folderId) {
                    $folderPath = $path;
                    break;
                }
            }
            
            // Si pas trouvé, essayer aussi avec le hash de la racine normalisée
            if ($folderPath === null) {
                if (md5('/') === $folderId) {
                    $folderPath = '/';
                } elseif (md5('') === $folderId) {
                    $folderPath = '';
                }
            }
            
            if ($folderPath !== null) {
                // Lire les fichiers directement depuis le filesystem
                // Normaliser : '/' devient '' pour la racine
                $normalizedPath = ($folderPath === '/' || $folderPath === '') ? '' : $folderPath;
                $fsContent = $fsReader->readDirectory($normalizedPath, false);
                
                // Définir currentFolder pour le template
                $currentFolder = $normalizedPath;
                
                // Debug: logger si aucun fichier trouvé
                if (empty($fsContent['files']) && empty($fsContent['error'])) {
                    error_log("Dossier '$normalizedPath' (hash: $folderId) trouvé mais vide");
                }
                
                // Mapper les fichiers du filesystem avec les documents en DB
                $documents = [];
                foreach ($fsContent['files'] as $file) {
                    // Chercher le document correspondant en DB
                    // Le relative_path peut être stocké avec ou sans le nom du fichier
                    // Essayer d'abord avec le chemin complet, puis avec juste le nom du fichier
                    $docStmt = $db->prepare("
                        SELECT d.*, dt.label as document_type_label, c.name as correspondent_name
                        FROM documents d
                        LEFT JOIN document_types dt ON d.document_type_id = dt.id
                        LEFT JOIN correspondents c ON d.correspondent_id = c.id
                        WHERE (d.relative_path = ? OR d.relative_path = ? OR d.filename = ?) 
                        AND d.deleted_at IS NULL
                        LIMIT 1
                    ");
                    $fileName = basename($file['path']);
                    $docStmt->execute([$file['path'], $fileName, $fileName]);
                    $doc = $docStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($doc) {
                        // Vérifier si le fichier a été modifié
                        if ($fsReader->checkFileModified($file['path'], $doc['checksum'] ?? null, $doc['file_modified_at'] ?? null)) {
                            $doc['_modified'] = true;
                            $doc['_file_info'] = $file;
                        }
                        $documents[] = $doc;
                    } else {
                        // Fichier non indexé en DB, créer une entrée minimale pour affichage
                        $documents[] = [
                            'id' => null,
                            'title' => pathinfo($file['name'], PATHINFO_FILENAME),
                            'filename' => $file['name'],
                            'original_filename' => $file['name'],
                            'file_path' => $file['full_path'],
                            'relative_path' => $file['path'],
                            'file_size' => $file['size'],
                            'mime_type' => $file['mime_type'],
                            'created_at' => date('Y-m-d H:i:s', $file['modified'] ?? time()),
                            '_not_indexed' => true,
                            '_file_info' => $file,
                        ];
                    }
                }
                
                // Appliquer la recherche si présente
                if (!empty($search)) {
                    $filtered = [];
                    foreach ($documents as $doc) {
                        if (stripos($doc['title'] ?? '', $search) !== false || 
                            stripos($doc['original_filename'] ?? '', $search) !== false ||
                            stripos($doc['filename'] ?? '', $search) !== false) {
                            $filtered[] = $doc;
                        }
                    }
                    $documents = $filtered;
                }
                
                $total = count($documents);
                // Pagination manuelle
                $documents = array_slice($documents, $offset, $limit);
                
                // Définir currentFolder pour le template (chemin normalisé)
                $currentFolder = $normalizedPath;
            } else {
                $documents = [];
                $total = 0;
                $currentFolder = null;
            }
        } else {
            // Vue par défaut : utiliser SearchService avec SearchQueryBuilder (nouveau)
            try {
                $searchService = new SearchService();
                $builder = SearchQueryBuilder::create();
                
                // Recherche texte
                if (!empty($search)) {
                    $builder->whereText($search);
                }
                
                // Filtres
                if ($typeId) {
                    $builder->whereDocumentType($typeId);
                }
                
                if ($correspondentId) {
                    $builder->whereCorrespondent($correspondentId);
                }
                
                if ($tagId) {
                    $builder->whereHasTag($tagId);
                }
                
                // Tri
                $sortField = match($sort) {
                    'title' => 'title',
                    'filename' => 'title', // Pas de champ filename dans SearchQuery, utiliser title
                    'document_date' => 'created_at',
                    'amount' => 'created_at', // Pas de champ amount dans SearchQuery
                    default => 'created_at',
                };
                $builder->orderBy($sortField, $order);
                
                // Pagination
                $builder->page($page, $limit);
                
                // Exécuter la recherche
                $searchQuery = $builder->build();
                $searchResult = $searchService->advancedSearch($searchQuery);
                
                $documents = $searchResult->documents;
                $total = $searchResult->total;
            } catch (\Exception $e) {
                // Fallback sur l'ancienne méthode si erreur
                error_log("SearchService error: " . $e->getMessage());
                
                $where = ['d.deleted_at IS NULL'];
                $params = [];
                
                if (!empty($search)) {
                    $where[] = "(d.title LIKE ? OR d.original_filename LIKE ? OR d.filename LIKE ? OR d.ocr_text LIKE ? OR d.content LIKE ?)";
                    $searchParam = '%' . $search . '%';
                    $params = array_fill(0, 5, $searchParam);
                }
                
                if ($typeId) {
                    $where[] = "d.document_type_id = ?";
                    $params[] = $typeId;
                }
                
                if ($correspondentId) {
                    $where[] = "d.correspondent_id = ?";
                    $params[] = $correspondentId;
                }
                
                if ($tagId) {
                    $where[] = "EXISTS (SELECT 1 FROM document_tags dt WHERE dt.document_id = d.id AND dt.tag_id = ?)";
                    $params[] = $tagId;
                }
                
                $whereClause = 'WHERE ' . implode(' AND ', $where);
                $orderBy = "d.$sort";
                if ($sort === 'title') {
                    $orderBy = "COALESCE(d.title, d.original_filename, d.filename)";
                } elseif ($sort === 'filename') {
                    $orderBy = "COALESCE(d.original_filename, d.filename)";
                }
                
                $sql = "
                    SELECT d.*, 
                           dt.label as document_type_label,
                           c.name as correspondent_name
                    FROM documents d
                    LEFT JOIN document_types dt ON d.document_type_id = dt.id
                    LEFT JOIN correspondents c ON d.correspondent_id = c.id
                    $whereClause
                    ORDER BY $orderBy $order
                    LIMIT ? OFFSET ?
                ";
                
                $stmt = $db->prepare($sql);
                $bindIndex = 1;
                foreach ($params as $value) {
                    $stmt->bindValue($bindIndex++, $value);
                }
                $stmt->bindValue($bindIndex++, $limit, PDO::PARAM_INT);
                $stmt->bindValue($bindIndex++, $offset, PDO::PARAM_INT);
                $stmt->execute();
                $documents = $stmt->fetchAll();
                
                $countSql = "SELECT COUNT(*) FROM documents d $whereClause";
                $countStmt = $db->prepare($countSql);
                $countParamIndex = 1;
                foreach ($params as $value) {
                    $countStmt->bindValue($countParamIndex++, $value);
                }
                $countStmt->execute();
                $total = (int)$countStmt->fetchColumn();
            }
        }
        
        $totalPages = ceil($total / $limit);
        
        // Récupérer les dossiers logiques pour la sidebar
        $logicalFolders = LogicalFolder::getAll();
        
        // Les dossiers filesystem sont maintenant chargés dynamiquement via AJAX
        // On garde juste le chemin du dossier actuel pour le template
        $fsFolders = [];
        $currentFolderPath = null;
        
        // Si un dossier est sélectionné, trouver son chemin pour le template
        if ($folderId) {
            try {
                $fsReader = new FilesystemReader();
                
                // Construire la liste de tous les dossiers pour trouver le chemin
                $allFolderPaths = [];
                $readAllFolders = function($relativePath = '') use (&$readAllFolders, $fsReader, &$allFolderPaths) {
                    $content = $fsReader->readDirectory($relativePath, false);
                    foreach ($content['folders'] as $folder) {
                        $folderPath = $relativePath ? $relativePath . '/' . $folder['name'] : $folder['name'];
                        $allFolderPaths[] = $folderPath;
                        $readAllFolders($folderPath);
                    }
                };
                $allFolderPaths[] = '/';
                $allFolderPaths[] = '';
                $readAllFolders();
                
                // Trouver le chemin correspondant au hash
                foreach ($allFolderPaths as $path) {
                    $normalizedForHash = ($path === '' || $path === '/') ? '/' : $path;
                    $pathHash = md5($normalizedForHash);
                    if ($pathHash === $folderId) {
                        $currentFolderPath = ($path === '' || $path === '/') ? '' : $path;
                        break;
                    }
                }
            } catch (\Exception $e) {
                error_log("Erreur récupération chemin dossier: " . $e->getMessage());
            }
        }
        
        // Récupérer les types de documents pour le filtre
        $documentTypes = [];
        try {
            $documentTypes = $db->query("SELECT id, code, label FROM document_types ORDER BY label")->fetchAll();
        } catch (\Exception $e) {}
        
        // Récupérer les tags (si table existe)
        $tags = [];
        try {
            $tags = $db->query("SELECT id, name, color FROM tags ORDER BY name LIMIT 20")->fetchAll();
        } catch (\Exception $e) {}
        
        // Récupérer les correspondants pour la sidebar (Priorité 1.4)
        $correspondents = [];
        try {
            $corrStmt = $db->query("
                SELECT c.id, c.name, COUNT(d.id) as doc_count
                FROM correspondents c
                LEFT JOIN documents d ON c.id = d.correspondent_id AND d.deleted_at IS NULL
                GROUP BY c.id, c.name
                HAVING doc_count > 0
                ORDER BY c.name
                LIMIT 20
            ");
            $correspondents = $corrStmt->fetchAll();
        } catch (\Exception $e) {}
        
        // Récupérer les Storage Paths (Phase 2.2)
        $storagePaths = [];
        try {
            $storagePaths = \KDocs\Models\StoragePath::all();
        } catch (\Exception $e) {
            // Table storage_paths n'existe pas encore
        }
        
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentsController::index', 'Before template render', [
            'documentsCount' => count($documents),
            'total' => $total,
            'templatePath' => __DIR__ . '/../../templates/documents/index.php',
            'templateExists' => file_exists(__DIR__ . '/../../templates/documents/index.php')
        ], 'C');
        // #endregion
        
        // Utiliser le template principal
        $templateFile = __DIR__ . '/../../templates/documents/index.php';
        $content = $this->renderTemplate($templateFile, [
            'documents' => $documents,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'search' => $search ?? '',
            'logicalFolderId' => $logicalFolderId,
            'folderId' => $folderId,
            'typeId' => $typeId,
            'sort' => $sort,
            'order' => $order,
            'logicalFolders' => $logicalFolders,
            'fsFolders' => $fsFolders,
            'documentTypes' => $documentTypes,
            'tags' => $tags,
            'correspondents' => $correspondents,
            'correspondentId' => $correspondentId,
            'tagId' => $tagId,
            'currentFolder' => $logicalFolderId ?? ($folderId ? true : null),
            'currentFolderPath' => $currentFolderPath ?? (isset($currentFolder) ? $currentFolder : null),
            'storagePaths' => $storagePaths ?? [],
        ]);
        
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentsController::index', 'After template render', [
            'contentLength' => strlen($content)
        ], 'C');
        // #endregion
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Documents - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Documents'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Page d'upload
     */
    public function showUpload(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentsController::showUpload', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        
        // Récupérer les types de documents et correspondants pour les selects
        $db = Database::getInstance();
        $documentTypes = $db->query("SELECT id, code, label FROM document_types ORDER BY label")->fetchAll();
        $correspondents = $db->query("SELECT id, name FROM correspondents ORDER BY name")->fetchAll();
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/documents/upload.php', [
            'documentTypes' => $documentTypes,
            'correspondents' => $correspondents,
            'error' => null,
            'success' => null,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Uploader un document - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Uploader un document'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Traitement de l'upload
     */
    public function upload(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentsController::upload', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $basePath = Config::basePath();
        
        $uploadedFiles = $request->getUploadedFiles();
        $data = $request->getParsedBody();
        
        if (empty($uploadedFiles['file']) || $uploadedFiles['file']->getError() !== UPLOAD_ERR_OK) {
            $db = Database::getInstance();
            $documentTypes = $db->query("SELECT id, code, label FROM document_types ORDER BY label")->fetchAll();
            $correspondents = $db->query("SELECT id, name FROM correspondents ORDER BY name")->fetchAll();
            
            $content = $this->renderTemplate(__DIR__ . '/../../templates/documents/upload.php', [
                'documentTypes' => $documentTypes,
                'correspondents' => $correspondents,
                'error' => 'Aucun fichier sélectionné ou erreur lors de l\'upload',
                'success' => null,
            ]);
            
            $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
                'title' => 'Uploader un document - K-Docs',
                'content' => $content,
                'user' => $user,
                'pageTitle' => 'Uploader un document'
            ]);
            
            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
        
        $file = $uploadedFiles['file'];
        $storagePath = Config::get('storage.documents');
        
        // Créer le dossier s'il n'existe pas
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }
        
        // Générer un nom de fichier unique
        $originalFilename = $file->getClientFilename();
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $filename = uniqid() . '.' . $extension;
        $filePath = $storagePath . '/' . $filename;
        
        // Déplacer le fichier
        $file->moveTo($filePath);
        
        // Créer l'entrée en base de données
        try {
            $documentId = Document::create([
                'title' => $data['title'] ?? null,
                'filename' => $filename,
                'original_filename' => $originalFilename,
                'file_path' => $filePath,
                'file_size' => filesize($filePath),
                'mime_type' => $file->getClientMediaType(),
                'document_type' => !empty($data['document_type']) ? (int)$data['document_type'] : null,
                'correspondent_id' => !empty($data['correspondent_id']) ? (int)$data['correspondent_id'] : null,
                'doc_date' => !empty($data['doc_date']) ? $data['doc_date'] : null,
                'amount' => !empty($data['amount']) ? (float)$data['amount'] : null,
                'currency' => $data['currency'] ?? 'CHF',
                'created_by' => $user['id'],
            ]);
            
            // Enregistrer dans l'audit log
            try {
                $document = Document::findById($documentId);
                if ($document) {
                    AuditService::logCreate('document', $documentId, $document['title'] ?? $document['original_filename'], $user['id']);
                }
            } catch (\Exception $e) {
                error_log("Erreur audit log document.created: " . $e->getMessage());
            }
            
            // Déclencher webhook document.created
            try {
                $webhookService = new WebhookService();
                $document = Document::findById($documentId);
                if ($document) {
                    $webhookService->trigger('document.created', [
                        'id' => $documentId,
                        'title' => $document['title'] ?? $document['original_filename'],
                        'filename' => $document['filename'],
                        'original_filename' => $document['original_filename'],
                        'file_size' => $document['file_size'],
                        'mime_type' => $document['mime_type'],
                        'created_at' => $document['created_at'],
                    ]);
                }
            } catch (\Exception $e) {
                // Ne pas bloquer l'upload si le webhook échoue
                error_log("Erreur webhook document.created: " . $e->getMessage());
            }
            
            // Rediriger vers la liste avec message de succès
            return $response
                ->withHeader('Location', $basePath . '/documents?success=1')
                ->withStatus(302);
                
        } catch (\Exception $e) {
            // En cas d'erreur, supprimer le fichier et afficher l'erreur
            @unlink($filePath);
            
            $db = Database::getInstance();
            $documentTypes = $db->query("SELECT id, code, label FROM document_types ORDER BY label")->fetchAll();
            $correspondents = $db->query("SELECT id, name FROM correspondents ORDER BY name")->fetchAll();
            
            $content = $this->renderTemplate(__DIR__ . '/../../templates/documents/upload.php', [
                'documentTypes' => $documentTypes,
                'correspondents' => $correspondents,
                'error' => 'Erreur lors de l\'enregistrement : ' . $e->getMessage(),
                'success' => null,
            ]);
            
            $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
                'title' => 'Uploader un document - K-Docs',
                'content' => $content,
                'user' => $user,
                'pageTitle' => 'Uploader un document'
            ]);
            
            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
    }

    /**
     * Affiche les détails d'un document
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentsController::show', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $id = (int)$args['id'];
        
        $db = Database::getInstance();
        
        // Récupérer le document avec storage path (Phase 2.2)
        $stmt = $db->prepare("
            SELECT d.*, 
                   dt.label as document_type_label,
                   c.name as correspondent_name,
                   u.username as created_by_username,
                   sp.name as storage_path_name,
                   sp.path as storage_path_path
            FROM documents d
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            LEFT JOIN users u ON d.created_by = u.id
            LEFT JOIN storage_paths sp ON d.storage_path_id = sp.id
            WHERE d.id = ? AND d.deleted_at IS NULL
        ");
        $stmt->execute([$id]);
        $document = $stmt->fetch();
        
        if (!$document) {
            $basePath = Config::basePath();
            return $response
                ->withHeader('Location', $basePath . '/documents')
                ->withStatus(302);
        }
        
        // Récupérer les tags du document
        $tags = [];
        try {
            $tagStmt = $db->prepare("SELECT t.id, t.name, t.color FROM tags t INNER JOIN document_tags dt ON t.id = dt.tag_id WHERE dt.document_id = ?");
            $tagStmt->execute([$id]);
            $tags = $tagStmt->fetchAll();
        } catch (\Exception $e) {
            // Table tags n'existe pas encore
        }
        
        // Récupérer les notes du document (Phase 2.4)
        $notes = [];
        try {
            $notes = \KDocs\Models\DocumentNote::allForDocument($id);
        } catch (\Exception $e) {
            // Table document_notes n'existe pas encore
        }
        
        // Récupérer les correspondants, types, storage paths pour les formulaires
        $correspondents = [];
        $documentTypes = [];
        $storagePaths = [];
        $allTags = [];
        try {
            $correspondents = $db->query("SELECT id, name FROM correspondents ORDER BY name")->fetchAll();
            $documentTypes = $db->query("SELECT id, code, label FROM document_types ORDER BY label")->fetchAll();
            $storagePaths = $db->query("SELECT id, name, path FROM storage_paths ORDER BY name")->fetchAll();
            $allTags = $db->query("SELECT id, name, color FROM tags ORDER BY name")->fetchAll();
        } catch (\Exception $e) {}
        
        // Récupérer les IDs précédent/suivant pour la navigation
        $previousId = null;
        $nextId = null;
        try {
            // Document précédent (créé avant celui-ci)
            $prevStmt = $db->prepare("SELECT id FROM documents WHERE deleted_at IS NULL AND created_at < ? ORDER BY created_at DESC LIMIT 1");
            $prevStmt->execute([$document['created_at']]);
            $prevDoc = $prevStmt->fetch();
            $previousId = $prevDoc ? $prevDoc['id'] : null;
            
            // Document suivant (créé après celui-ci)
            $nextStmt = $db->prepare("SELECT id FROM documents WHERE deleted_at IS NULL AND created_at > ? ORDER BY created_at ASC LIMIT 1");
            $nextStmt->execute([$document['created_at']]);
            $nextDoc = $nextStmt->fetch();
            $nextId = $nextDoc ? $nextDoc['id'] : null;
        } catch (\Exception $e) {}
        
        // Vérifier si la classification IA est disponible (Bonus)
        $aiClassifier = new \KDocs\Services\AIClassifierService();
        $aiAvailable = $aiClassifier->isAvailable();
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/documents/show.php', [
            'document' => $document,
            'tags' => $tags,
            'notes' => $notes,
            'documentId' => $id,
            'aiClassifier' => $aiClassifier,
            'aiAvailable' => $aiAvailable,
            'correspondents' => $correspondents,
            'documentTypes' => $documentTypes,
            'storagePaths' => $storagePaths,
            'allTags' => $allTags,
            'previousId' => $previousId,
            'nextId' => $nextId,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => htmlspecialchars($document['title'] ?: $document['original_filename']) . ' - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Détails du document'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Télécharge un document
     */
    public function download(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentsController::download', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $id = (int)$args['id'];
        
        $document = Document::findById($id);
        
        if (!$document || !file_exists($document['file_path'])) {
            $basePath = Config::basePath();
            return $response
                ->withHeader('Location', $basePath . '/documents')
                ->withStatus(302);
        }
        
        $file = fopen($document['file_path'], 'rb');
        $stream = new \Slim\Psr7\Stream($file);
        
        return $response
            ->withHeader('Content-Type', $document['mime_type'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $document['original_filename'] . '"')
            ->withHeader('Content-Length', (string)$document['file_size'])
            ->withBody($stream);
    }

    /**
     * Affiche un document dans le navigateur (pour PDF/images)
     */
    public function view(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentsController::view', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $id = (int)$args['id'];
        
        $document = Document::findById($id);
        
        if (!$document || !file_exists($document['file_path'])) {
            $basePath = Config::basePath();
            return $response
                ->withHeader('Location', $basePath . '/documents')
                ->withStatus(302);
        }
        
        $file = fopen($document['file_path'], 'rb');
        $stream = new \Slim\Psr7\Stream($file);
        
        return $response
            ->withHeader('Content-Type', $document['mime_type'])
            ->withHeader('Content-Disposition', 'inline; filename="' . $document['original_filename'] . '"')
            ->withHeader('Content-Length', (string)$document['file_size'])
            ->withBody($stream);
    }

    /**
     * Affiche une miniature de document
     */
    public function thumbnail(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = (int)$args['id'];
        
        $document = Document::findById($id);
        
        if (!$document) {
            return $response->withStatus(404);
        }
        
        $config = Config::load();
        $thumbBasePath = $config['storage']['thumbnails'] ?? __DIR__ . '/../../storage/thumbnails';
        
        // thumbnail_path contient juste le nom du fichier (ex: "123_thumb.png")
        $thumbnailFilename = $document['thumbnail_path'] ?? null;
        
        if (!$thumbnailFilename) {
            return $response->withStatus(404);
        }
        
        $thumbnailPath = $thumbBasePath . DIRECTORY_SEPARATOR . basename($thumbnailFilename);
        
        if (!file_exists($thumbnailPath)) {
            return $response->withStatus(404);
        }
        
        $file = fopen($thumbnailPath, 'rb');
        $stream = new \Slim\Psr7\Stream($file);
        
        return $response
            ->withHeader('Content-Type', 'image/png')
            ->withHeader('Cache-Control', 'public, max-age=31536000')
            ->withBody($stream);
    }

    /**
     * Affiche le formulaire d'édition (Priorité 1.2)
     */
    public function showEdit(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentsController::showEdit', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $id = (int)$args['id'];
        
        $document = Document::findById($id);
        if (!$document) {
            $basePath = Config::basePath();
            return $response
                ->withHeader('Location', $basePath . '/documents')
                ->withStatus(302);
        }
        
        $db = Database::getInstance();
        $documentTypes = $db->query("SELECT id, code, label FROM document_types ORDER BY label")->fetchAll();
        $correspondents = $db->query("SELECT id, name FROM correspondents ORDER BY name")->fetchAll();
        
        // Récupérer les tags du document
        $tags = [];
        try {
            $tagStmt = $db->prepare("SELECT t.id, t.name, t.color FROM tags t INNER JOIN document_tags dt ON t.id = dt.tag_id WHERE dt.document_id = ?");
            $tagStmt->execute([$id]);
            $tags = $tagStmt->fetchAll();
        } catch (\Exception $e) {}
        
        // Tous les tags disponibles
        $allTags = [];
        try {
            $allTags = $db->query("SELECT id, name, color FROM tags ORDER BY name")->fetchAll();
        } catch (\Exception $e) {}
        
        // Récupérer les Custom Fields (Phase 2.1)
        $customFields = [];
        try {
            $customFields = \KDocs\Models\CustomField::all();
            $customFieldValues = \KDocs\Models\CustomField::getValuesForDocument($id);
            // Créer un tableau associatif field_id => value
            $customFieldValuesMap = [];
            foreach ($customFieldValues as $cfv) {
                $customFieldValuesMap[$cfv['custom_field_id']] = $cfv['value'];
            }
        } catch (\Exception $e) {
            // Table custom_fields n'existe pas encore
        }
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/documents/edit.php', [
            'document' => $document,
            'documentTypes' => $documentTypes,
            'correspondents' => $correspondents,
            'tags' => $tags,
            'allTags' => $allTags,
            'customFields' => $customFields ?? [],
            'customFieldValues' => $customFieldValuesMap ?? [],
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Modifier le document - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Modifier le document'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Traite l'édition d'un document (Priorité 1.2)
     */
    public function edit(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentsController::edit', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $id = (int)$args['id'];
        $basePath = Config::basePath();
        
        $document = Document::findById($id);
        if (!$document) {
            return $response
                ->withHeader('Location', $basePath . '/documents')
                ->withStatus(302);
        }
        
        $data = $request->getParsedBody();
        $db = Database::getInstance();
        
        try {
            $db->beginTransaction();
            
            // Enregistrer l'historique avant modification (Priorité 3.4)
            $oldDocument = Document::findById($id);
            
            // Mettre à jour les métadonnées
            // Gérer ASN (Phase 2.3) - générer automatiquement si vide
            $asn = null;
            if (!empty($data['asn'])) {
                $asn = (int)$data['asn'];
            } else {
                // Générer automatiquement le prochain ASN
                $asnStmt = $db->query("SELECT MAX(asn) FROM documents WHERE asn IS NOT NULL");
                $maxAsn = $asnStmt->fetchColumn();
                $asn = $maxAsn ? (int)$maxAsn + 1 : 1;
            }
            
            $updateStmt = $db->prepare("
                UPDATE documents SET
                    asn = ?,
                    title = ?,
                    document_type_id = ?,
                    correspondent_id = ?,
                    document_date = ?,
                    amount = ?,
                    currency = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([
                $asn,
                $data['title'] ?? null,
                !empty($data['document_type_id']) ? (int)$data['document_type_id'] : null,
                !empty($data['correspondent_id']) ? (int)$data['correspondent_id'] : null,
                !empty($data['document_date']) ? $data['document_date'] : null,
                !empty($data['amount']) ? (float)$data['amount'] : null,
                $data['currency'] ?? 'CHF',
                $id
            ]);
            
            // Gérer les tags
            if (isset($data['tags']) && is_array($data['tags'])) {
                // Supprimer les tags existants
                $db->prepare("DELETE FROM document_tags WHERE document_id = ?")->execute([$id]);
                
                // Ajouter les nouveaux tags
                foreach ($data['tags'] as $tagId) {
                    $tagId = (int)$tagId;
                    if ($tagId > 0) {
                        $db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)")->execute([$id, $tagId]);
                    }
                }
            }
            
            // Gérer les Custom Fields (Phase 2.1)
            if (isset($data['custom_fields']) && is_array($data['custom_fields'])) {
                foreach ($data['custom_fields'] as $fieldId => $value) {
                    $fieldId = (int)$fieldId;
                    if ($fieldId > 0) {
                        if ($value === '' || $value === null) {
                            // Supprimer la valeur si vide
                            \KDocs\Models\CustomField::deleteValue($id, $fieldId);
                        } else {
                            // Enregistrer ou mettre à jour la valeur
                            \KDocs\Models\CustomField::setValue($id, $fieldId, $value);
                        }
                    }
                }
            }
            
            // Enregistrer l'historique des modifications (Priorité 3.4)
            try {
                $historyStmt = $db->prepare("
                    INSERT INTO document_history (document_id, user_id, action, field_name, old_value, new_value, created_at)
                    VALUES (?, ?, 'update', ?, ?, ?, NOW())
                ");
                
                // Enregistrer chaque champ modifié
                $fields = ['title', 'document_type_id', 'correspondent_id', 'document_date', 'amount', 'currency'];
                foreach ($fields as $field) {
                    $oldValue = $oldDocument[$field] ?? null;
                    $newValue = $data[$field] ?? null;
                    
                    if ($oldValue != $newValue) {
                        $historyStmt->execute([
                            $id,
                            $user['id'],
                            $field,
                            $oldValue ? (string)$oldValue : null,
                            $newValue ? (string)$newValue : null
                        ]);
                    }
                }
            } catch (\Exception $e) {
                // Table n'existe pas encore, continuer sans historique
            }
            
            $db->commit();
            
            // Enregistrer dans l'audit log
            try {
                $updatedDocument = Document::findById($id);
                if ($updatedDocument) {
                    $changes = [];
                    foreach (['title', 'document_type_id', 'correspondent_id', 'document_date', 'amount', 'currency'] as $field) {
                        if (isset($data[$field]) && ($oldDocument[$field] ?? null) != ($data[$field] ?? null)) {
                            $changes[$field] = [
                                'old' => $oldDocument[$field] ?? null,
                                'new' => $data[$field] ?? null
                            ];
                        }
                    }
                    AuditService::logUpdate('document', $id, $updatedDocument['title'] ?? $updatedDocument['original_filename'], $changes, $user['id']);
                }
            } catch (\Exception $e) {
                error_log("Erreur audit log document.updated: " . $e->getMessage());
            }
            
            // Déclencher webhook document.updated
            try {
                $webhookService = new WebhookService();
                $updatedDocument = Document::findById($id);
                if ($updatedDocument) {
                    $webhookService->trigger('document.updated', [
                        'id' => $id,
                        'title' => $updatedDocument['title'] ?? $updatedDocument['original_filename'],
                        'document_type_id' => $updatedDocument['document_type_id'],
                        'correspondent_id' => $updatedDocument['correspondent_id'],
                        'updated_at' => $updatedDocument['updated_at'],
                    ]);
                }
            } catch (\Exception $e) {
                // Ne pas bloquer l'édition si le webhook échoue
                error_log("Erreur webhook document.updated: " . $e->getMessage());
            }
            
            return $response
                ->withHeader('Location', $basePath . '/documents/' . $id . '?success=1')
                ->withStatus(302);
                
        } catch (\Exception $e) {
            $db->rollBack();
            // En cas d'erreur, retourner au formulaire
            $documentTypes = $db->query("SELECT id, code, label FROM document_types ORDER BY label")->fetchAll();
            $correspondents = $db->query("SELECT id, name FROM correspondents ORDER BY name")->fetchAll();
            
            $content = $this->renderTemplate(__DIR__ . '/../../templates/documents/edit.php', [
                'document' => $document,
                'documentTypes' => $documentTypes,
                'correspondents' => $correspondents,
                'error' => 'Erreur lors de la modification : ' . $e->getMessage(),
            ]);
            
            $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
                'title' => 'Modifier le document - K-Docs',
                'content' => $content,
                'user' => $user,
                'pageTitle' => 'Modifier le document'
            ]);
            
            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
    }

    /**
     * Supprime un document (déplace dans le trash)
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentsController::delete', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $id = (int)$args['id'];
        $basePath = Config::basePath();
        
        $trash = new TrashService();
        if ($trash->moveToTrash($id, $user['id'])) {
            // Enregistrer dans l'audit log
            try {
                $document = Document::findById($id);
                if ($document) {
                    AuditService::logDelete('document', $id, $document['title'] ?? $document['original_filename'], $user['id']);
                }
            } catch (\Exception $e) {
                error_log("Erreur audit log document.deleted: " . $e->getMessage());
            }
            
            // Déclencher webhook document.deleted
            try {
                $webhookService = new WebhookService();
                $document = Document::findById($id);
                if ($document) {
                    $webhookService->trigger('document.deleted', [
                        'id' => $id,
                        'title' => $document['title'] ?? $document['original_filename'],
                        'deleted_at' => date('c'),
                    ]);
                }
            } catch (\Exception $e) {
                // Ne pas bloquer la suppression si le webhook échoue
                error_log("Erreur webhook document.deleted: " . $e->getMessage());
            }
            
            return $response
                ->withHeader('Location', $basePath . '/documents?deleted=1')
                ->withStatus(302);
        }
        
        return $response
            ->withHeader('Location', $basePath . '/documents?error=delete_failed')
            ->withStatus(302);
    }

    /**
     * Restaure un document depuis le trash
     */
    public function restore(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentsController::restore', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $id = (int)$args['id'];
        $basePath = Config::basePath();
        
        $trash = new TrashService();
        if ($trash->restoreFromTrash($id)) {
            // Enregistrer dans l'audit log
            try {
                $document = Document::findById($id);
                if ($document) {
                    AuditService::logRestore('document', $id, $document['title'] ?? $document['original_filename'], $user['id']);
                }
            } catch (\Exception $e) {
                error_log("Erreur audit log document.restored: " . $e->getMessage());
            }
            
            // Déclencher webhook document.restored
            try {
                $webhookService = new WebhookService();
                $document = Document::findById($id);
                if ($document) {
                    $webhookService->trigger('document.restored', [
                        'id' => $id,
                        'title' => $document['title'] ?? $document['original_filename'],
                        'restored_at' => date('c'),
                    ]);
                }
            } catch (\Exception $e) {
                // Ne pas bloquer la restauration si le webhook échoue
                error_log("Erreur webhook document.restored: " . $e->getMessage());
            }
            
            return $response
                ->withHeader('Location', $basePath . '/documents?restored=1')
                ->withStatus(302);
        }
        
        return $response
            ->withHeader('Location', $basePath . '/documents?error=restore_failed')
            ->withStatus(302);
    }

    /**
     * Actions groupées sur plusieurs documents (Priorité 1.3)
     */
    public function bulkAction(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentsController::bulkAction', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();
        $action = $data['action'] ?? '';
        $documentIds = $data['document_ids'] ?? [];
        
        if (empty($documentIds) || !is_array($documentIds)) {
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(400)
                ->getBody()->write(json_encode(['success' => false, 'error' => 'Aucun document sélectionné']));
        }
        
        $db = Database::getInstance();
        $results = ['success' => 0, 'errors' => 0];
        
        try {
            $db->beginTransaction();
            
            foreach ($documentIds as $docId) {
                $docId = (int)$docId;
                if ($docId <= 0) continue;
                
                try {
                    switch ($action) {
                        case 'delete':
                            $trash = new TrashService();
                            if ($trash->moveToTrash($docId, $user['id'])) {
                                $results['success']++;
                            } else {
                                $results['errors']++;
                            }
                            break;
                            
                        case 'add_tag':
                            if (!empty($data['tag_id'])) {
                                $db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)")->execute([$docId, (int)$data['tag_id']]);
                                $results['success']++;
                            }
                            break;
                            
                        case 'remove_tag':
                            if (!empty($data['tag_id'])) {
                                $db->prepare("DELETE FROM document_tags WHERE document_id = ? AND tag_id = ?")->execute([$docId, (int)$data['tag_id']]);
                                $results['success']++;
                            }
                            break;
                            
                        case 'set_type':
                            if (!empty($data['document_type_id'])) {
                                $db->prepare("UPDATE documents SET document_type_id = ? WHERE id = ?")->execute([(int)$data['document_type_id'], $docId]);
                                $results['success']++;
                            }
                            break;
                            
                        case 'set_correspondent':
                            if (!empty($data['correspondent_id'])) {
                                $db->prepare("UPDATE documents SET correspondent_id = ? WHERE id = ?")->execute([(int)$data['correspondent_id'], $docId]);
                                $results['success']++;
                            }
                            break;
                    }
                } catch (\Exception $e) {
                    $results['errors']++;
                }
            }
            
            $db->commit();
            
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->getBody()->write(json_encode([
                    'success' => true,
                    'results' => $results
                ]));
                
        } catch (\Exception $e) {
            $db->rollBack();
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500)
                ->getBody()->write(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    /**
     * API: Scanner le filesystem pour mapping (savoir où est chaque document)
     */
    public function scanFilesystem(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentsController::scanFilesystem', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        try {
            $mapper = new \KDocs\Services\DocumentMapper();
            $stats = $mapper->scanForMapping();
            
            if (isset($stats['error'])) {
                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withStatus(500)
                    ->getBody()->write(json_encode(['success' => false, 'error' => $stats['error']]));
            }
            
            // Générer les thumbnails pour les nouveaux documents
            $db = Database::getInstance();
            $newDocs = $db->query("SELECT id, file_path, mime_type FROM documents WHERE thumbnail_path IS NULL AND mime_type LIKE 'image/%' LIMIT 20")->fetchAll();
            $thumbnailsGenerated = 0;
            
            if (!empty($newDocs)) {
                $thumbnailGenerator = new ThumbnailGenerator();
                foreach ($newDocs as $doc) {
                    try {
                        $thumbnailPath = $thumbnailGenerator->generate($doc['file_path'], $doc['id']);
                        if ($thumbnailPath) {
                            $db->prepare("UPDATE documents SET thumbnail_path = ? WHERE id = ?")->execute([$thumbnailPath, $doc['id']]);
                            $thumbnailsGenerated++;
                        }
                    } catch (\Exception $e) {
                        // Ignorer les erreurs de génération de thumbnails
                    }
                }
            }
            
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->getBody()->write(json_encode([
                    'success' => true,
                    'stats' => $stats,
                    'thumbnails_generated' => $thumbnailsGenerated
                ]));
                
        } catch (\Exception $e) {
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500)
                ->getBody()->write(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    /**
     * API: Upload multiple de documents
     */
    public function apiUpload(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentsController::apiUpload', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $uploadedFiles = $request->getUploadedFiles();
        $results = [];
        
        if (empty($uploadedFiles['files']) && empty($uploadedFiles['files[]'])) {
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(400)
                ->getBody()->write(json_encode(['success' => false, 'error' => 'Aucun fichier fourni']));
        }
        
        $files = $uploadedFiles['files'] ?? $uploadedFiles['files[]'] ?? [];
        if (!is_array($files)) {
            $files = [$files];
        }
        
        $storagePath = Config::get('storage.documents');
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }
        
        foreach ($files as $file) {
            if ($file->getError() !== UPLOAD_ERR_OK) {
                $results[] = ['success' => false, 'filename' => $file->getClientFilename(), 'error' => 'Erreur upload'];
                continue;
            }
            
            try {
                $originalFilename = $file->getClientFilename();
                $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
                $filename = uniqid() . '.' . $extension;
                $filePath = $storagePath . '/' . $filename;
                
                $file->moveTo($filePath);
                
                $documentId = Document::create([
                    'title' => pathinfo($originalFilename, PATHINFO_FILENAME),
                    'filename' => $filename,
                    'original_filename' => $originalFilename,
                    'file_path' => $filePath,
                    'file_size' => filesize($filePath),
                    'mime_type' => $file->getClientMediaType(),
                    'created_by' => $user['id'],
                ]);
                
                // Traiter le document en arrière-plan
                try {
                    $processor = new DocumentProcessor();
                    $processor->processDocument($documentId);
                } catch (\Exception $e) {
                    // Ignorer les erreurs de traitement, le document est quand même créé
                }
                
                $results[] = ['success' => true, 'filename' => $originalFilename, 'id' => $documentId];
                
            } catch (\Exception $e) {
                $results[] = ['success' => false, 'filename' => $file->getClientFilename(), 'error' => $e->getMessage()];
            }
        }
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->getBody()->write(json_encode([
                'success' => true,
                'results' => $results
            ]));
    }
    
    /**
     * Partage un document (Priorité 3.3)
     */
    public function share(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentsController::share', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $id = (int)$args['id'];
        
        $document = Document::findById($id);
        if (!$document) {
            $basePath = Config::basePath();
            return $response
                ->withHeader('Location', $basePath . '/documents')
                ->withStatus(302);
        }
        
        $db = Database::getInstance();
        $token = bin2hex(random_bytes(32));
        
        // Créer un partage public (sans utilisateur spécifique)
        try {
            $stmt = $db->prepare("
                INSERT INTO document_shares (document_id, shared_by, share_token, expires_at, can_view, can_download, created_at)
                VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), TRUE, TRUE, NOW())
            ");
            $stmt->execute([$id, $user['id'], $token]);
        } catch (\Exception $e) {
            // Table n'existe pas encore
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->getBody()
                ->write(json_encode([
                    'success' => false,
                    'error' => 'Fonctionnalité de partage non disponible'
                ]));
        }
        
        $shareUrl = Config::basePath() . '/shared/' . $token;
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->getBody()
            ->write(json_encode([
                'success' => true,
                'share_url' => $shareUrl,
                'token' => $token
            ]));
    }
    
    /**
     * Affiche l'historique des modifications (Priorité 3.4)
     */
    public function history(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentsController::history', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $id = (int)$args['id'];
        
        $document = Document::findById($id);
        if (!$document) {
            $basePath = Config::basePath();
            return $response
                ->withHeader('Location', $basePath . '/documents')
                ->withStatus(302);
        }
        
        $db = Database::getInstance();
        $history = [];
        
        // Ajouter la date de création comme première entrée d'historique
        $createdBy = $document['created_by_username'] ?? 'Inconnu';
        $history[] = [
            'id' => 0,
            'document_id' => $id,
            'user_id' => $document['created_by'] ?? null,
            'action' => 'created',
            'field_name' => 'Document créé',
            'old_value' => null,
            'new_value' => 'Document ajouté au système',
            'created_at' => $document['created_at'],
            'user_name' => $createdBy
        ];
        
        try {
            $stmt = $db->prepare("
                SELECT h.*, u.username as user_name
                FROM document_history h
                LEFT JOIN users u ON h.user_id = u.id
                WHERE h.document_id = ?
                ORDER BY h.created_at DESC
                LIMIT 50
            ");
            $stmt->execute([$id]);
            $dbHistory = $stmt->fetchAll();
            
            // Fusionner avec l'entrée de création et trier par date décroissante
            $history = array_merge($history, $dbHistory);
            usort($history, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });
        } catch (\Exception $e) {
            // Table n'existe pas encore, on garde juste l'entrée de création
        }
        
        // Si c'est une requête AJAX, retourner juste le HTML de l'historique
        if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest' || 
            strpos($request->getUri()->getPath(), '/history') !== false) {
            $content = $this->renderTemplate(__DIR__ . '/../../templates/documents/history_partial.php', [
                'document' => $document,
                'history' => $history,
            ]);
            $response->getBody()->write($content);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/documents/history.php', [
            'document' => $document,
            'history' => $history,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Historique - ' . htmlspecialchars($document['title'] ?: $document['original_filename']) . ' - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Historique des modifications'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
    
    /**
     * Liste les recherches sauvegardées (Priorité 3.2)
     */
    public function listSavedSearches(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentsController::listSavedSearches', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        try {
            $searches = SavedSearch::findByUser($user['id']);
        } catch (\Exception $e) {
            $searches = [];
        }
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->getBody()
            ->write(json_encode(['success' => true, 'searches' => $searches]));
    }
    
    /**
     * Sauvegarde une recherche (Priorité 3.2)
     */
    public function saveSearch(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentsController::saveSearch', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();
        
        try {
            $id = SavedSearch::create([
                'user_id' => $user['id'],
                'name' => $data['name'] ?? 'Recherche sans nom',
                'query' => $data['query'] ?? '',
                'filters' => $data['filters'] ?? []
            ]);
            
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->getBody()
                ->write(json_encode(['success' => true, 'id' => $id]));
        } catch (\Exception $e) {
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->getBody()
                ->write(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }
    
    /**
     * Supprime une recherche sauvegardée (Priorité 3.2)
     */
    public function deleteSavedSearch(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentsController::deleteSavedSearch', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $id = (int)$args['id'];
        
        try {
            $success = SavedSearch::delete($id, $user['id']);
        } catch (\Exception $e) {
            $success = false;
        }
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->getBody()
            ->write(json_encode(['success' => $success]));
    }
    
    /**
     * Liste les notes d'un document (API Phase 2.4)
     */
    public function listNotes(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentsController::listNotes', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $id = (int)$args['id'];
        $notes = \KDocs\Models\DocumentNote::allForDocument($id);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'notes' => $notes
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    /**
     * Ajoute une note à un document (API Phase 2.4)
     * Gère à la fois les requêtes JSON (API) et les formulaires web
     */
    public function addNote(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentsController::addNote', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $id = (int)$args['id'];
        $data = $request->getParsedBody();
        $user = $request->getAttribute('user');
        $basePath = Config::basePath();
        
        // Détecter si c'est une requête JSON (API) ou formulaire web
        $isJson = strpos($request->getHeaderLine('Content-Type'), 'application/json') !== false;
        
        try {
            // Récupérer le contenu de la note (API: 'note', Web: 'content')
            $noteContent = $data['note'] ?? $data['content'] ?? '';
            
            if (empty($noteContent)) {
                if ($isJson) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'error' => 'Le contenu de la note est requis'
                    ]));
                    return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
                } else {
                    // Redirection web avec message d'erreur
                    return $response
                        ->withHeader('Location', $basePath . '/documents/' . $id . '?error=note_empty')
                        ->withStatus(302);
                }
            }
            
            $noteId = \KDocs\Models\DocumentNote::create([
                'document_id' => $id,
                'user_id' => $user['id'] ?? null,
                'note' => $noteContent
            ]);
            
            if ($isJson) {
                // Réponse JSON pour API
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'note_id' => $noteId
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            } else {
                // Redirection web vers la page du document
                return $response
                    ->withHeader('Location', $basePath . '/documents/' . $id . '?note_added=1')
                    ->withStatus(302);
            }
        } catch (\Exception $e) {
            if ($isJson) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            } else {
                // Redirection web avec erreur
                return $response
                    ->withHeader('Location', $basePath . '/documents/' . $id . '?error=note_failed')
                    ->withStatus(302);
            }
        }
    }
    
    /**
     * Supprime une note (API Phase 2.4)
     * Gère à la fois les requêtes JSON (API) et les requêtes DELETE web
     */
    public function deleteNote(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentsController::deleteNote', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $noteId = (int)$args['noteId'];
        $documentId = (int)$args['id'];
        $basePath = Config::basePath();
        
        // Détecter si c'est une requête JSON (API) ou web
        $isJson = strpos($request->getHeaderLine('Content-Type'), 'application/json') !== false 
                  || strpos($request->getHeaderLine('Accept'), 'application/json') !== false;
        
        try {
            // Récupérer le document_id depuis la note avant suppression
            $note = \KDocs\Models\DocumentNote::find($noteId);
            if ($note) {
                $documentId = $note['document_id'];
            }
            
            \KDocs\Models\DocumentNote::delete($noteId);
            
            if ($isJson) {
                // Réponse JSON pour API
                $response->getBody()->write(json_encode([
                    'success' => true
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            } else {
                // Redirection web vers la page du document
                return $response
                    ->withHeader('Location', $basePath . '/documents/' . $documentId . '?note_deleted=1')
                    ->withStatus(302);
            }
        } catch (\Exception $e) {
            if ($isJson) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            } else {
                // Redirection web avec erreur
                return $response
                    ->withHeader('Location', $basePath . '/documents/' . $documentId . '?error=note_delete_failed')
                    ->withStatus(302);
            }
        }
    }
}

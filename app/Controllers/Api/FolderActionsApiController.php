<?php
/**
 * K-Docs - API Controller pour les opérations sur les dossiers
 * Renommer, déplacer, supprimer (vers trash), créer
 */

namespace KDocs\Controllers\Api;

use KDocs\Services\FolderService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class FolderActionsApiController
{
    private FolderService $folderService;
    
    public function __construct()
    {
        $this->folderService = new FolderService();
    }
    
    /**
     * Renommer un dossier
     * POST /api/folders/rename
     */
    public function rename(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        $path = $data['path'] ?? '';
        $newName = $data['new_name'] ?? '';
        
        if (empty($path) || empty($newName)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'Chemin et nouveau nom requis'
            ], 400);
        }
        
        // Récupérer l'utilisateur courant
        $userId = $_SESSION['user_id'] ?? 1;
        
        $result = $this->folderService->rename($path, $newName, $userId);
        
        return $this->jsonResponse($response, $result, $result['success'] ? 200 : 400);
    }
    
    /**
     * Déplacer un dossier
     * POST /api/folders/move
     */
    public function move(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        $sourcePath = $data['source_path'] ?? '';
        $targetPath = $data['target_path'] ?? '';
        
        if (empty($sourcePath)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'Chemin source requis'
            ], 400);
        }
        
        $userId = $_SESSION['user_id'] ?? 1;
        
        $result = $this->folderService->move($sourcePath, $targetPath, $userId);
        
        return $this->jsonResponse($response, $result, $result['success'] ? 200 : 400);
    }
    
    /**
     * Supprimer un dossier (vers trash)
     * POST /api/folders/delete
     */
    public function delete(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        $path = $data['path'] ?? '';
        
        if (empty($path)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'Chemin requis'
            ], 400);
        }
        
        $userId = $_SESSION['user_id'] ?? 1;
        
        $result = $this->folderService->moveToTrash($path, $userId);
        
        return $this->jsonResponse($response, $result, $result['success'] ? 200 : 400);
    }
    
    /**
     * Créer un nouveau dossier
     * POST /api/folders/create
     */
    public function create(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        $parentPath = $data['parent_path'] ?? '';
        $name = $data['name'] ?? '';
        
        if (empty($name)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'Nom du dossier requis'
            ], 400);
        }
        
        $userId = $_SESSION['user_id'] ?? 1;
        
        $result = $this->folderService->create($parentPath, $name, $userId);
        
        return $this->jsonResponse($response, $result, $result['success'] ? 200 : 400);
    }
    
    /**
     * Lister les dossiers disponibles (pour le sélecteur de destination)
     * GET /api/folders/list?exclude=path
     */
    public function listAll(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $excludePath = $queryParams['exclude'] ?? '';
        
        $folders = $this->folderService->getAllFolders($excludePath);
        
        return $this->jsonResponse($response, [
            'success' => true,
            'folders' => $folders
        ]);
    }
    
    /**
     * Lister les dossiers dans le trash
     * GET /api/folders/trash
     */
    public function listTrash(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $limit = (int)($queryParams['limit'] ?? 50);
        $offset = (int)($queryParams['offset'] ?? 0);
        
        $folders = $this->folderService->getTrashedFolders($limit, $offset);
        $total = $this->folderService->countTrashedFolders();
        
        return $this->jsonResponse($response, [
            'success' => true,
            'folders' => $folders,
            'total' => $total
        ]);
    }
    
    /**
     * Restaurer un dossier depuis le trash
     * POST /api/folders/restore
     */
    public function restore(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        $trashId = (int)($data['id'] ?? 0);
        
        if ($trashId <= 0) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'ID invalide'
            ], 400);
        }
        
        $userId = $_SESSION['user_id'] ?? 1;
        
        $result = $this->folderService->restoreFromTrash($trashId, $userId);
        
        return $this->jsonResponse($response, $result, $result['success'] ? 200 : 400);
    }
    
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}

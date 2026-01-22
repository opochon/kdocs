<?php
/**
 * K-Docs - API Controller pour les dossiers filesystem
 */

namespace KDocs\Controllers\Api;

use KDocs\Services\FilesystemReader;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class FoldersApiController
{
    /**
     * Récupère les sous-dossiers d'un dossier parent
     */
    public function getChildren(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $parentId = $queryParams['parent_id'] ?? null;
        
        if ($parentId === null) {
            // Retourner la racine
            $fsReader = new FilesystemReader();
            $content = $fsReader->readDirectory('', false);
            
            $folders = [];
            foreach ($content['folders'] as $folder) {
                $folderPath = $folder['name'];
                $normalizedPath = $folderPath;
                $pathId = md5($normalizedPath);
                
                // Vérifier si ce dossier a des sous-dossiers
                $subContent = $fsReader->readDirectory($normalizedPath, false);
                $hasChildren = !empty($subContent['folders']);
                
                $folders[] = [
                    'id' => $pathId,
                    'path' => $normalizedPath,
                    'name' => $folder['name'],
                    'file_count' => $folder['file_count'] ?? 0,
                    'has_children' => $hasChildren,
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
        
        // Trouver le chemin du dossier parent
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
        $parentPath = null;
        foreach ($allFolderPaths as $path) {
            $normalizedForHash = ($path === '' || $path === '/') ? '/' : $path;
            $pathHash = md5($normalizedForHash);
            if ($pathHash === $parentId) {
                $parentPath = ($path === '' || $path === '/') ? '' : $path;
                break;
            }
        }
        
        if ($parentPath === null) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Dossier parent introuvable'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }
        
        // Lire les sous-dossiers
        $content = $fsReader->readDirectory($parentPath, false);
        
        $folders = [];
        foreach ($content['folders'] as $folder) {
            $folderPath = $parentPath ? $parentPath . '/' . $folder['name'] : $folder['name'];
            $normalizedPath = $folderPath;
            $pathId = md5($normalizedPath);
            
            // Vérifier si ce dossier a des sous-dossiers
            $subContent = $fsReader->readDirectory($normalizedPath, false);
            $hasChildren = !empty($subContent['folders']);
            
            $folders[] = [
                'id' => $pathId,
                'path' => $normalizedPath,
                'name' => $folder['name'],
                'file_count' => $folder['file_count'] ?? 0,
                'has_children' => $hasChildren,
            ];
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
    }
}

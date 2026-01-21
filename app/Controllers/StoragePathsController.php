<?php
/**
 * K-Docs - Contrôleur Storage Paths (Phase 2.2)
 */

namespace KDocs\Controllers;

use KDocs\Models\StoragePath;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class StoragePathsController
{
    private function renderTemplate(string $templatePath, array $data = []): string
    {
        extract($data);
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }
    
    public function index(Request $request, Response $response): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('StoragePathsController::index', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $user = $request->getAttribute('user');
        $storagePaths = StoragePath::all();
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/storage_paths.php', [
            'storagePaths' => $storagePaths,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Chemins de stockage - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Chemins de stockage'
        ]);
        
        $response->getBody()->write($html);
        return $response;
    }
    
    public function showForm(Request $request, Response $response, array $args): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('StoragePathsController::showForm', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $user = $request->getAttribute('user');
        $id = $args['id'] ?? null;
        $storagePath = $id ? StoragePath::find((int)$id) : null;
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/storage_path_form.php', [
            'storagePath' => $storagePath,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => ($storagePath ? 'Modifier' : 'Créer') . ' chemin de stockage - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => ($storagePath ? 'Modifier' : 'Créer') . ' chemin de stockage'
        ]);
        
        $response->getBody()->write($html);
        return $response;
    }
    
    public function save(Request $request, Response $response): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('StoragePathsController::save', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $data = $request->getParsedBody();
        $id = $data['id'] ?? null;
        
        // Normaliser le chemin (enlever les slashes initiaux/finaux)
        if (isset($data['path'])) {
            $data['path'] = trim($data['path'], '/');
        }
        
        try {
            if ($id) {
                StoragePath::update((int)$id, $data);
            } else {
                StoragePath::create($data);
            }
            
            $basePath = \KDocs\Core\Config::basePath();
            return $response->withHeader('Location', $basePath . '/admin/storage-paths')->withStatus(302);
        } catch (\Exception $e) {
            $user = $request->getAttribute('user');
            $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/storage_path_form.php', [
                'storagePath' => $id ? StoragePath::find((int)$id) : null,
                'error' => 'Erreur : ' . $e->getMessage(),
            ]);
            
            $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
                'title' => 'Erreur - K-Docs',
                'content' => $content,
                'user' => $user,
            ]);
            
            $response->getBody()->write($html);
            return $response->withStatus(500);
        }
    }
    
    public function delete(Request $request, Response $response, array $args): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('StoragePathsController::delete', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $id = $args['id'] ?? null;
        if ($id) {
            StoragePath::delete((int)$id);
        }
        
        $basePath = \KDocs\Core\Config::basePath();
        return $response->withHeader('Location', $basePath . '/admin/storage-paths')->withStatus(302);
    }
}

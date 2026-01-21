<?php
/**
 * K-Docs - Contrôleur Custom Fields (Phase 2.1)
 */

namespace KDocs\Controllers;

use KDocs\Core\Database;
use KDocs\Models\CustomField;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CustomFieldsController
{
    private function renderTemplate(string $templatePath, array $data = []): string
    {
        extract($data);
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }
    
    public function index(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('CustomFieldsController::index', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $customFields = CustomField::all();
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/custom_fields.php', [
            'customFields' => $customFields,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Champs personnalisés - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Champs personnalisés'
        ]);
        
        $response->getBody()->write($html);
        return $response;
    }
    
    public function showForm(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('CustomFieldsController::showForm', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $id = $args['id'] ?? null;
        $customField = $id ? CustomField::find((int)$id) : null;
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/custom_field_form.php', [
            'customField' => $customField,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => ($customField ? 'Modifier' : 'Créer') . ' champ personnalisé - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => ($customField ? 'Modifier' : 'Créer') . ' champ personnalisé'
        ]);
        
        $response->getBody()->write($html);
        return $response;
    }
    
    public function save(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('CustomFieldsController::save', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $data = $request->getParsedBody();
        $id = $data['id'] ?? null;
        
        // Traiter les options pour les champs select
        if (isset($data['options']) && !empty($data['options'])) {
            $options = array_filter(array_map('trim', explode("\n", $data['options'])));
            $data['options'] = !empty($options) ? json_encode($options) : null;
        } else {
            $data['options'] = null;
        }
        
        // Traiter required
        $data['required'] = isset($data['required']) && $data['required'] == '1';
        
        try {
            if ($id) {
                CustomField::update((int)$id, $data);
            } else {
                CustomField::create($data);
            }
            
            $basePath = \KDocs\Core\Config::basePath();
            return $response->withHeader('Location', $basePath . '/admin/custom-fields')->withStatus(302);
        } catch (\Exception $e) {
            $user = $request->getAttribute('user');
            $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/custom_field_form.php', [
                'customField' => $id ? CustomField::find((int)$id) : null,
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
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('CustomFieldsController::delete', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $id = $args['id'] ?? null;
        if ($id) {
            CustomField::delete((int)$id);
        }
        
        $basePath = \KDocs\Core\Config::basePath();
        return $response->withHeader('Location', $basePath . '/admin/custom-fields')->withStatus(302);
    }
}

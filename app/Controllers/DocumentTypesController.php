<?php
/**
 * K-Docs - Contrôleur Document Types
 */

namespace KDocs\Controllers;

use KDocs\Core\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DocumentTypesController
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
        \KDocs\Core\DebugLogger::log('DocumentTypesController::index', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $db = Database::getInstance();
        
        $documentTypes = $db->query("
            SELECT dt.*, COUNT(d.id) as document_count
            FROM document_types dt
            LEFT JOIN documents d ON dt.id = d.document_type_id AND d.deleted_at IS NULL
            GROUP BY dt.id
            ORDER BY dt.label
        ")->fetchAll();
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/document_types.php', [
            'documentTypes' => $documentTypes,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Types de documents - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Types de documents'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
    
    public function showForm(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentTypesController::showForm', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $db = Database::getInstance();
        $id = $args['id'] ?? null;
        
        $documentType = null;
        if ($id) {
            $stmt = $db->prepare("SELECT * FROM document_types WHERE id = ?");
            $stmt->execute([$id]);
            $documentType = $stmt->fetch();
        }
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/document_type_form.php', [
            'documentType' => $documentType,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => ($documentType ? 'Modifier' : 'Créer') . ' type de document - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => ($documentType ? 'Modifier' : 'Créer') . ' type de document'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
    
    public function save(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentTypesController::save', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $data = $request->getParsedBody();
        $id = $data['id'] ?? null;
        $db = Database::getInstance();
        
        // Utiliser "name" comme label (comme Paperless-ngx)
        $name = trim($data['name'] ?? '');
        $match = trim($data['match'] ?? '');
        $matchingAlgorithm = isset($data['matching_algorithm']) ? (int)$data['matching_algorithm'] : 6; // 6 = Auto par défaut
        $isInsensitive = isset($data['is_insensitive']) ? 1 : 0;
        
        // Permissions
        $ownerId = !empty($data['owner_id']) ? (int)$data['owner_id'] : null;
        $viewUsers = isset($data['view_users']) && is_array($data['view_users']) ? json_encode(array_map('intval', $data['view_users'])) : null;
        $viewGroups = isset($data['view_groups']) && is_array($data['view_groups']) ? json_encode(array_map('intval', $data['view_groups'])) : null;
        $modifyUsers = isset($data['modify_users']) && is_array($data['modify_users']) ? json_encode(array_map('intval', $data['modify_users'])) : null;
        $modifyGroups = isset($data['modify_groups']) && is_array($data['modify_groups']) ? json_encode(array_map('intval', $data['modify_groups'])) : null;
        
        if (empty($name)) {
            $basePath = \KDocs\Core\Config::basePath();
            return $response->withHeader('Location', $basePath . '/admin/document-types')->withStatus(302);
        }
        
        // Générer un code à partir du nom (slug)
        $code = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        $code = trim($code, '-');
        
        if ($id) {
            $stmt = $db->prepare("
                UPDATE document_types 
                SET code = ?, label = ?, `match` = ?, matching_algorithm = ?, is_insensitive = ?,
                    owner_id = ?, view_users = ?, view_groups = ?, modify_users = ?, modify_groups = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $code, $name, $match, $matchingAlgorithm, $isInsensitive,
                $ownerId, $viewUsers, $viewGroups, $modifyUsers, $modifyGroups, $id
            ]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO document_types (code, label, `match`, matching_algorithm, is_insensitive, owner_id, view_users, view_groups, modify_users, modify_groups)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $code, $name, $match, $matchingAlgorithm, $isInsensitive,
                $ownerId, $viewUsers, $viewGroups, $modifyUsers, $modifyGroups
            ]);
        }
        
        $basePath = \KDocs\Core\Config::basePath();
        return $response->withHeader('Location', $basePath . '/admin/document-types')->withStatus(302);
    }
    
    public function delete(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('DocumentTypesController::delete', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $id = $args['id'] ?? null;
        if ($id) {
            $db = Database::getInstance();
            $stmt = $db->prepare("DELETE FROM document_types WHERE id = ?");
            $stmt->execute([$id]);
        }
        
        $basePath = \KDocs\Core\Config::basePath();
        return $response->withHeader('Location', $basePath . '/admin/document-types')->withStatus(302);
    }
}

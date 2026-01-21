<?php
/**
 * K-Docs - Contrôleur de gestion des tags (Priorité 2.3)
 */

namespace KDocs\Controllers;

use KDocs\Core\Database;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class TagsController
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
     * Liste des tags
     */
    public function index(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('TagsController::index', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $db = Database::getInstance();
        
        $tags = $db->query("
            SELECT t.*, COUNT(dt.document_id) as document_count
            FROM tags t
            LEFT JOIN document_tags dt ON t.id = dt.tag_id
            LEFT JOIN documents d ON dt.document_id = d.id AND d.deleted_at IS NULL
            GROUP BY t.id
            ORDER BY t.name
        ")->fetchAll();
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/tags.php', [
            'tags' => $tags,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Gestion des tags - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Tags'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Affiche le formulaire de création/édition
     */
    public function showForm(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('TagsController::showForm', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $db = Database::getInstance();
        $id = !empty($args['id']) ? (int)$args['id'] : null;
        
        $tag = null;
        if ($id) {
            $stmt = $db->prepare("SELECT * FROM tags WHERE id = ?");
            $stmt->execute([$id]);
            $tag = $stmt->fetch();
            
            if (!$tag) {
                $basePath = \KDocs\Core\Config::basePath();
                return $response
                    ->withHeader('Location', $basePath . '/admin/tags')
                    ->withStatus(302);
            }
        }
        
        // Récupérer tous les tags pour le select parent (Phase 3.2)
        $allTags = [];
        try {
            $allTags = $db->query("SELECT id, name, parent_id FROM tags ORDER BY name")->fetchAll();
        } catch (\Exception $e) {}
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/tag_form.php', [
            'tag' => $tag,
            'allTags' => $allTags,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => ($id ? 'Modifier' : 'Créer') . ' un tag - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => ($id ? 'Modifier' : 'Créer') . ' un tag'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Crée ou met à jour un tag
     */
    public function save(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('TagsController::save', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $db = Database::getInstance();
        $id = !empty($args['id']) ? (int)$args['id'] : null;
        
        $data = $request->getParsedBody();
        $name = trim($data['name'] ?? '');
        $color = trim($data['color'] ?? '#6b7280');
        $match = trim($data['match'] ?? '');
        
        if (empty($name)) {
            $basePath = \KDocs\Core\Config::basePath();
            return $response
                ->withHeader('Location', $basePath . '/admin/tags')
                ->withStatus(302);
        }
        
        // Valider la couleur hex
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $color = '#6b7280';
        }
        
        if ($id) {
            // Récupérer l'ancien tag pour l'audit
            $oldTagStmt = $db->prepare("SELECT * FROM tags WHERE id = ?");
            $oldTagStmt->execute([$id]);
            $oldTag = $oldTagStmt->fetch(PDO::FETCH_ASSOC);
            
            // Mise à jour
            $stmt = $db->prepare("UPDATE tags SET name = ?, color = ?, match = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$name, $color, $match, $id]);
            
            // Audit log
            if ($oldTag) {
                $changes = [];
                if ($oldTag['name'] !== $name) $changes['name'] = ['old' => $oldTag['name'], 'new' => $name];
                if ($oldTag['color'] !== $color) $changes['color'] = ['old' => $oldTag['color'], 'new' => $color];
                if (($oldTag['match'] ?? '') !== $match) $changes['match'] = ['old' => $oldTag['match'] ?? '', 'new' => $match];
                if (!empty($changes)) {
                    AuditService::logUpdate('tag', $id, $name, $changes, $user['id']);
                }
            }
        } else {
            // Création
            $stmt = $db->prepare("INSERT INTO tags (name, color, match, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
            $stmt->execute([$name, $color, $match]);
            $newId = (int)$db->lastInsertId();
            
            // Audit log
            AuditService::logCreate('tag', $newId, $name, $user['id']);
        }
        
        $basePath = \KDocs\Core\Config::basePath();
        return $response
            ->withHeader('Location', $basePath . '/admin/tags')
            ->withStatus(302);
    }

    /**
     * Supprime un tag
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('TagsController::delete', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $db = Database::getInstance();
        $id = (int)$args['id'];
        
        // Récupérer le tag avant suppression pour l'audit
        $tagStmt = $db->prepare("SELECT * FROM tags WHERE id = ?");
        $tagStmt->execute([$id]);
        $tag = $tagStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($tag) {
            // Supprimer les relations d'abord
            $stmt = $db->prepare("DELETE FROM document_tags WHERE tag_id = ?");
            $stmt->execute([$id]);
            
            // Puis supprimer le tag
            $stmt = $db->prepare("DELETE FROM tags WHERE id = ?");
            $stmt->execute([$id]);
            
            // Audit log
            AuditService::logDelete('tag', $id, $tag['name'], $user['id']);
        }
        
        $basePath = \KDocs\Core\Config::basePath();
        return $response
            ->withHeader('Location', $basePath . '/admin/tags')
            ->withStatus(302);
    }
}

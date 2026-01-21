<?php
/**
 * K-Docs - Contrôleur de gestion des correspondants (Priorité 2.2)
 */

namespace KDocs\Controllers;

use KDocs\Core\Database;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CorrespondentsController
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
     * Liste des correspondants
     */
    public function index(Request $request, Response $response): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('CorrespondentsController::index', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $user = $request->getAttribute('user');
        $db = Database::getInstance();
        
        $correspondents = $db->query("
            SELECT c.*, COUNT(d.id) as document_count
            FROM correspondents c
            LEFT JOIN documents d ON c.id = d.correspondent_id AND d.deleted_at IS NULL
            GROUP BY c.id
            ORDER BY c.name
        ")->fetchAll();
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/correspondents.php', [
            'correspondents' => $correspondents,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Gestion des correspondants - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Correspondants'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Affiche le formulaire de création/édition
     */
    public function showForm(Request $request, Response $response, array $args): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('CorrespondentsController::showForm', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $user = $request->getAttribute('user');
        $db = Database::getInstance();
        $id = !empty($args['id']) ? (int)$args['id'] : null;
        
        $correspondent = null;
        if ($id) {
            $stmt = $db->prepare("SELECT * FROM correspondents WHERE id = ?");
            $stmt->execute([$id]);
            $correspondent = $stmt->fetch();
            
            if (!$correspondent) {
                $basePath = \KDocs\Core\Config::basePath();
                return $response
                    ->withHeader('Location', $basePath . '/admin/correspondents')
                    ->withStatus(302);
            }
        }
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/correspondent_form.php', [
            'correspondent' => $correspondent,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => ($id ? 'Modifier' : 'Créer') . ' un correspondant - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => ($id ? 'Modifier' : 'Créer') . ' un correspondant'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Crée ou met à jour un correspondant
     */
    public function save(Request $request, Response $response, array $args): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('CorrespondentsController::save', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $user = $request->getAttribute('user');
        $db = Database::getInstance();
        $id = !empty($args['id']) ? (int)$args['id'] : null;
        
        $data = $request->getParsedBody();
        $name = trim($data['name'] ?? '');
        $slug = trim($data['slug'] ?? '');
        $match = trim($data['match'] ?? '');
        
        if (empty($name)) {
            $basePath = \KDocs\Core\Config::basePath();
            return $response
                ->withHeader('Location', $basePath . '/admin/correspondents')
                ->withStatus(302);
        }
        
        // Générer le slug si vide
        if (empty($slug)) {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
        }
        
        if ($id) {
            // Récupérer l'ancien correspondant pour l'audit
            $oldCorrStmt = $db->prepare("SELECT * FROM correspondents WHERE id = ?");
            $oldCorrStmt->execute([$id]);
            $oldCorrespondent = $oldCorrStmt->fetch(PDO::FETCH_ASSOC);
            
            // Mise à jour
            $stmt = $db->prepare("UPDATE correspondents SET name = ?, slug = ?, match = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$name, $slug, $match, $id]);
            
            // Audit log
            if ($oldCorrespondent) {
                $changes = [];
                if ($oldCorrespondent['name'] !== $name) $changes['name'] = ['old' => $oldCorrespondent['name'], 'new' => $name];
                if ($oldCorrespondent['slug'] !== $slug) $changes['slug'] = ['old' => $oldCorrespondent['slug'], 'new' => $slug];
                if (($oldCorrespondent['match'] ?? '') !== $match) $changes['match'] = ['old' => $oldCorrespondent['match'] ?? '', 'new' => $match];
                if (!empty($changes)) {
                    AuditService::logUpdate('correspondent', $id, $name, $changes, $user['id']);
                }
            }
        } else {
            // Création
            $stmt = $db->prepare("INSERT INTO correspondents (name, slug, match, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
            $stmt->execute([$name, $slug, $match]);
            $newId = (int)$db->lastInsertId();
            
            // Audit log
            AuditService::logCreate('correspondent', $newId, $name, $user['id']);
        }
        
        $basePath = \KDocs\Core\Config::basePath();
        return $response
            ->withHeader('Location', $basePath . '/admin/correspondents')
            ->withStatus(302);
    }

    /**
     * Supprime un correspondant
     */
    public function delete(Request $request, Response $response, array $args): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('CorrespondentsController::delete', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $user = $request->getAttribute('user');
        $db = Database::getInstance();
        $id = (int)$args['id'];
        
        // Vérifier s'il y a des documents associés
        $stmt = $db->prepare("SELECT COUNT(*) FROM documents WHERE correspondent_id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        $count = (int)$stmt->fetchColumn();
        
        if ($count > 0) {
            // Ne pas supprimer, juste retourner avec erreur
            $basePath = \KDocs\Core\Config::basePath();
            return $response
                ->withHeader('Location', $basePath . '/admin/correspondents?error=has_documents')
                ->withStatus(302);
        }
        
        // Récupérer le correspondant avant suppression pour l'audit
        $corrStmt = $db->prepare("SELECT * FROM correspondents WHERE id = ?");
        $corrStmt->execute([$id]);
        $correspondent = $corrStmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $db->prepare("DELETE FROM correspondents WHERE id = ?");
        $stmt->execute([$id]);
        
        // Audit log
        if ($correspondent) {
            AuditService::logDelete('correspondent', $id, $correspondent['name'], $user['id']);
        }
        
        $basePath = \KDocs\Core\Config::basePath();
        return $response
            ->withHeader('Location', $basePath . '/admin/correspondents')
            ->withStatus(302);
    }
    
    /**
     * Recherche de correspondants (API pour autocomplétion)
     */
    public function search(Request $request, Response $response): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('CorrespondentsController::search', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $query = $request->getQueryParams()['q'] ?? '';
        $db = Database::getInstance();
        
        $stmt = $db->prepare("
            SELECT id, name 
            FROM correspondents 
            WHERE name LIKE ? 
            ORDER BY name 
            LIMIT 20
        ");
        $stmt->execute(['%' . $query . '%']);
        $results = $stmt->fetchAll();
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'results' => $results
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }
}

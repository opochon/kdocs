<?php
/**
 * K-Docs - Contrôleur Workflows (Phase 3.3)
 */

namespace KDocs\Controllers;

use KDocs\Models\WorkflowDefinition;
use KDocs\Workflow\WorkflowManager;
use KDocs\Core\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class WorkflowsController
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
        \KDocs\Core\DebugLogger::log('WorkflowsController::index', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        
        // Utiliser le nouveau système WorkflowDefinition
        try {
            $workflows = WorkflowDefinition::findAll();
        } catch (\Exception $e) {
            // Si la table n'existe pas encore, retourner un tableau vide
            error_log("Erreur chargement workflows: " . $e->getMessage());
            $workflows = [];
        }
        
        // Charger les nodes et connections pour chaque workflow
        $workflowManager = new WorkflowManager();
        foreach ($workflows as &$workflow) {
            try {
                $fullWorkflow = $workflowManager->getWorkflow($workflow['id']);
                $workflow['nodes'] = $fullWorkflow['nodes'] ?? [];
                $workflow['connections'] = $fullWorkflow['connections'] ?? [];
            } catch (\Exception $e) {
                $workflow['nodes'] = [];
                $workflow['connections'] = [];
            }
        }
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/workflows.php', [
            'workflows' => $workflows,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Workflows - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Workflows'
        ]);
        
        $response->getBody()->write($html);
        return $response;
    }
    
    public function showForm(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('WorkflowsController::showForm', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $id = $args['id'] ?? null;
        
        // Rediriger vers le designer pour les workflows existants
        if ($id) {
            return $response->withHeader('Location', '/admin/workflows/' . $id . '/designer')->withStatus(302);
        }
        
        // Pour un nouveau workflow, rediriger vers le designer
        return $response->withHeader('Location', '/admin/workflows/new/designer')->withStatus(302);
    }
    
    public function save(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('WorkflowsController::save', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        
        // Rediriger vers le designer pour la sauvegarde
        // La sauvegarde se fait maintenant via l'API du designer
        $basePath = \KDocs\Core\Config::basePath();
        return $response->withHeader('Location', $basePath . '/admin/workflows/new/designer')->withStatus(302);
    }
    
    public function delete(Request $request, Response $response, array $args): Response
    {
        // Utiliser le nouveau système WorkflowManager pour supprimer
        $id = (int)($args['id'] ?? 0);
        if ($id > 0) {
            try {
                $workflowManager = new WorkflowManager();
                $workflowManager->deleteWorkflow($id);
            } catch (\Exception $e) {
                error_log("Erreur suppression workflow: " . $e->getMessage());
            }
        }
        
        $basePath = \KDocs\Core\Config::basePath();
        return $response->withHeader('Location', $basePath . '/admin/workflows')->withStatus(302);
    }
    
    
    public function actionFormTemplate(Request $request, Response $response): Response
    {
        $index = (int)($request->getQueryParams()['index'] ?? 0);
        
        // Charger toutes les données nécessaires
        $db = Database::getInstance();
        $tags = $db->query("SELECT id, name FROM tags ORDER BY name")->fetchAll();
        $correspondents = $db->query("SELECT id, name FROM correspondents ORDER BY name")->fetchAll();
        $documentTypes = $db->query("SELECT id, label FROM document_types ORDER BY label")->fetchAll();
        
        // Vérifier si la table storage_paths existe
        try {
            $storagePaths = $db->query("SELECT id, name FROM storage_paths ORDER BY name")->fetchAll();
        } catch (\Exception $e) {
            $storagePaths = [];
        }
        
        // Vérifier si la table custom_fields existe
        try {
            $customFields = $db->query("SELECT id, name FROM custom_fields ORDER BY name")->fetchAll();
        } catch (\Exception $e) {
            $customFields = [];
        }
        
        $users = $db->query("SELECT id, username, first_name, last_name FROM users ORDER BY username")->fetchAll();
        
        // Vérifier si la table groups existe
        try {
            $groups = $db->query("SELECT id, name FROM groups ORDER BY name")->fetchAll();
        } catch (\Exception $e) {
            $groups = [];
        }
        
        $action = null; // Nouvelle action
        
        ob_start();
        include __DIR__ . '/../../templates/admin/workflow_action_form.php';
        $html = ob_get_clean();
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}

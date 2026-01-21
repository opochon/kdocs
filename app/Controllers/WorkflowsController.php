<?php
/**
 * K-Docs - Contrôleur Workflows (Phase 3.3)
 */

namespace KDocs\Controllers;

use KDocs\Models\Workflow;
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
        // #region agent log
        \KDocs\Core\DebugLogger::log('WorkflowsController::index', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $user = $request->getAttribute('user');
        $workflows = Workflow::all();
        
        // Charger les triggers et actions pour chaque workflow
        foreach ($workflows as &$workflow) {
            $workflow['triggers'] = Workflow::getTriggers($workflow['id']);
            $workflow['actions'] = Workflow::getActions($workflow['id']);
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
        // #region agent log
        \KDocs\Core\DebugLogger::log('WorkflowsController::showForm', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $user = $request->getAttribute('user');
        $id = $args['id'] ?? null;
        $workflow = $id ? Workflow::find((int)$id) : null;
        
        if ($workflow) {
            $workflow['triggers'] = Workflow::getTriggers($workflow['id']);
            $workflow['actions'] = Workflow::getActions($workflow['id']);
        }
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/workflow_form.php', [
            'workflow' => $workflow,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => ($workflow ? 'Modifier' : 'Créer') . ' workflow - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => ($workflow ? 'Modifier' : 'Créer') . ' workflow'
        ]);
        
        $response->getBody()->write($html);
        return $response;
    }
    
    public function save(Request $request, Response $response): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('WorkflowsController::save', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $data = $request->getParsedBody();
        $id = $data['id'] ?? null;
        
        try {
            if ($id) {
                Workflow::update((int)$id, $data);
                $workflowId = (int)$id;
            } else {
                $workflowId = Workflow::create($data);
            }
            
            // Gérer les triggers
            if (isset($data['triggers']) && is_array($data['triggers'])) {
                // Supprimer les anciens triggers
                $db = \KDocs\Core\Database::getInstance();
                $db->prepare("DELETE FROM workflow_triggers WHERE workflow_id = ?")->execute([$workflowId]);
                
                // Ajouter les nouveaux
                foreach ($data['triggers'] as $trigger) {
                    if (!empty($trigger['trigger_type'])) {
                        Workflow::addTrigger($workflowId, $trigger);
                    }
                }
            }
            
            // Gérer les actions
            if (isset($data['actions']) && is_array($data['actions'])) {
                // Supprimer les anciennes actions
                $db = \KDocs\Core\Database::getInstance();
                $db->prepare("DELETE FROM workflow_actions WHERE workflow_id = ?")->execute([$workflowId]);
                
                // Ajouter les nouvelles
                foreach ($data['actions'] as $index => $action) {
                    if (!empty($action['action_type'])) {
                        $action['order_index'] = $index;
                        Workflow::addAction($workflowId, $action);
                    }
                }
            }
            
            $basePath = \KDocs\Core\Config::basePath();
            return $response->withHeader('Location', $basePath . '/admin/workflows')->withStatus(302);
        } catch (\Exception $e) {
            $user = $request->getAttribute('user');
            $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/workflow_form.php', [
                'workflow' => $id ? Workflow::find((int)$id) : null,
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
        \KDocs\Core\DebugLogger::log('WorkflowsController::delete', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $id = $args['id'] ?? null;
        if ($id) {
            Workflow::delete((int)$id);
        }
        
        $basePath = \KDocs\Core\Config::basePath();
        return $response->withHeader('Location', $basePath . '/admin/workflows')->withStatus(302);
    }
}

<?php
/**
 * K-Docs - WorkflowDesignerPageController
 * Contrôleur pour la page du designer
 */

namespace KDocs\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class WorkflowDesignerPageController
{
    private function renderTemplate(string $templatePath, array $data = []): string
    {
        extract($data);
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }
    
    /**
     * GET /admin/workflows/{id}/designer
     * Affiche la page du designer pour un workflow existant
     */
    public function designer(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $workflowId = (int)($args['id'] ?? 0);
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/workflow/designer.php', [
            'workflowId' => $workflowId,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Workflow Designer - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Workflow Designer'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
    
    /**
     * GET /admin/workflows/new/designer
     * Affiche la page du designer pour un nouveau workflow
     */
    public function newDesigner(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/workflow/designer.php', [
            'workflowId' => null,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Nouveau Workflow - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Nouveau Workflow'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}

<?php
/**
 * K-Docs - Contrôleur Chat IA
 */

namespace KDocs\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ChatController
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
     * Affiche la page du Chat IA
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/chat/index.php', [
            'user' => $user,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Recherche avancée - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Recherche avancée'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}

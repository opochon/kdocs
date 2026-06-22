<?php
/**
 * K-Docs - Recherche unifiée (plein texte + filtres basiques)
 */

namespace KDocs\Controllers;

use KDocs\Core\Database;
use KDocs\Search\SearchQuery;
use KDocs\Services\SearchService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SearchController
{
    private function renderTemplate(string $templatePath, array $data = []): string
    {
        extract($data);
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }

    /**
     * GET /search — recherche documents + lien assistant IA
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();
        $mode = $params['mode'] ?? 'documents';

        if ($mode === 'chat') {
            return $this->renderChatMode($request, $response, $user);
        }

        $searchQuery = SearchQuery::fromArray($params);
        $searchService = new SearchService();
        $result = $searchService->advancedSearch($searchQuery);

        $documentTypes = [];
        $correspondents = [];
        try {
            $db = Database::getInstance();
            $documentTypes = $db->query('SELECT id, name FROM document_types ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC);
            $correspondents = $db->query('SELECT id, name FROM correspondents ORDER BY name LIMIT 200')->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            // BDD minimale
        }

        $content = $this->renderTemplate(__DIR__ . '/../../templates/search/index.php', [
            'user' => $user,
            'result' => $result,
            'searchQuery' => $searchQuery,
            'documentTypes' => $documentTypes,
            'correspondents' => $correspondents,
            'q' => trim((string) ($params['q'] ?? $params['text'] ?? '')),
        ]);

        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Recherche - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Recherche',
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * GET /chat — redirection vers /search?mode=chat (compat B0)
     */
    public function redirectFromChat(Request $request, Response $response): Response
    {
        $query = $request->getUri()->getQuery();
        $target = url('/search?mode=chat');
        if ($query !== '') {
            $target .= '&' . $query;
        }

        return $response
            ->withHeader('Location', $target)
            ->withStatus(302);
    }

    private function renderChatMode(Request $request, Response $response, ?array $user): Response
    {
        $content = $this->renderTemplate(__DIR__ . '/../../templates/chat/index.php', [
            'user' => $user,
        ]);

        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Assistant IA - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Assistant IA',
            'fullHeight' => true,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}

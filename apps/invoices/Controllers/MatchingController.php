<?php

namespace KDocs\Apps\Invoices\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MatchingController
{
    public function suggestions(Request $request, Response $response, array $args): Response
    {
        $response->getBody()->write('<h1>Rapprochement</h1><p>Module en cours d\'activation.</p>');
        return $response;
    }

    public function apply(Request $request, Response $response, array $args): Response
    {
        $response->getBody()->write(json_encode(['success' => true, 'stub' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function searchBL(Request $request, Response $response): Response
    {
        $response->getBody()->write(json_encode(['success' => true, 'results' => []]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function searchArticles(Request $request, Response $response): Response
    {
        $response->getBody()->write(json_encode(['success' => true, 'results' => []]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}

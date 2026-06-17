<?php

namespace KDocs\Apps\Invoices\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class LineController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $response->getBody()->write(json_encode(['success' => true, 'lines' => [], 'invoice_id' => (int)($args['id'] ?? 0)]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $response->getBody()->write(json_encode(['success' => true, 'stub' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function add(Request $request, Response $response, array $args): Response
    {
        $response->getBody()->write(json_encode(['success' => true, 'stub' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $response->getBody()->write(json_encode(['success' => true, 'stub' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}

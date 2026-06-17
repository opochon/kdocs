<?php

namespace KDocs\Apps\Invoices\Controllers\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class InvoiceApiController
{
    public function pending(Request $request, Response $response): Response
    {
        $response->getBody()->write(json_encode(['success' => true, 'invoices' => []]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function stats(Request $request, Response $response): Response
    {
        $response->getBody()->write(json_encode(['success' => true, 'pending' => 0, 'validated' => 0]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function extract(Request $request, Response $response): Response
    {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Stub']));
        return $response->withHeader('Content-Type', 'application/json');
    }
}

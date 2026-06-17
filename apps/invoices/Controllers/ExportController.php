<?php

namespace KDocs\Apps\Invoices\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ExportController
{
    public function index(Request $request, Response $response): Response
    {
        $response->getBody()->write('<h1>Export factures</h1><p>Stub — à implémenter.</p>');
        return $response;
    }

    public function toWinBiz(Request $request, Response $response): Response
    {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Export WinBiz stub']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function toCsv(Request $request, Response $response): Response
    {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Export CSV stub']));
        return $response->withHeader('Content-Type', 'application/json');
    }
}

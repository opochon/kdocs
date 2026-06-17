<?php

namespace KDocs\Apps\Invoices\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class InvoiceController
{
    public function index(Request $request, Response $response): Response
    {
        $html = $this->render('inbox', ['title' => 'Factures fournisseurs']);
        $response->getBody()->write($html);
        return $response;
    }

    public function inbox(Request $request, Response $response): Response
    {
        return $this->index($request, $response);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $html = $this->render('inbox', [
            'title' => "Facture #{$id}",
            'invoice_id' => $id,
        ]);
        $response->getBody()->write($html);
        return $response;
    }

    public function extract(Request $request, Response $response, array $args): Response
    {
        $response->getBody()->write(json_encode([
            'success' => false,
            'message' => 'Extraction IA non implémentée (stub)',
            'invoice_id' => (int)($args['id'] ?? 0),
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function validate(Request $request, Response $response, array $args): Response
    {
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Validation stub',
            'invoice_id' => (int)($args['id'] ?? 0),
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function reject(Request $request, Response $response, array $args): Response
    {
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Rejet stub',
            'invoice_id' => (int)($args['id'] ?? 0),
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function render(string $template, array $data = []): string
    {
        $file = dirname(__DIR__) . '/templates/' . $template . '.php';
        if (!is_file($file)) {
            return '<h1>K-Invoices</h1><p>Template manquant.</p>';
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string)ob_get_clean();
    }
}

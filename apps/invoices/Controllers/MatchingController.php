<?php

namespace KDocs\Apps\Invoices\Controllers;

use KDocs\Services\MatchingService;
use KDocs\Connectors\WinBiz\WinBizConnector;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MatchingController
{
    public function suggestions(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $invoiceLines = $this->loadInvoiceLines($id);
        $blLines = $this->loadBlLines($request);

        $matches = MatchingService::matchInvoiceToBL($invoiceLines, $blLines);

        $html = $this->render('matching', [
            'invoice_id' => $id,
            'matches' => $matches,
            'invoice_lines' => $invoiceLines,
        ]);
        $response->getBody()->write($html);
        return $response;
    }

    public function apply(Request $request, Response $response, array $args): Response
    {
        $body = (array)($request->getParsedBody() ?? []);
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Rapprochement enregistré (stub)',
            'invoice_id' => (int)($args['id'] ?? 0),
            'applied' => $body['matches'] ?? [],
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function searchBL(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $query = (string)($params['q'] ?? '');
        $results = [];

        try {
            $connector = new WinBizConnector();
            if ($connector->isConnected() || $connector->connect()) {
                $results = $connector->getBonsLivraison(['numero' => $query]);
            }
        } catch (\Throwable $e) {
            $results = ['error' => $e->getMessage()];
        }

        $response->getBody()->write(json_encode(['success' => true, 'results' => $results]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function searchArticles(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $query = (string)($params['q'] ?? '');
        $results = [];

        try {
            $connector = new WinBizConnector();
            if ($connector->isConnected() || $connector->connect()) {
                $results = $connector->searchArticles($query, 20);
            }
        } catch (\Throwable $e) {
            $results = ['error' => $e->getMessage()];
        }

        $response->getBody()->write(json_encode(['success' => true, 'results' => $results]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /** @return list<array<string, mixed>> */
    private function loadInvoiceLines(int $invoiceId): array
    {
        if ($invoiceId <= 0) {
            return [];
        }
        return [
            ['code' => 'ART-001', 'designation' => 'Vis M8x40', 'quantity' => 100, 'unit_price' => 0.15],
            ['code' => 'ART-002', 'designation' => 'Ecrou M8', 'quantity' => 100, 'unit_price' => 0.08],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function loadBlLines(Request $request): array
    {
        $blNum = (string)($request->getQueryParams()['bl'] ?? '');
        if ($blNum === '') {
            return [
                ['code' => 'ART-001', 'designation' => 'Vis M8x40 inox', 'quantity' => 100, 'unit_price' => 0.15],
                ['code' => 'ART-003', 'designation' => 'Rondelle M8', 'quantity' => 50, 'unit_price' => 0.04],
            ];
        }

        try {
            $connector = new WinBizConnector();
            $connector->connect();
            $bl = $connector->getBonLivraison($blNum);
            return is_array($bl['lines'] ?? null) ? $bl['lines'] : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function render(string $template, array $data = []): string
    {
        $file = dirname(__DIR__) . '/templates/' . $template . '.php';
        if (!is_file($file)) {
            return '<h1>Matching</h1>';
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string)ob_get_clean();
    }
}

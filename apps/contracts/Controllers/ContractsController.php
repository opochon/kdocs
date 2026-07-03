<?php

declare(strict_types=1);

/**
 * Contrôleur contrats — GAP-030.
 *
 * makeService() est protected pour permettre l'injection du service
 * via une sous-classe anonyme dans les tests.
 */

namespace KDocs\Apps\Contracts\Controllers;

use KDocs\Apps\Contracts\Services\ContractService;
use KDocs\Core\Database;

class ContractsController
{
    /**
     * Fabrique du service — surchargeable dans les tests.
     */
    protected function makeService(): ContractService
    {
        return new ContractService(Database::getInstance());
    }

    /**
     * GET /contracts — liste des contrats avec due_date.
     *
     * Réponse JSON : {'contracts': [...], 'count': N}
     *
     * @param array<string,mixed> $args
     */
    public function index(object $request, object $response, array $args = []): object
    {
        $params  = $request->getQueryParams();
        $filters = [];
        if (!empty($params['status'])) {
            $filters['status'] = $params['status'];
        }

        $contracts = $this->makeService()->list($filters);

        $response->getBody()->write(json_encode([
            'contracts' => $contracts,
            'count'     => count($contracts),
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST /contracts — création d'un contrat.
     *
     * Réponse 201 JSON : {'id': N, 'success': true}
     *
     * @param array<string,mixed> $args
     */
    public function store(object $request, object $response, array $args = []): object
    {
        $data = $request->getParsedBody() ?? [];

        if (empty($data['title'])) {
            $response->getBody()->write(json_encode(['error' => 'Le champ title est requis']));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(400);
        }

        $id = $this->makeService()->create($data);

        $response->getBody()->write(json_encode([
            'id'      => $id,
            'success' => true,
        ]));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(201);
    }

    /**
     * GET /contracts/upcoming — échéances à venir.
     *
     * Paramètre query optionnel : ?days=N (défaut 90).
     * Réponse JSON : {'contracts': [...], 'count': N}
     *
     * @param array<string,mixed> $args
     */
    public function upcoming(object $request, object $response, array $args = []): object
    {
        $params     = $request->getQueryParams();
        $withinDays = isset($params['days']) ? (int) $params['days'] : 90;

        $contracts = $this->makeService()->upcoming($withinDays);

        $response->getBody()->write(json_encode([
            'contracts' => $contracts,
            'count'     => count($contracts),
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }
}

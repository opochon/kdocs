<?php

declare(strict_types=1);

/**
 * Contrôleur RH — GAP-033 (dossier RH digital).
 *
 * makeService() est protected pour permettre l'injection du service
 * via une sous-classe anonyme dans les tests.
 */

namespace KDocs\Apps\Rh\Controllers;

use KDocs\Apps\Rh\Services\HrService;
use KDocs\Core\Database;

class HrController
{
    /**
     * Fabrique du service — surchargeable dans les tests.
     */
    protected function makeService(): HrService
    {
        return new HrService(Database::getInstance());
    }

    /**
     * GET /rh/employees/{id} — fiche employé + dossiers documentaires.
     *
     * 200 avec fiche JSON si trouvé, 404 sinon.
     *
     * @param array<string,mixed> $args
     */
    public function show(object $request, object $response, array $args = []): object
    {
        $id       = (int) ($args['id'] ?? 0);
        $employee = $this->makeService()->getEmployee($id);

        if ($employee === null) {
            $response->getBody()->write(json_encode(['error' => 'Employé non trouvé']));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(404);
        }

        $response->getBody()->write(json_encode($employee));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * GET /rh/employees — liste des employés.
     *
     * @param array<string,mixed> $args
     */
    public function index(object $request, object $response, array $args = []): object
    {
        $employees = $this->makeService()->listEmployees();

        $response->getBody()->write(json_encode([
            'employees' => $employees,
            'count'     => count($employees),
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }
}

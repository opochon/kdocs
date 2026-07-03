<?php
/**
 * K-RH — Routes (GAP-033 — dossier RH digital).
 * Remplace le stub scaffold phase D.
 */

use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $app): void {
    $app->group('/rh', function (RouteCollectorProxy $group): void {
        // Accueil plugin (route de santé minimale)
        $group->get('', function ($req, $res) {
            $res->getBody()->write('K-RH plugin — GAP-033');
            return $res;
        });

        // Dossiers employés
        $group->get('/employees',      'KDocs\\Apps\\Rh\\Controllers\\HrController:index');
        $group->get('/employees/{id}', 'KDocs\\Apps\\Rh\\Controllers\\HrController:show');
    });
};

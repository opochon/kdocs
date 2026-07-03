<?php
/**
 * K-Contrats — Routes (GAP-030).
 */

use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $app): void {
    $app->group('/contracts', function (RouteCollectorProxy $group): void {
        $group->get('',          'KDocs\\Apps\\Contracts\\Controllers\\ContractsController:index');
        $group->post('',         'KDocs\\Apps\\Contracts\\Controllers\\ContractsController:store');
        $group->get('/upcoming', 'KDocs\\Apps\\Contracts\\Controllers\\ContractsController:upcoming');
    });
};

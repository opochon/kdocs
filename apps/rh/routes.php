<?php
/**
 * K-RH — Routes (scaffold phase D)
 */

use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $app): void {
    $app->group('/rh', function (RouteCollectorProxy $group): void {
        $group->get('', function ($req, $res) {
            $res->getBody()->write('K-RH plugin — scaffold phase D');
            return $res;
        });
    });
};

<?php
/**
 * K-SMQ — Routes (scaffold phase C)
 */

use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $app): void {
    $app->group('/smq', function (RouteCollectorProxy $group): void {
        $group->get('', function ($req, $res) {
            $res->getBody()->write('K-SMQ plugin — scaffold phase C');
            return $res;
        });
    });
};

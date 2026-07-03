<?php
/**
 * K-Portail — Routes (GAP-042).
 *
 * Chargé automatiquement par PluginRegistry si PORTAL_APP_ENABLED=true.
 * Groupe /portal — lecture seule, aucune route POST/PUT/DELETE.
 */

use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $app) {
    $app->group('/portal', function (RouteCollectorProxy $group) {
        // Liste des documents d'un correspondant — lecture seule
        $group->get('/{client}', 'KDocs\\Apps\\Portal\\Controllers\\PortalController:show');
    });
};

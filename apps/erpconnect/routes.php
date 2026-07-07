<?php
/**
 * K-ERP Connect — Routes (auto-chargées par PluginRegistry si ERPCONNECT_APP_ENABLED=true).
 * Spec : K-TIME/docs/SPEC-GED-INTEGRATION.md
 */

use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $app): void {
    $app->group('/erpconnect', function (RouteCollectorProxy $group): void {
        // Endpoints AJAX sous /api/ : exemption CSRF (convention CSRFMiddleware),
        // toujours derrière AuthMiddleware (session requise).
        /** GET  /erpconnect/api/proposal/{documentId} — proposition de ventilation JSON */
        $group->get('/api/proposal/{documentId:[0-9]+}',
            'KDocs\\Apps\\Erpconnect\\Controllers\\ErpConnectController:proposal');

        /** POST /erpconnect/api/submit/{documentId} — introduction dans K-Time */
        $group->post('/api/submit/{documentId:[0-9]+}',
            'KDocs\\Apps\\Erpconnect\\Controllers\\ErpConnectController:submit');

        /** POST /erpconnect/api/refresh/{documentId} — rafraîchir statut validation */
        $group->post('/api/refresh/{documentId:[0-9]+}',
            'KDocs\\Apps\\Erpconnect\\Controllers\\ErpConnectController:refresh');

        /** POST /erpconnect/api/block/{documentId} — demande de blocage avec cause */
        $group->post('/api/block/{documentId:[0-9]+}',
            'KDocs\\Apps\\Erpconnect\\Controllers\\ErpConnectController:block');

        /** GET  /erpconnect/panel/{documentId} — panneau HTML */
        $group->get('/panel/{documentId:[0-9]+}',
            'KDocs\\Apps\\Erpconnect\\Controllers\\ErpConnectController:panel');
    });
};

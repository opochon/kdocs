<?php
/**
 * K-ERP Connect — Routes (auto-chargées par PluginRegistry si ERPCONNECT_APP_ENABLED=true).
 * Spec : K-TIME/docs/SPEC-GED-INTEGRATION.md
 */

use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $app): void {
    $app->group('/erpconnect', function (RouteCollectorProxy $group): void {
        /** GET  /erpconnect/proposal/{documentId} — proposition de ventilation JSON (AJAX) */
        $group->get('/proposal/{documentId:[0-9]+}',
            'KDocs\\Apps\\Erpconnect\\Controllers\\ErpConnectController:proposal');

        /** POST /erpconnect/submit/{documentId} — introduction dans K-Time */
        $group->post('/submit/{documentId:[0-9]+}',
            'KDocs\\Apps\\Erpconnect\\Controllers\\ErpConnectController:submit');

        /** POST /erpconnect/refresh/{documentId} — rafraîchir statut validation */
        $group->post('/refresh/{documentId:[0-9]+}',
            'KDocs\\Apps\\Erpconnect\\Controllers\\ErpConnectController:refresh');

        /** GET  /erpconnect/panel/{documentId} — panneau HTML */
        $group->get('/panel/{documentId:[0-9]+}',
            'KDocs\\Apps\\Erpconnect\\Controllers\\ErpConnectController:panel');
    });
};

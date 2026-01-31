<?php
/**
 * K-Invoices - Routes
 * Gestion factures fournisseurs
 */

use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $app) {
    $app->group('/invoices', function (RouteCollectorProxy $group) {
        // Dashboard / Inbox
        $group->get('', 'KDocs\\Apps\\Invoices\\Controllers\\InvoiceController:index');
        $group->get('/inbox', 'KDocs\\Apps\\Invoices\\Controllers\\InvoiceController:inbox');

        // Factures
        $group->get('/{id}', 'KDocs\\Apps\\Invoices\\Controllers\\InvoiceController:show');
        $group->post('/{id}/extract', 'KDocs\\Apps\\Invoices\\Controllers\\InvoiceController:extract');
        $group->post('/{id}/validate', 'KDocs\\Apps\\Invoices\\Controllers\\InvoiceController:validate');
        $group->post('/{id}/reject', 'KDocs\\Apps\\Invoices\\Controllers\\InvoiceController:reject');

        // Lignes
        $group->get('/{id}/lines', 'KDocs\\Apps\\Invoices\\Controllers\\LineController:index');
        $group->put('/{id}/lines/{lineId}', 'KDocs\\Apps\\Invoices\\Controllers\\LineController:update');
        $group->post('/{id}/lines', 'KDocs\\Apps\\Invoices\\Controllers\\LineController:add');
        $group->delete('/{id}/lines/{lineId}', 'KDocs\\Apps\\Invoices\\Controllers\\LineController:delete');

        // Rapprochement WinBiz
        $group->get('/{id}/matching', 'KDocs\\Apps\\Invoices\\Controllers\\MatchingController:suggestions');
        $group->post('/{id}/matching/apply', 'KDocs\\Apps\\Invoices\\Controllers\\MatchingController:apply');
        $group->get('/winbiz/bl', 'KDocs\\Apps\\Invoices\\Controllers\\MatchingController:searchBL');
        $group->get('/winbiz/articles', 'KDocs\\Apps\\Invoices\\Controllers\\MatchingController:searchArticles');

        // Export
        $group->get('/export', 'KDocs\\Apps\\Invoices\\Controllers\\ExportController:index');
        $group->post('/export/winbiz', 'KDocs\\Apps\\Invoices\\Controllers\\ExportController:toWinBiz');
        $group->post('/export/csv', 'KDocs\\Apps\\Invoices\\Controllers\\ExportController:toCsv');

        // API JSON
        $group->group('/api', function (RouteCollectorProxy $api) {
            $api->get('/pending', 'KDocs\\Apps\\Invoices\\Controllers\\Api\\InvoiceApiController:pending');
            $api->get('/stats', 'KDocs\\Apps\\Invoices\\Controllers\\Api\\InvoiceApiController:stats');
            $api->post('/extract', 'KDocs\\Apps\\Invoices\\Controllers\\Api\\InvoiceApiController:extract');
        });
    });
};

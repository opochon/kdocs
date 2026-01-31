<?php
/**
 * K-Time - Routes
 * Saisie horaire integree a K-Docs
 */

use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $app) {
    $app->group('/time', function (RouteCollectorProxy $group) {
        // Dashboard
        $group->get('', 'KDocs\\Apps\\Timetrack\\Controllers\\DashboardController:index');

        // Entrees de temps
        $group->get('/entries', 'KDocs\\Apps\\Timetrack\\Controllers\\EntryController:index');
        $group->post('/entries', 'KDocs\\Apps\\Timetrack\\Controllers\\EntryController:store');
        $group->post('/entries/quick', 'KDocs\\Apps\\Timetrack\\Controllers\\EntryController:quickCreate');
        $group->put('/entries/{id}', 'KDocs\\Apps\\Timetrack\\Controllers\\EntryController:update');
        $group->delete('/entries/{id}', 'KDocs\\Apps\\Timetrack\\Controllers\\EntryController:delete');

        // Timer
        $group->get('/timer', 'KDocs\\Apps\\Timetrack\\Controllers\\TimerController:status');
        $group->post('/timer/start', 'KDocs\\Apps\\Timetrack\\Controllers\\TimerController:start');
        $group->post('/timer/pause', 'KDocs\\Apps\\Timetrack\\Controllers\\TimerController:pause');
        $group->post('/timer/resume', 'KDocs\\Apps\\Timetrack\\Controllers\\TimerController:resume');
        $group->post('/timer/stop', 'KDocs\\Apps\\Timetrack\\Controllers\\TimerController:stop');

        // Clients
        $group->get('/clients', 'KDocs\\Apps\\Timetrack\\Controllers\\ClientController:index');
        $group->post('/clients', 'KDocs\\Apps\\Timetrack\\Controllers\\ClientController:store');
        $group->get('/clients/{id}', 'KDocs\\Apps\\Timetrack\\Controllers\\ClientController:show');
        $group->put('/clients/{id}', 'KDocs\\Apps\\Timetrack\\Controllers\\ClientController:update');

        // Projets
        $group->get('/projects', 'KDocs\\Apps\\Timetrack\\Controllers\\ProjectController:index');
        $group->post('/projects', 'KDocs\\Apps\\Timetrack\\Controllers\\ProjectController:store');
        $group->get('/projects/autocomplete', 'KDocs\\Apps\\Timetrack\\Controllers\\ProjectController:autocomplete');

        // Fournitures
        $group->get('/supplies', 'KDocs\\Apps\\Timetrack\\Controllers\\SupplyController:index');
        $group->post('/supplies', 'KDocs\\Apps\\Timetrack\\Controllers\\SupplyController:store');

        // Factures
        $group->get('/invoices', 'KDocs\\Apps\\Timetrack\\Controllers\\InvoiceController:index');
        $group->post('/invoices/generate', 'KDocs\\Apps\\Timetrack\\Controllers\\InvoiceController:generate');
        $group->get('/invoices/{id}', 'KDocs\\Apps\\Timetrack\\Controllers\\InvoiceController:show');
        $group->get('/invoices/{id}/pdf', 'KDocs\\Apps\\Timetrack\\Controllers\\InvoiceController:pdf');

        // Integration K-Docs
        $group->get('/kdocs/search', 'KDocs\\Apps\\Timetrack\\Controllers\\KDocsController:search');
        $group->post('/kdocs/sync-clients', 'KDocs\\Apps\\Timetrack\\Controllers\\KDocsController:syncClients');

        // API JSON
        $group->group('/api', function (RouteCollectorProxy $api) {
            $api->get('/entries', 'KDocs\\Apps\\Timetrack\\Controllers\\Api\\TimeApiController:entries');
            $api->get('/stats', 'KDocs\\Apps\\Timetrack\\Controllers\\Api\\TimeApiController:stats');
            $api->post('/quick-parse', 'KDocs\\Apps\\Timetrack\\Controllers\\Api\\TimeApiController:parseQuickCode');
        });
    });
};

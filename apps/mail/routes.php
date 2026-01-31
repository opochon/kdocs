<?php
/**
 * K-Mail - Routes
 * Client mail integre a K-Docs
 */

use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $app) {
    $app->group('/mail', function (RouteCollectorProxy $group) {
        // Dashboard
        $group->get('', 'KDocs\\Apps\\Mail\\Controllers\\MailboxController:index');

        // Dossiers / Folders
        $group->get('/folders', 'KDocs\\Apps\\Mail\\Controllers\\MailboxController:folders');
        $group->get('/folders/{folder}', 'KDocs\\Apps\\Mail\\Controllers\\MailboxController:folder');

        // Messages
        $group->get('/messages', 'KDocs\\Apps\\Mail\\Controllers\\MessageController:index');
        $group->get('/messages/{id}', 'KDocs\\Apps\\Mail\\Controllers\\MessageController:show');
        $group->post('/messages', 'KDocs\\Apps\\Mail\\Controllers\\MessageController:send');
        $group->delete('/messages/{id}', 'KDocs\\Apps\\Mail\\Controllers\\MessageController:delete');

        // Recherche
        $group->get('/search', 'KDocs\\Apps\\Mail\\Controllers\\MessageController:search');

        // Calendrier (Phase 3)
        $group->get('/calendar', 'KDocs\\Apps\\Mail\\Controllers\\CalendarController:index');
        $group->get('/calendar/events', 'KDocs\\Apps\\Mail\\Controllers\\CalendarController:events');
        $group->post('/calendar/events', 'KDocs\\Apps\\Mail\\Controllers\\CalendarController:store');

        // API JSON
        $group->group('/api', function (RouteCollectorProxy $api) {
            $api->get('/folders', 'KDocs\\Apps\\Mail\\Controllers\\Api\\MailApiController:folders');
            $api->get('/messages', 'KDocs\\Apps\\Mail\\Controllers\\Api\\MailApiController:messages');
            $api->get('/messages/{id}', 'KDocs\\Apps\\Mail\\Controllers\\Api\\MailApiController:message');
        });
    });
};

<?php
/**
 * Routes shell user — Bibliothèque, Recherche, À traiter, Importer (B1.9)
 */

use KDocs\Controllers\DashboardController;
use KDocs\Controllers\DocumentsController;
use KDocs\Controllers\MyTasksController;
use KDocs\Controllers\SearchController;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group): void {
    $group->get('/', [DashboardController::class, 'index']);
    $group->get('/dashboard', [DashboardController::class, 'index']);

    $group->get('/search', [SearchController::class, 'index']);
    $group->get('/chat', [SearchController::class, 'redirectFromChat']);

    $group->get('/mes-taches', [MyTasksController::class, 'index']);

    $group->get('/documents', [DocumentsController::class, 'index']);
    $group->get('/documents/upload', [DocumentsController::class, 'showUpload']);
    $group->post('/documents/upload', [DocumentsController::class, 'upload']);
};

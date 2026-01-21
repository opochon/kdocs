<?php
/**
 * K-Docs - Point d'entrée principal
 */

// Charger l'autoloader Composer ou l'autoloader simple
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
} else {
    // Utiliser l'autoloader simple si Composer n'est pas disponible
    require_once __DIR__ . '/app/autoload.php';
    
    // Charger Slim Framework manuellement si nécessaire
    // Pour l'instant, on va essayer de charger depuis un chemin local
    if (!class_exists('Slim\\Factory\\AppFactory')) {
        die('
        <!DOCTYPE html>
        <html>
        <head>
            <title>K-Docs - Installation requise</title>
            <style>
                body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
                h1 { color: #dc2626; }
                code { background: #f3f4f6; padding: 2px 6px; border-radius: 3px; }
            </style>
        </head>
        <body>
            <h1>⚠ Installation requise</h1>
            <p>Les dépendances Composer ne sont pas installées.</p>
            <p>Veuillez exécuter la commande suivante dans le répertoire du projet :</p>
            <p><code>composer install</code></p>
            <p>Si Composer n\'est pas installé, téléchargez-le depuis <a href="https://getcomposer.org">getcomposer.org</a></p>
        </body>
        </html>
        ');
    }
}

// Charger les fonctions helper
require_once __DIR__ . '/app/helpers.php';

// Démarrer l'application Slim via la classe App
use KDocs\Core\App;
use KDocs\Controllers\AuthController;
use KDocs\Controllers\DocumentsController;
use KDocs\Controllers\TasksController;
use KDocs\Controllers\AdminController;
use KDocs\Controllers\CorrespondentsController;
use KDocs\Controllers\TagsController;
use KDocs\Controllers\DashboardController;
use KDocs\Controllers\SettingsController;
use KDocs\Controllers\CustomFieldsController;
use KDocs\Controllers\StoragePathsController;
use KDocs\Controllers\WorkflowsController;
use KDocs\Controllers\DocumentTypesController;
use KDocs\Controllers\WebhooksController;
use KDocs\Controllers\AuditLogsController;
use KDocs\Controllers\UsersController;
use KDocs\Controllers\ExportController;
use KDocs\Controllers\MailAccountsController;
use KDocs\Controllers\ScheduledTasksController;
use KDocs\Controllers\Api\ConsumptionApiController;
use KDocs\Controllers\Api\DocumentsApiController;
use KDocs\Controllers\Api\TagsApiController;
use KDocs\Controllers\Api\CorrespondentsApiController;
use KDocs\Middleware\AuthMiddleware;
use KDocs\Core\Config;

$app = App::create();

// Routes publiques (sans authentification)
$app->get('/login', [AuthController::class, 'showLogin']);
$app->post('/login', [AuthController::class, 'login']);
$app->get('/logout', [AuthController::class, 'logout']);

// Helper function pour rendre un template
function renderTemplate($templatePath, $data = []) {
    extract($data);
    ob_start();
    include $templatePath;
    return ob_get_clean();
}

// Traitement automatique en arrière-plan (non bloquant)
// Traite les documents en attente (OCR + métadonnées)
try {
    $processor = new \KDocs\Services\DocumentProcessor();
    $processor->processPendingDocuments(5);
} catch (\Exception $e) {
    // Ignorer les erreurs silencieusement en production
    $config = Config::load();
    if ($config['app']['debug'] ?? false) {
        error_log("Background processing error: " . $e->getMessage());
    }
}

// Routes protégées (avec authentification)
$app->group('', function ($group) {
    // Dashboard (Priorité 2.5)
    $group->get('/', [DashboardController::class, 'index']);
    $group->get('/dashboard', [DashboardController::class, 'index']);
    
    // Documents
    $group->get('/documents', [DocumentsController::class, 'index']);
    $group->get('/documents/upload', [DocumentsController::class, 'showUpload']);
    $group->post('/documents/upload', [DocumentsController::class, 'upload']);
    $group->get('/documents/{id}', [DocumentsController::class, 'show']);
    $group->get('/documents/{id}/edit', [DocumentsController::class, 'showEdit']);  // Priorité 1.2
    $group->post('/documents/{id}/edit', [DocumentsController::class, 'edit']);  // Priorité 1.2
    $group->post('/documents/{id}/delete', [DocumentsController::class, 'delete']);  // Trash
    $group->post('/documents/{id}/restore', [DocumentsController::class, 'restore']);  // Restauration
    $group->get('/documents/{id}/download', [DocumentsController::class, 'download']);
    $group->get('/documents/{id}/view', [DocumentsController::class, 'view']);
    $group->get('/documents/{id}/share', [DocumentsController::class, 'share']);  // Priorité 3.3
    $group->get('/documents/{id}/history', [DocumentsController::class, 'history']);  // Priorité 3.4
    // Routes Notes (web) - AVANT routes API pour éviter conflits
    $group->post('/documents/{id}/notes', [DocumentsController::class, 'addNote']);
    $group->delete('/documents/{id}/notes/{noteId}', [DocumentsController::class, 'deleteNote']);
    
    // API REST Complète (Phase 5)
    // IMPORTANT: Routes statiques AVANT routes variables pour éviter les conflits FastRoute
    
    // API Recherche correspondants (route statique AVANT routes variables)
    $group->get('/api/correspondents/search', [CorrespondentsController::class, 'search']);
    
    // Correspondents API (routes variables APRÈS routes statiques)
    $group->get('/api/correspondents', [CorrespondentsApiController::class, 'index']);
    $group->get('/api/correspondents/{id}', [CorrespondentsApiController::class, 'show']);
    $group->post('/api/correspondents', [CorrespondentsApiController::class, 'create']);
    $group->put('/api/correspondents/{id}', [CorrespondentsApiController::class, 'update']);
    $group->delete('/api/correspondents/{id}', [CorrespondentsApiController::class, 'delete']);
    
    // Tags API
    $group->get('/api/tags', [TagsApiController::class, 'index']);
    $group->get('/api/tags/{id}', [TagsApiController::class, 'show']);
    $group->post('/api/tags', [TagsApiController::class, 'create']);
    $group->put('/api/tags/{id}', [TagsApiController::class, 'update']);
    $group->delete('/api/tags/{id}', [TagsApiController::class, 'delete']);
    
    // Documents API - Routes statiques d'abord
    $group->post('/api/documents/bulk-action', [DocumentsController::class, 'bulkAction']);
    $group->post('/api/documents/upload', [DocumentsController::class, 'apiUpload']);
    
    // Documents API - Routes variables ensuite
    $group->get('/api/documents', [DocumentsApiController::class, 'index']);
    $group->get('/api/documents/{id}', [DocumentsApiController::class, 'show']);
    $group->post('/api/documents', [DocumentsApiController::class, 'create']);
    $group->put('/api/documents/{id}', [DocumentsApiController::class, 'update']);
    $group->delete('/api/documents/{id}', [DocumentsApiController::class, 'delete']);
    
    // API Document Notes (routes avec {id} donc après routes simples)
    $group->get('/api/documents/{id}/notes', [DocumentsController::class, 'listNotes']);
    $group->post('/api/documents/{id}/notes', [DocumentsController::class, 'addNote']);
    $group->delete('/api/documents/{id}/notes/{noteId}', [DocumentsController::class, 'deleteNote']);
    
    // API Classification IA (Bonus)
    $group->post('/api/documents/{id}/classify-ai', [\KDocs\Controllers\Api\DocumentsApiController::class, 'classifyWithAI']);
    $group->post('/api/documents/{id}/apply-ai-suggestions', [\KDocs\Controllers\Api\DocumentsApiController::class, 'applyAISuggestions']);
    
    // API Recherches sauvegardées (Priorité 3.2)
    $group->get('/api/saved-searches', [DocumentsController::class, 'listSavedSearches']);
    $group->post('/api/saved-searches', [DocumentsController::class, 'saveSearch']);
    $group->delete('/api/saved-searches/{id}', [DocumentsController::class, 'deleteSavedSearch']);
    
    // API Scanner
    $group->post('/api/scanner/scan', [DocumentsController::class, 'scanFilesystem']);
    
    // Tâches
    $group->get('/tasks', [TasksController::class, 'index']);
    $group->get('/tasks/create', [TasksController::class, 'showCreate']);
    $group->post('/tasks/create', [TasksController::class, 'create']);
    $group->post('/tasks/{id}/status', [TasksController::class, 'updateStatus']);
    
    // Administration
    $group->get('/admin', [AdminController::class, 'index']);
    // Users (Multi-utilisateurs avancé)
    $group->get('/admin/users', [UsersController::class, 'index']);
    $group->get('/admin/users/create', [UsersController::class, 'showForm']);
    $group->get('/admin/users/{id}/edit', [UsersController::class, 'showForm']);
    $group->post('/admin/users/save', [UsersController::class, 'save']);
    $group->post('/admin/users/{id}/save', [UsersController::class, 'save']);
    $group->post('/admin/users/{id}/delete', [UsersController::class, 'delete']);
    
    // Paramètres système (configurable)
    $group->get('/admin/settings', [SettingsController::class, 'index']);
    $group->post('/admin/settings/save', [SettingsController::class, 'save']);
    
    // Correspondants (Priorité 2.2)
    $group->get('/admin/correspondents', [CorrespondentsController::class, 'index']);
    $group->get('/admin/correspondents/create', [CorrespondentsController::class, 'showForm']);
    $group->get('/admin/correspondents/{id}/edit', [CorrespondentsController::class, 'showForm']);
    $group->post('/admin/correspondents/save', [CorrespondentsController::class, 'save']);
    $group->post('/admin/correspondents/{id}/save', [CorrespondentsController::class, 'save']);
    $group->post('/admin/correspondents/{id}/delete', [CorrespondentsController::class, 'delete']);
    
    // Tags (Priorité 2.3)
    $group->get('/admin/tags', [TagsController::class, 'index']);
    $group->get('/admin/tags/create', [TagsController::class, 'showForm']);
    $group->get('/admin/tags/{id}/edit', [TagsController::class, 'showForm']);
    $group->post('/admin/tags/save', [TagsController::class, 'save']);
    $group->post('/admin/tags/{id}/save', [TagsController::class, 'save']);
    $group->post('/admin/tags/{id}/delete', [TagsController::class, 'delete']);
    
    // Document Types
    $group->get('/admin/document-types', [DocumentTypesController::class, 'index']);
    $group->get('/admin/document-types/create', [DocumentTypesController::class, 'showForm']);
    $group->get('/admin/document-types/{id}/edit', [DocumentTypesController::class, 'showForm']);
    $group->post('/admin/document-types/save', [DocumentTypesController::class, 'save']);
    $group->post('/admin/document-types/{id}/save', [DocumentTypesController::class, 'save']);
    $group->post('/admin/document-types/{id}/delete', [DocumentTypesController::class, 'delete']);
    
    // Custom Fields (Phase 2.1)
    $group->get('/admin/custom-fields', [CustomFieldsController::class, 'index']);
    $group->get('/admin/custom-fields/create', [CustomFieldsController::class, 'showForm']);
    $group->get('/admin/custom-fields/{id}/edit', [CustomFieldsController::class, 'showForm']);
    $group->post('/admin/custom-fields/save', [CustomFieldsController::class, 'save']);
    $group->post('/admin/custom-fields/{id}/save', [CustomFieldsController::class, 'save']);
    $group->post('/admin/custom-fields/{id}/delete', [CustomFieldsController::class, 'delete']);
    
    // Storage Paths (Phase 2.2)
    $group->get('/admin/storage-paths', [StoragePathsController::class, 'index']);
    $group->get('/admin/storage-paths/create', [StoragePathsController::class, 'showForm']);
    $group->get('/admin/storage-paths/{id}/edit', [StoragePathsController::class, 'showForm']);
    $group->post('/admin/storage-paths/save', [StoragePathsController::class, 'save']);
    $group->post('/admin/storage-paths/{id}/save', [StoragePathsController::class, 'save']);
    $group->post('/admin/storage-paths/{id}/delete', [StoragePathsController::class, 'delete']);
    
    // Workflows (Phase 3.3)
    $group->get('/admin/workflows', [WorkflowsController::class, 'index']);
    $group->get('/admin/workflows/create', [WorkflowsController::class, 'showForm']);
    $group->get('/admin/workflows/{id}/edit', [WorkflowsController::class, 'showForm']);
    $group->post('/admin/workflows/save', [WorkflowsController::class, 'save']);
    $group->post('/admin/workflows/{id}/save', [WorkflowsController::class, 'save']);
    $group->post('/admin/workflows/{id}/delete', [WorkflowsController::class, 'delete']);
    
    // Webhooks (Phase 4.3)
    $group->get('/admin/webhooks', [WebhooksController::class, 'index']);
    $group->get('/admin/webhooks/create', [WebhooksController::class, 'showForm']);
    $group->get('/admin/webhooks/{id}/edit', [WebhooksController::class, 'showForm']);
    $group->get('/admin/webhooks/{id}/logs', [WebhooksController::class, 'logs']);
    $group->post('/admin/webhooks/save', [WebhooksController::class, 'save']);
    $group->post('/admin/webhooks/{id}/save', [WebhooksController::class, 'save']);
    $group->post('/admin/webhooks/{id}/delete', [WebhooksController::class, 'delete']);
    $group->post('/admin/webhooks/{id}/test', [WebhooksController::class, 'test']);
    
    // Audit Logs (Phase 5.3)
    $group->get('/admin/audit-logs', [AuditLogsController::class, 'index']);
    
    // Export/Import (Phase 5.4)
    $group->get('/admin/export-import', [ExportController::class, 'index']);
    $group->get('/admin/export-import/export-documents', [ExportController::class, 'exportDocuments']);
    $group->get('/admin/export-import/export-metadata', [ExportController::class, 'exportMetadata']);
    $group->post('/admin/export-import/import', [ExportController::class, 'import']);
    
    // Mail Accounts & Rules (10% manquant - 5%)
    $group->get('/admin/mail-accounts', [MailAccountsController::class, 'index']);
    $group->get('/admin/mail-accounts/create', [MailAccountsController::class, 'showForm']);
    $group->get('/admin/mail-accounts/{id}/edit', [MailAccountsController::class, 'showForm']);
    $group->post('/admin/mail-accounts/save', [MailAccountsController::class, 'save']);
    $group->post('/admin/mail-accounts/{id}/save', [MailAccountsController::class, 'save']);
    $group->post('/admin/mail-accounts/{id}/test', [MailAccountsController::class, 'testConnection']);
    $group->post('/admin/mail-accounts/{id}/process', [MailAccountsController::class, 'process']);
    $group->post('/admin/mail-accounts/{id}/delete', [MailAccountsController::class, 'delete']);
    
    // Scheduled Tasks (10% manquant - 1.5%)
    $group->get('/admin/scheduled-tasks', [ScheduledTasksController::class, 'index']);
    $group->post('/admin/scheduled-tasks/{id}/run', [ScheduledTasksController::class, 'run']);
    $group->post('/admin/scheduled-tasks/process-queue', [ScheduledTasksController::class, 'processQueue']);
    
    // Document Consumption API (10% manquant - 2%)
    $group->post('/api/consumption/consume', [ConsumptionApiController::class, 'consume']);
    $group->post('/api/consumption/consume-batch', [ConsumptionApiController::class, 'consumeBatch']);
})->add(new AuthMiddleware());

// Démarrer l'application
try {
    // #region agent log
    \KDocs\Core\DebugLogger::log('index.php', 'Application start', [
        'requestUri' => $_SERVER['REQUEST_URI'] ?? '',
        'requestMethod' => $_SERVER['REQUEST_METHOD'] ?? ''
    ], 'B');
    // #endregion
    
    $app->run();
    
    // #region agent log
    \KDocs\Core\DebugLogger::log('index.php', 'Application completed successfully', [], 'B');
    // #endregion
} catch (\Exception $e) {
    // #region agent log
    \KDocs\Core\DebugLogger::logException($e, 'index.php - Application error', 'A');
    // #endregion
    error_log("Application error: " . $e->getMessage());
    http_response_code(500);
    echo "Une erreur est survenue. Veuillez réessayer plus tard.";
}

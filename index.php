<?php
/**
 * K-Docs - Point d'entrée principal
 */

// Headers de sécurité
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

// Content Security Policy (CSP)
// Note: 'unsafe-inline' nécessaire pour les styles inline et certains scripts
// En production stricte, utiliser des nonces ou hashes
// OnlyOffice (localhost:8080) ajouté pour prévisualisation documents Office - HTTP et HTTPS
header("Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com http://localhost:8080 https://localhost:8080; " .
    "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com http://localhost:8080 https://localhost:8080; " .
    "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com http://localhost:8080 https://localhost:8080; " .
    "img-src 'self' data: blob: http://localhost:8080 https://localhost:8080; " .
    "connect-src 'self' https://cdn.jsdelivr.net http://localhost:8080 https://localhost:8080 ws://localhost:8080 wss://localhost:8080; " .
    "frame-src 'self' http://localhost:8080 https://localhost:8080; " .
    "object-src 'none'; " .
    "base-uri 'self';"
);

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

// Charger K-Time (app timesheet)
require_once __DIR__ . '/apps/timetrack/Models/Client.php';
require_once __DIR__ . '/apps/timetrack/Models/Project.php';
require_once __DIR__ . '/apps/timetrack/Models/Entry.php';
require_once __DIR__ . '/apps/timetrack/Models/Timer.php';
require_once __DIR__ . '/apps/timetrack/Models/Supply.php';
require_once __DIR__ . '/apps/timetrack/Services/QuickCodeParser.php';
require_once __DIR__ . '/apps/timetrack/Controllers/DashboardController.php';
require_once __DIR__ . '/apps/timetrack/Controllers/EntryController.php';
require_once __DIR__ . '/apps/timetrack/Controllers/TimerController.php';

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
use KDocs\Controllers\WorkflowDesignerPageController;
use KDocs\Controllers\WorkflowDesignerController;
use KDocs\Controllers\DocumentTypesController;
use KDocs\Controllers\WebhooksController;
use KDocs\Controllers\AuditLogsController;
use KDocs\Controllers\UsersController;
use KDocs\Controllers\ExportController;
use KDocs\Controllers\MailAccountsController;
use KDocs\Controllers\ScheduledTasksController;
use KDocs\Controllers\ConsumeController;
use KDocs\Controllers\ClassificationFieldsController;
use KDocs\Controllers\Api\ConsumptionApiController;
use KDocs\Controllers\Api\DocumentsApiController;
use KDocs\Controllers\Api\TagsApiController;
use KDocs\Controllers\Api\DocumentTypesApiController;
use KDocs\Controllers\Api\ClassificationFieldsApiController;
use KDocs\Controllers\Api\CategoryMappingApiController;
use KDocs\Controllers\Api\SuggestedTagsApiController;
use KDocs\Controllers\Api\CorrespondentsApiController;
use KDocs\Controllers\Api\SearchApiController;
use KDocs\Controllers\Api\FoldersApiController;
use KDocs\Controllers\Api\WorkflowApiController;
use KDocs\Controllers\Api\ValidationApiController;
use KDocs\Controllers\Api\NotificationsApiController;
use KDocs\Controllers\Api\UserNotesApiController;
use KDocs\Controllers\Api\ChatApiController;
use KDocs\Controllers\Api\OnlyOfficeApiController;
use KDocs\Controllers\Api\AttributionRulesApiController;
use KDocs\Controllers\Api\ClassificationSuggestionsApiController;
use KDocs\Controllers\Api\InvoiceLineItemsApiController;
use KDocs\Controllers\Api\ClassificationAuditApiController;
use KDocs\Controllers\Api\ClassificationFieldOptionsApiController;
use KDocs\Controllers\Api\ExtractionApiController;
use KDocs\Controllers\Api\EmbeddingsApiController;
use KDocs\Controllers\Api\SemanticSearchApiController;
use KDocs\Controllers\Api\SnapshotsApiController;
use KDocs\Controllers\Api\DocumentVersionsApiController;
use KDocs\Controllers\Admin\AttributionRulesController;
use KDocs\Controllers\Admin\SnapshotsController;
use KDocs\Controllers\MyTasksController;
use KDocs\Controllers\ChatController;
use KDocs\Middleware\AuthMiddleware;
use KDocs\Middleware\AutoIndexMiddleware;
use KDocs\Middleware\RateLimitMiddleware;
use KDocs\Core\Config;

$app = App::create();

// Routes publiques (sans authentification)
$app->get('/login', [AuthController::class, 'showLogin']);
$app->get('/login/', [AuthController::class, 'showLogin']);
$app->post('/login', [AuthController::class, 'login']);
$app->post('/login/', [AuthController::class, 'login']);
$app->get('/logout', [AuthController::class, 'logout']);
$app->get('/logout/', [AuthController::class, 'logout']);

// Workflow Approval - Routes publiques accessibles via token email (style Alfresco)
use KDocs\Controllers\WorkflowApprovalController;
$app->get('/workflow/approve/{token}', [WorkflowApprovalController::class, 'showApprovalPage']);
$app->post('/workflow/approve/{token}', [WorkflowApprovalController::class, 'processApproval']);

// OnlyOffice - Routes publiques pour accès Docker (avec token de sécurité)
$app->get('/api/onlyoffice/public/download/{documentId}/{token}', [OnlyOfficeApiController::class, 'publicDownload']);
$app->post('/api/onlyoffice/public/callback/{documentId}/{token}', [OnlyOfficeApiController::class, 'publicCallback']);

// Thumbnails - Route publique (les miniatures ne sont pas sensibles)
$app->get('/documents/{id}/thumbnail', [DocumentsController::class, 'thumbnail']);

// Health Check - Route publique pour monitoring
$app->get('/health', function($request, $response) {
    $checks = [];
    $status = 'healthy';
    $httpStatus = 200;

    // 1. Database check
    try {
        $db = \KDocs\Core\Database::getInstance();
        $stmt = $db->query('SELECT 1');
        $checks['database'] = ['status' => 'ok', 'message' => 'Connected'];
    } catch (\Exception $e) {
        $checks['database'] = ['status' => 'error', 'message' => $e->getMessage()];
        $status = 'unhealthy';
        $httpStatus = 503;
    }

    // 2. Storage writable check
    $storageDir = __DIR__ . '/storage';
    if (is_writable($storageDir)) {
        $checks['storage'] = ['status' => 'ok', 'message' => 'Writable'];
    } else {
        $checks['storage'] = ['status' => 'error', 'message' => 'Not writable'];
        $status = 'unhealthy';
        $httpStatus = 503;
    }

    // 3. Cache directory check
    $cacheDir = __DIR__ . '/storage/cache';
    if (is_dir($cacheDir) && is_writable($cacheDir)) {
        $checks['cache'] = ['status' => 'ok', 'message' => 'Writable'];
    } else {
        $checks['cache'] = ['status' => 'warning', 'message' => 'Not available'];
    }

    // 4. OCR tools check (cross-platform)
    $ocrAvailable = false;
    if (\KDocs\Helpers\SystemHelper::commandExists('tesseract')) {
        $checks['ocr'] = ['status' => 'ok', 'message' => 'Tesseract available'];
        $ocrAvailable = true;
    } else {
        // Essayer avec le chemin configuré
        $config = \KDocs\Core\Config::load();
        $tesseractPath = $config['ocr']['tesseract_path'] ?? 'tesseract';
        if (file_exists($tesseractPath)) {
            $checks['ocr'] = ['status' => 'ok', 'message' => 'Tesseract available (configured path)'];
            $ocrAvailable = true;
        } else {
            $checks['ocr'] = ['status' => 'warning', 'message' => 'Tesseract not found'];
        }
    }

    // 5. Queue worker check (optional)
    $queueLock = __DIR__ . '/storage/queue_worker.lock';
    if (file_exists($queueLock)) {
        $lockTime = filemtime($queueLock);
        if (time() - $lockTime < 300) { // 5 minutes
            $checks['queue_worker'] = ['status' => 'ok', 'message' => 'Running'];
        } else {
            $checks['queue_worker'] = ['status' => 'warning', 'message' => 'Stale lock file'];
        }
    } else {
        $checks['queue_worker'] = ['status' => 'warning', 'message' => 'Not running'];
    }

    // 6. PHP version check
    $checks['php'] = [
        'status' => version_compare(PHP_VERSION, '8.3.0', '>=') ? 'ok' : 'warning',
        'message' => 'PHP ' . PHP_VERSION
    ];

    // 7. OnlyOffice check
    try {
        $ooService = new \KDocs\Services\OnlyOfficeService();
        if ($ooService->isEnabled()) {
            $checks['onlyoffice'] = [
                'status' => $ooService->isAvailable() ? 'ok' : 'warning',
                'message' => $ooService->isAvailable() ? 'Available' : 'Server not responding'
            ];
        } else {
            $checks['onlyoffice'] = ['status' => 'warning', 'message' => 'Disabled'];
        }
    } catch (\Exception $e) {
        $checks['onlyoffice'] = ['status' => 'error', 'message' => $e->getMessage()];
    }

    // 8. Qdrant (Vector Search) check
    try {
        $vectorService = new \KDocs\Services\VectorSearchService();
        $embeddingsEnabled = \KDocs\Core\Config::get('embeddings.enabled', false);
        if ($embeddingsEnabled) {
            if ($vectorService->isAvailable()) {
                $info = $vectorService->getCollectionInfo();
                $checks['qdrant'] = [
                    'status' => 'ok',
                    'message' => 'Available' . ($info ? " ({$info['vectors_count']} vectors)" : '')
                ];
            } else {
                $checks['qdrant'] = ['status' => 'warning', 'message' => 'Server not responding'];
            }
        } else {
            $checks['qdrant'] = ['status' => 'warning', 'message' => 'Disabled'];
        }
    } catch (\Exception $e) {
        $checks['qdrant'] = ['status' => 'error', 'message' => $e->getMessage()];
    }

    // Build response
    $result = [
        'status' => $status,
        'timestamp' => date('c'),
        'checks' => $checks,
        'version' => '1.0.0'
    ];

    $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus($httpStatus);
});

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

// Déclenchement automatique du crawler (toutes les 10 minutes)
// Vérifie s'il y a des queues et si le dernier crawl date de plus de 10 minutes
try {
    $autoTrigger = new \KDocs\Services\CrawlerAutoTrigger();
    if ($autoTrigger->shouldRun() && $autoTrigger->hasQueues()) {
        $autoTrigger->trigger();
    }
} catch (\Exception $e) {
    // Ignorer les erreurs silencieusement
    $config = Config::load();
    if ($config['app']['debug'] ?? false) {
        error_log("CrawlerAutoTrigger error: " . $e->getMessage());
    }
}

// Routes protégées (avec authentification)
$app->group('', function ($group) {
    // Dashboard (Priorité 2.5)
    $group->get('/', [DashboardController::class, 'index']);
    $group->get('/dashboard', [DashboardController::class, 'index']);
    
    // Chat IA
    $group->get('/chat', [ChatController::class, 'index']);

    // Mes Tâches (centralisé)
    $group->get('/mes-taches', [MyTasksController::class, 'index']);

    // Documents
    $group->get('/documents', [DocumentsController::class, 'index']);
    $group->get('/documents/upload', [DocumentsController::class, 'showUpload']);
    $group->post('/documents/upload', [DocumentsController::class, 'upload']);
    $group->get('/documents/{id}', [DocumentsController::class, 'show']);
    $group->get('/documents/{id}/edit', [DocumentsController::class, 'showEdit']);
    $group->post('/documents/{id}/edit', [DocumentsController::class, 'edit']);
    $group->get('/documents/{id}/onlyoffice', [DocumentsController::class, 'onlyofficeEdit']);
    $group->post('/documents/{id}/delete', [DocumentsController::class, 'delete']);
    $group->post('/documents/{id}/restore', [DocumentsController::class, 'restore']);
    $group->get('/documents/{id}/download', [DocumentsController::class, 'download']);
    $group->get('/documents/{id}/view', [DocumentsController::class, 'view']);
    // Note: thumbnail route is public (defined outside auth group)
    $group->get('/documents/{id}/share', [DocumentsController::class, 'share']);
    $group->get('/documents/{id}/history', [DocumentsController::class, 'history']);
    $group->post('/documents/{id}/notes', [DocumentsController::class, 'addNote']);
    $group->delete('/documents/{id}/notes/{noteId}', [DocumentsController::class, 'deleteNote']);
    
    // API REST Complète
    $group->get('/api/correspondents/search', [CorrespondentsController::class, 'search']);
    $group->get('/api/correspondents', [CorrespondentsApiController::class, 'index']);
    $group->get('/api/correspondents/{id}', [CorrespondentsApiController::class, 'show']);
    $group->post('/api/correspondents', [CorrespondentsApiController::class, 'create']);
    $group->put('/api/correspondents/{id}', [CorrespondentsApiController::class, 'update']);
    $group->delete('/api/correspondents/{id}', [CorrespondentsApiController::class, 'delete']);
    
    $group->get('/api/tags', [TagsApiController::class, 'index']);
    $group->get('/api/tags/{id}', [TagsApiController::class, 'show']);
    $group->post('/api/tags', [TagsApiController::class, 'create']);
    $group->put('/api/tags/{id}', [TagsApiController::class, 'update']);
    $group->delete('/api/tags/{id}', [TagsApiController::class, 'delete']);
    
    $group->get('/api/document-types', [DocumentTypesApiController::class, 'index']);
    $group->get('/api/classification-fields', [ClassificationFieldsApiController::class, 'index']);
    
    $group->post('/api/category-mapping/create-tag', [CategoryMappingApiController::class, 'createTag']);
    $group->post('/api/category-mapping/create-field', [CategoryMappingApiController::class, 'createField']);
    $group->post('/api/category-mapping/create-correspondent', [CategoryMappingApiController::class, 'createCorrespondent']);
    $group->post('/api/category-mapping/map-to-tag', [CategoryMappingApiController::class, 'mapToTag']);
    $group->post('/api/category-mapping/map-to-field', [CategoryMappingApiController::class, 'mapToField']);
    $group->post('/api/category-mapping/map-to-correspondent', [CategoryMappingApiController::class, 'mapToCorrespondent']);
    $group->post('/api/category-mapping/map-to-type', [CategoryMappingApiController::class, 'mapToType']);
    
    $group->post('/api/suggested-tags/mark-irrelevant', [SuggestedTagsApiController::class, 'markIrrelevant']);
    
    $group->post('/api/documents/bulk-action', [DocumentsController::class, 'bulkAction']);
    $group->post('/api/documents/upload', [DocumentsController::class, 'apiUpload']);
    $group->get('/api/documents', [DocumentsApiController::class, 'index']);
    $group->get('/api/documents/{id}', [DocumentsApiController::class, 'show']);
    $group->post('/api/documents', [DocumentsApiController::class, 'create']);
    $group->put('/api/documents/{id}', [DocumentsApiController::class, 'update']);
    $group->delete('/api/documents/{id}', [DocumentsApiController::class, 'delete']);
    
    $group->get('/api/documents/{id}/notes', [DocumentsController::class, 'listNotes']);
    $group->post('/api/documents/{id}/notes', [DocumentsController::class, 'addNote']);
    $group->delete('/api/documents/{id}/notes/{noteId}', [DocumentsController::class, 'deleteNote']);
    
    $group->post('/api/documents/{id}/classify-ai', [DocumentsApiController::class, 'classifyWithAI']);
    $group->post('/api/documents/{id}/analyze-with-ai', [DocumentsApiController::class, 'analyzeWithAI']);
    $group->post('/api/documents/{id}/analyze-complex-with-ai', [DocumentsApiController::class, 'analyzeComplexWithAI']);
    $group->post('/api/documents/{id}/apply-ai-suggestions', [DocumentsApiController::class, 'applyAISuggestions']);
    
    $group->post('/api/search/ask', [SearchApiController::class, 'ask']);
    $group->get('/api/search/quick', [SearchApiController::class, 'quick']);
    $group->get('/api/search/reference', [SearchApiController::class, 'reference']);
    $group->get('/api/documents/{id}/summary', [SearchApiController::class, 'summary']);
    
    $group->get('/api/saved-searches', [DocumentsController::class, 'listSavedSearches']);
    $group->post('/api/saved-searches', [DocumentsController::class, 'saveSearch']);
    $group->delete('/api/saved-searches/{id}', [DocumentsController::class, 'deleteSavedSearch']);
    
    $group->get('/api/workflows', [WorkflowDesignerController::class, 'list']);
    $group->get('/api/workflows/{id}', [WorkflowDesignerController::class, 'get']);
    $group->post('/api/workflows', [WorkflowDesignerController::class, 'create']);
    $group->put('/api/workflows/{id}', [WorkflowDesignerController::class, 'update']);
    $group->delete('/api/workflows/{id}', [WorkflowDesignerController::class, 'delete']);
    $group->post('/api/workflows/{id}/enable', [WorkflowDesignerController::class, 'toggleEnabled']);

    $group->get('/api/workflow/node-catalog', [WorkflowApiController::class, 'getNodeCatalog']);
    $group->get('/api/workflow/node-config/{type}', [WorkflowApiController::class, 'getNodeConfig']);
    $group->get('/api/workflow/options', [WorkflowApiController::class, 'getOptions']);

    $group->get('/api/validation/pending', [ValidationApiController::class, 'getPending']);
    $group->get('/api/validation/statistics', [ValidationApiController::class, 'getStatistics']);
    $group->post('/api/validation/{documentId}/submit', [ValidationApiController::class, 'submit']);
    $group->post('/api/validation/{documentId}/approve', [ValidationApiController::class, 'approve']);
    $group->post('/api/validation/{documentId}/reject', [ValidationApiController::class, 'reject']);
    $group->post('/api/validation/{documentId}/validate', [ValidationApiController::class, 'validate']);
    $group->get('/api/validation/{documentId}/history', [ValidationApiController::class, 'getHistory']);
    $group->get('/api/validation/{documentId}/status', [ValidationApiController::class, 'getStatus']);
    $group->post('/api/validation/{documentId}/status', [ValidationApiController::class, 'setStatus']);
    $group->get('/api/validation/{documentId}/can-validate', [ValidationApiController::class, 'canValidate']);

    $group->get('/api/roles', [ValidationApiController::class, 'getRoles']);
    $group->get('/api/roles/user/{userId}', [ValidationApiController::class, 'getUserRoles']);
    $group->post('/api/roles/user/{userId}/assign', [ValidationApiController::class, 'assignRole']);
    $group->delete('/api/roles/user/{userId}/{roleCode}', [ValidationApiController::class, 'removeRole']);

    $group->get('/api/notifications', [NotificationsApiController::class, 'index']);
    $group->get('/api/notifications/unread', [NotificationsApiController::class, 'unread']);
    $group->get('/api/notifications/count', [NotificationsApiController::class, 'count']);
    $group->post('/api/notifications/{id}/read', [NotificationsApiController::class, 'markRead']);
    $group->post('/api/notifications/read-all', [NotificationsApiController::class, 'markAllRead']);
    $group->delete('/api/notifications/{id}', [NotificationsApiController::class, 'delete']);

    $group->get('/api/chat/conversations', [ChatApiController::class, 'listConversations']);
    $group->post('/api/chat/conversations', [ChatApiController::class, 'createConversation']);
    $group->get('/api/chat/conversations/{id}', [ChatApiController::class, 'getConversation']);
    $group->patch('/api/chat/conversations/{id}', [ChatApiController::class, 'updateConversation']);
    $group->delete('/api/chat/conversations/{id}', [ChatApiController::class, 'deleteConversation']);
    $group->post('/api/chat/conversations/{id}/messages', [ChatApiController::class, 'sendMessage']);

    $group->get('/api/notes', [UserNotesApiController::class, 'index']);
    $group->get('/api/notes/recipients', [UserNotesApiController::class, 'recipients']);
    $group->get('/api/notes/document/{documentId}', [UserNotesApiController::class, 'forDocument']);
    $group->get('/api/notes/{id}', [UserNotesApiController::class, 'show']);
    $group->get('/api/notes/{id}/thread', [UserNotesApiController::class, 'thread']);
    $group->post('/api/notes', [UserNotesApiController::class, 'create']);
    $group->post('/api/notes/{id}/reply', [UserNotesApiController::class, 'reply']);
    $group->post('/api/notes/{id}/read', [UserNotesApiController::class, 'markRead']);
    $group->post('/api/notes/{id}/complete', [UserNotesApiController::class, 'markComplete']);
    $group->delete('/api/notes/{id}', [UserNotesApiController::class, 'delete']);

    $group->get('/api/tasks', [MyTasksController::class, 'apiIndex']);
    $group->get('/api/tasks/counts', [MyTasksController::class, 'apiCounts']);
    $group->get('/api/tasks/summary', [MyTasksController::class, 'apiSummary']);

    $group->post('/api/scanner/scan', [DocumentsController::class, 'scanFilesystem']);
    
    $group->get('/api/folders/documents', [FoldersApiController::class, 'getDocuments']);
    $group->get('/api/folders/children', [FoldersApiController::class, 'getChildren']);
    $group->get('/api/folders/tree', [FoldersApiController::class, 'getTree']);
    $group->get('/api/folders/tree-html', [FoldersApiController::class, 'getTreeHtml']);
    $group->post('/api/folders/crawl', [FoldersApiController::class, 'triggerCrawlApi']);
    $group->post('/api/folders/index', [FoldersApiController::class, 'indexFolder']);
    $group->get('/api/folders/indexing-all', [FoldersApiController::class, 'getAllIndexingStatus']);
    $group->get('/api/folders/crawl-status', [FoldersApiController::class, 'getCrawlStatus']);
    $group->get('/api/folders/indexing-status', [FoldersApiController::class, 'getIndexingStatus']);
    $group->post('/api/folders/counts', [FoldersApiController::class, 'getFolderCounts']);
    $group->post('/api/folders/rename', [FoldersApiController::class, 'renameFolder']);
    $group->post('/api/folders/move', [FoldersApiController::class, 'moveFolder']);
    $group->post('/api/folders/delete', [FoldersApiController::class, 'deleteFolder']);

    $group->get('/api/onlyoffice/status', [OnlyOfficeApiController::class, 'status']);
    $group->get('/api/onlyoffice/config/{documentId}', [OnlyOfficeApiController::class, 'getConfig']);
    $group->get('/api/onlyoffice/download/{documentId}', [OnlyOfficeApiController::class, 'download']);
    $group->post('/api/onlyoffice/callback/{documentId}', [OnlyOfficeApiController::class, 'saveCallback']);

    $group->get('/tasks', [TasksController::class, 'index']);
    $group->get('/tasks/create', [TasksController::class, 'showCreate']);
    $group->post('/tasks/create', [TasksController::class, 'create']);
    $group->post('/tasks/{id}/status', [TasksController::class, 'updateStatus']);
    
    $group->get('/admin', [AdminController::class, 'index']);
    $group->get('/admin/api-usage', [AdminController::class, 'apiUsage']);
    
    $group->get('/admin/users', [UsersController::class, 'index']);
    $group->get('/admin/users/create', [UsersController::class, 'showForm']);
    $group->get('/admin/users/{id}/edit', [UsersController::class, 'showForm']);
    $group->post('/admin/users/save', [UsersController::class, 'save']);
    $group->post('/admin/users/{id}/save', [UsersController::class, 'save']);
    $group->post('/admin/users/{id}/delete', [UsersController::class, 'delete']);
    
    $group->get('/admin/roles', [\KDocs\Controllers\RolesController::class, 'index']);
    $group->get('/admin/roles/{userId}/assign', [\KDocs\Controllers\RolesController::class, 'showAssignForm']);
    $group->post('/admin/roles/{userId}/assign', [\KDocs\Controllers\RolesController::class, 'assign']);
    $group->post('/admin/roles/{userId}/remove/{roleCode}', [\KDocs\Controllers\RolesController::class, 'remove']);

    $group->get('/admin/user-groups', [\KDocs\Controllers\UserGroupsController::class, 'index']);
    $group->get('/admin/user-groups/create', [\KDocs\Controllers\UserGroupsController::class, 'showForm']);
    $group->get('/admin/user-groups/{id}/edit', [\KDocs\Controllers\UserGroupsController::class, 'showForm']);
    $group->post('/admin/user-groups/save', [\KDocs\Controllers\UserGroupsController::class, 'save']);
    $group->post('/admin/user-groups/{id}/save', [\KDocs\Controllers\UserGroupsController::class, 'save']);
    $group->post('/admin/user-groups/{id}/delete', [\KDocs\Controllers\UserGroupsController::class, 'delete']);
    $group->get('/api/user-groups', [\KDocs\Controllers\UserGroupsController::class, 'apiIndex']);
    $group->get('/api/user-groups/{id}', [\KDocs\Controllers\UserGroupsController::class, 'apiShow']);
    
    $group->get('/admin/settings', [SettingsController::class, 'index']);
    $group->post('/admin/settings/save', [SettingsController::class, 'save']);
    
    $group->get('/admin/correspondents', [CorrespondentsController::class, 'index']);
    $group->get('/admin/correspondents/create', [CorrespondentsController::class, 'showForm']);
    $group->get('/admin/correspondents/{id}/edit', [CorrespondentsController::class, 'showForm']);
    $group->post('/admin/correspondents/save', [CorrespondentsController::class, 'save']);
    $group->post('/admin/correspondents/{id}/save', [CorrespondentsController::class, 'save']);
    $group->post('/admin/correspondents/{id}/delete', [CorrespondentsController::class, 'delete']);
    
    $group->get('/admin/tags', [TagsController::class, 'index']);
    $group->get('/admin/tags/create', [TagsController::class, 'showForm']);
    $group->get('/admin/tags/{id}/edit', [TagsController::class, 'showForm']);
    $group->post('/admin/tags/save', [TagsController::class, 'save']);
    $group->post('/admin/tags/{id}/save', [TagsController::class, 'save']);
    $group->post('/admin/tags/{id}/delete', [TagsController::class, 'delete']);
    
    $group->get('/admin/document-types', [DocumentTypesController::class, 'index']);
    $group->get('/admin/document-types/create', [DocumentTypesController::class, 'showForm']);
    $group->get('/admin/document-types/{id}/edit', [DocumentTypesController::class, 'showForm']);
    $group->post('/admin/document-types/save', [DocumentTypesController::class, 'save']);
    $group->post('/admin/document-types/{id}/save', [DocumentTypesController::class, 'save']);
    $group->post('/admin/document-types/{id}/delete', [DocumentTypesController::class, 'delete']);
    
    $group->get('/admin/custom-fields', [CustomFieldsController::class, 'index']);
    $group->get('/admin/custom-fields/create', [CustomFieldsController::class, 'showForm']);
    $group->get('/admin/custom-fields/{id}/edit', [CustomFieldsController::class, 'showForm']);
    $group->post('/admin/custom-fields/save', [CustomFieldsController::class, 'save']);
    $group->post('/admin/custom-fields/{id}/save', [CustomFieldsController::class, 'save']);
    $group->post('/admin/custom-fields/{id}/delete', [CustomFieldsController::class, 'delete']);
    
    $group->get('/admin/storage-paths', [StoragePathsController::class, 'index']);
    $group->get('/admin/storage-paths/create', [StoragePathsController::class, 'showForm']);
    $group->get('/admin/storage-paths/{id}/edit', [StoragePathsController::class, 'showForm']);
    $group->post('/admin/storage-paths/save', [StoragePathsController::class, 'save']);
    $group->post('/admin/storage-paths/{id}/save', [StoragePathsController::class, 'save']);
    $group->post('/admin/storage-paths/{id}/delete', [StoragePathsController::class, 'delete']);
    
    $group->get('/admin/workflows', [WorkflowsController::class, 'index']);
    $group->get('/admin/workflows/create', [WorkflowsController::class, 'showForm']);
    $group->get('/admin/workflows/{id}/edit', [WorkflowsController::class, 'showForm']);
    $group->get('/admin/workflows/action-form-template', [WorkflowsController::class, 'actionFormTemplate']);
    $group->post('/admin/workflows/save', [WorkflowsController::class, 'save']);
    $group->post('/admin/workflows/{id}/save', [WorkflowsController::class, 'save']);
    $group->post('/admin/workflows/{id}/delete', [WorkflowsController::class, 'delete']);
    
    $group->get('/admin/workflows/new/designer', [WorkflowDesignerPageController::class, 'newDesigner']);
    $group->get('/admin/workflows/{id}/designer', [WorkflowDesignerPageController::class, 'designer']);
    
    $group->get('/admin/webhooks', [WebhooksController::class, 'index']);
    $group->get('/admin/webhooks/create', [WebhooksController::class, 'showForm']);
    $group->get('/admin/webhooks/{id}/edit', [WebhooksController::class, 'showForm']);
    $group->get('/admin/webhooks/{id}/logs', [WebhooksController::class, 'logs']);
    $group->post('/admin/webhooks/save', [WebhooksController::class, 'save']);
    $group->post('/admin/webhooks/{id}/save', [WebhooksController::class, 'save']);
    $group->post('/admin/webhooks/{id}/delete', [WebhooksController::class, 'delete']);
    $group->post('/admin/webhooks/{id}/test', [WebhooksController::class, 'test']);
    
    $group->get('/admin/audit-logs', [AuditLogsController::class, 'index']);
    
    $group->get('/admin/export-import', [ExportController::class, 'index']);
    $group->get('/admin/export-import/export-documents', [ExportController::class, 'exportDocuments']);
    $group->get('/admin/export-import/export-metadata', [ExportController::class, 'exportMetadata']);
    $group->post('/admin/export-import/import', [ExportController::class, 'import']);
    
    $group->get('/admin/mail-accounts', [MailAccountsController::class, 'index']);
    $group->get('/admin/mail-accounts/create', [MailAccountsController::class, 'showForm']);
    $group->get('/admin/mail-accounts/{id}/edit', [MailAccountsController::class, 'showForm']);
    $group->post('/admin/mail-accounts/save', [MailAccountsController::class, 'save']);
    $group->post('/admin/mail-accounts/{id}/save', [MailAccountsController::class, 'save']);
    $group->post('/admin/mail-accounts/{id}/test', [MailAccountsController::class, 'testConnection']);
    $group->post('/admin/mail-accounts/{id}/process', [MailAccountsController::class, 'process']);
    $group->post('/admin/mail-accounts/{id}/delete', [MailAccountsController::class, 'delete']);
    
    $group->get('/admin/scheduled-tasks', [ScheduledTasksController::class, 'index']);
    $group->post('/admin/scheduled-tasks/{id}/run', [ScheduledTasksController::class, 'run']);
    $group->post('/admin/scheduled-tasks/process-queue', [ScheduledTasksController::class, 'processQueue']);
    
    $group->get('/admin/consume', [ConsumeController::class, 'index']);
    $group->get('/admin/consume/document-card/{id}', [ConsumeController::class, 'documentCard']);
    $group->post('/admin/consume/scan', [ConsumeController::class, 'scan']);
    $group->post('/admin/consume/rescan', [ConsumeController::class, 'rescan']);
    $group->post('/admin/consume/validate/{id}', [ConsumeController::class, 'validate']);
    
    $group->get('/admin/classification-fields', [ClassificationFieldsController::class, 'index']);
    $group->get('/admin/classification-fields/create', [ClassificationFieldsController::class, 'showForm']);
    $group->get('/admin/classification-fields/{id}/edit', [ClassificationFieldsController::class, 'showForm']);
    $group->post('/admin/classification-fields/save', [ClassificationFieldsController::class, 'save']);
    $group->post('/admin/classification-fields/{id}/save', [ClassificationFieldsController::class, 'save']);
    $group->post('/admin/classification-fields/{id}/delete', [ClassificationFieldsController::class, 'delete']);

    $group->get('/admin/attribution-rules', [AttributionRulesController::class, 'index']);
    $group->get('/admin/attribution-rules/create', [AttributionRulesController::class, 'editor']);
    $group->get('/admin/attribution-rules/{id}/edit', [AttributionRulesController::class, 'editor']);
    $group->get('/admin/attribution-rules/{id}/logs', [AttributionRulesController::class, 'logs']);

    // Snapshots Admin
    $group->get('/admin/snapshots', [SnapshotsController::class, 'index']);
    $group->get('/admin/snapshots/compare', [SnapshotsController::class, 'compare']);
    $group->get('/admin/snapshots/{id}', [SnapshotsController::class, 'show']);
    $group->post('/admin/snapshots/create', [SnapshotsController::class, 'create']);
    $group->post('/admin/snapshots/{id}/delete', [SnapshotsController::class, 'delete']);
    $group->post('/admin/snapshots/{id}/restore', [SnapshotsController::class, 'restore']);

    $group->get('/api/attribution-rules/field-types', [AttributionRulesApiController::class, 'fieldTypes']);
    $group->get('/api/attribution-rules', [AttributionRulesApiController::class, 'index']);
    $group->post('/api/attribution-rules', [AttributionRulesApiController::class, 'create']);
    $group->get('/api/attribution-rules/{id}', [AttributionRulesApiController::class, 'show']);
    $group->put('/api/attribution-rules/{id}', [AttributionRulesApiController::class, 'update']);
    $group->delete('/api/attribution-rules/{id}', [AttributionRulesApiController::class, 'delete']);
    $group->post('/api/attribution-rules/{id}/test', [AttributionRulesApiController::class, 'test']);
    $group->post('/api/attribution-rules/{id}/duplicate', [AttributionRulesApiController::class, 'duplicate']);
    $group->get('/api/attribution-rules/{id}/logs', [AttributionRulesApiController::class, 'logs']);
    $group->post('/api/attribution-rules/process-document', [AttributionRulesApiController::class, 'processDocument']);
    $group->post('/api/attribution-rules/process-batch', [AttributionRulesApiController::class, 'processBatch']);

    $group->get('/api/suggestions/pending', [ClassificationSuggestionsApiController::class, 'listPending']);
    $group->get('/api/suggestions/stats', [ClassificationSuggestionsApiController::class, 'stats']);
    $group->get('/api/documents/{id}/suggestions', [ClassificationSuggestionsApiController::class, 'getForDocument']);
    $group->post('/api/documents/{id}/suggestions/generate', [ClassificationSuggestionsApiController::class, 'generate']);
    $group->post('/api/documents/{id}/suggestions/apply-all', [ClassificationSuggestionsApiController::class, 'applyAll']);
    $group->post('/api/documents/{id}/suggestions/ignore-all', [ClassificationSuggestionsApiController::class, 'ignoreAll']);
    $group->post('/api/documents/{documentId}/suggestions/{suggestionId}/apply', [ClassificationSuggestionsApiController::class, 'apply']);
    $group->post('/api/documents/{documentId}/suggestions/{suggestionId}/ignore', [ClassificationSuggestionsApiController::class, 'ignore']);

    $group->get('/api/documents/{id}/line-items', [InvoiceLineItemsApiController::class, 'index']);
    $group->post('/api/documents/{id}/line-items', [InvoiceLineItemsApiController::class, 'create']);
    $group->post('/api/documents/{id}/line-items/extract', [InvoiceLineItemsApiController::class, 'extract']);
    $group->post('/api/documents/{id}/line-items/reorder', [InvoiceLineItemsApiController::class, 'reorder']);
    $group->delete('/api/documents/{id}/line-items', [InvoiceLineItemsApiController::class, 'deleteAll']);
    $group->get('/api/documents/{id}/line-items/extraction-history', [InvoiceLineItemsApiController::class, 'extractionHistory']);
    $group->get('/api/documents/{documentId}/line-items/{lineId}', [InvoiceLineItemsApiController::class, 'show']);
    $group->put('/api/documents/{documentId}/line-items/{lineId}', [InvoiceLineItemsApiController::class, 'update']);
    $group->delete('/api/documents/{documentId}/line-items/{lineId}', [InvoiceLineItemsApiController::class, 'delete']);

    $group->get('/api/documents/{id}/classification-history', [ClassificationAuditApiController::class, 'documentHistory']);
    $group->get('/api/documents/{id}/classification-compare', [ClassificationAuditApiController::class, 'compare']);
    $group->get('/api/audit/classifications', [ClassificationAuditApiController::class, 'globalHistory']);
    $group->get('/api/audit/classifications/stats', [ClassificationAuditApiController::class, 'stats']);
    $group->get('/api/audit/classifications/export', [ClassificationAuditApiController::class, 'export']);
    $group->post('/api/audit/classifications/{id}/revert', [ClassificationAuditApiController::class, 'revert']);

    $group->get('/api/classification-field-options', [ClassificationFieldOptionsApiController::class, 'index']);
    $group->get('/api/classification-field-options/field/{fieldCode}', [ClassificationFieldOptionsApiController::class, 'getForField']);
    $group->post('/api/classification-field-options', [ClassificationFieldOptionsApiController::class, 'create']);
    $group->post('/api/classification-field-options/import', [ClassificationFieldOptionsApiController::class, 'import']);
    $group->get('/api/classification-field-options/{id}', [ClassificationFieldOptionsApiController::class, 'show']);
    $group->put('/api/classification-field-options/{id}', [ClassificationFieldOptionsApiController::class, 'update']);
    $group->delete('/api/classification-field-options/{id}', [ClassificationFieldOptionsApiController::class, 'delete']);

    $group->get('/api/extraction/templates', [ExtractionApiController::class, 'listTemplates']);
    $group->get('/api/extraction/templates/{id}', [ExtractionApiController::class, 'getTemplate']);
    $group->post('/api/extraction/templates', [ExtractionApiController::class, 'createTemplate']);
    $group->put('/api/extraction/templates/{id}', [ExtractionApiController::class, 'updateTemplate']);
    $group->delete('/api/extraction/templates/{id}', [ExtractionApiController::class, 'deleteTemplate']);
    $group->post('/api/extraction/templates/{id}/options', [ExtractionApiController::class, 'addOption']);
    $group->get('/api/extraction/suggestions/{field_code}', [ExtractionApiController::class, 'getSuggestions']);
    $group->post('/api/documents/{id}/extract', [ExtractionApiController::class, 'extractDocument']);
    $group->get('/api/documents/{id}/extracted', [ExtractionApiController::class, 'getExtracted']);
    $group->post('/api/documents/{id}/extracted/{field_code}/confirm', [ExtractionApiController::class, 'confirmValue']);
    $group->post('/api/documents/{id}/extracted/{field_code}/correct', [ExtractionApiController::class, 'correctValue']);

    // Documents API - Extended endpoints
    $group->get('/api/documents/{id}/content', [DocumentsApiController::class, 'content']);
    $group->get('/api/documents/{id}/thumbnail', [DocumentsApiController::class, 'thumbnail']);
    $group->get('/api/documents/{id}/download', [DocumentsApiController::class, 'download']);
    $group->post('/api/documents/{id}/ocr', [DocumentsApiController::class, 'triggerOcr']);
    $group->post('/api/documents/{id}/tags', [DocumentsApiController::class, 'addTags']);
    $group->delete('/api/documents/{id}/tags/{tagId}', [DocumentsApiController::class, 'removeTag']);
    $group->put('/api/documents/{id}/type', [DocumentsApiController::class, 'updateType']);
    $group->put('/api/documents/{id}/correspondent', [DocumentsApiController::class, 'updateCorrespondent']);
    $group->put('/api/documents/{id}/fields', [DocumentsApiController::class, 'updateFields']);
    $group->post('/api/documents/{id}/classify', [DocumentsApiController::class, 'classify']);

    // Semantic Search & Embeddings API
    $group->get('/api/embeddings/status', [EmbeddingsApiController::class, 'status']);
    $group->post('/api/embeddings/sync', [EmbeddingsApiController::class, 'sync']);
    $group->post('/api/embeddings/cleanup', [EmbeddingsApiController::class, 'cleanup']);
    $group->post('/api/search/semantic', [EmbeddingsApiController::class, 'semanticSearch']);
    $group->post('/api/search/hybrid', [EmbeddingsApiController::class, 'hybridSearch']);
    $group->get('/api/search/similar/{id}', [EmbeddingsApiController::class, 'similar']);
    $group->post('/api/documents/{id}/embed', [EmbeddingsApiController::class, 'embedDocument']);
    $group->delete('/api/documents/{id}/embed', [EmbeddingsApiController::class, 'deleteEmbedding']);

    // Semantic Search API
    $group->get('/api/semantic-search/status', [SemanticSearchApiController::class, 'status']);
    $group->post('/api/semantic-search', [SemanticSearchApiController::class, 'search']);
    $group->get('/api/semantic-search/similar/{documentId}', [SemanticSearchApiController::class, 'similar']);
    $group->post('/api/semantic-search/index/{documentId}', [SemanticSearchApiController::class, 'indexDocument']);
    $group->delete('/api/semantic-search/index/{documentId}', [SemanticSearchApiController::class, 'removeDocument']);
    $group->post('/api/semantic-search/sync', [SemanticSearchApiController::class, 'sync']);
    $group->get('/api/semantic-search/stats', [SemanticSearchApiController::class, 'stats']);
    $group->post('/api/semantic-search/feedback', [SemanticSearchApiController::class, 'feedback']);

    // Snapshots API
    $group->get('/api/snapshots', [SnapshotsApiController::class, 'index']);
    $group->post('/api/snapshots', [SnapshotsApiController::class, 'create']);
    $group->get('/api/snapshots/latest', [SnapshotsApiController::class, 'latest']);
    $group->get('/api/snapshots/compare', [SnapshotsApiController::class, 'compare']);
    $group->post('/api/snapshots/import', [SnapshotsApiController::class, 'import']);
    $group->get('/api/snapshots/{id}', [SnapshotsApiController::class, 'show']);
    $group->delete('/api/snapshots/{id}', [SnapshotsApiController::class, 'delete']);
    $group->get('/api/snapshots/{id}/items', [SnapshotsApiController::class, 'items']);
    $group->post('/api/snapshots/{id}/restore', [SnapshotsApiController::class, 'restore']);
    $group->get('/api/snapshots/{id}/export', [SnapshotsApiController::class, 'export']);

    // Document Versions API
    $group->get('/api/documents/{documentId}/versions', [DocumentVersionsApiController::class, 'index']);
    $group->post('/api/documents/{documentId}/versions', [DocumentVersionsApiController::class, 'create']);
    $group->get('/api/documents/{documentId}/versions/current', [DocumentVersionsApiController::class, 'current']);
    $group->get('/api/documents/{documentId}/versions/diff', [DocumentVersionsApiController::class, 'diff']);
    $group->delete('/api/documents/{documentId}/versions/cleanup', [DocumentVersionsApiController::class, 'cleanup']);
    $group->get('/api/documents/{documentId}/versions/{versionNumber}', [DocumentVersionsApiController::class, 'show']);
    $group->post('/api/documents/{documentId}/versions/{versionNumber}/restore', [DocumentVersionsApiController::class, 'restore']);
    $group->get('/api/documents/{documentId}/versions/{versionNumber}/download', [DocumentVersionsApiController::class, 'download']);

    $group->get('/admin/indexing', [\KDocs\Controllers\IndexingController::class, 'index']);
    $group->get('/admin/indexing/status', [\KDocs\Controllers\IndexingController::class, 'status']);
    $group->post('/admin/indexing/start', [\KDocs\Controllers\IndexingController::class, 'start']);
    $group->post('/admin/indexing/stop', [\KDocs\Controllers\IndexingController::class, 'stop']);
    $group->get('/admin/indexing/logs', [\KDocs\Controllers\IndexingController::class, 'logs']);
    $group->post('/admin/indexing/clear-logs', [\KDocs\Controllers\IndexingController::class, 'clearLogs']);
    $group->post('/admin/indexing/settings', [\KDocs\Controllers\IndexingController::class, 'saveSettings']);
    $group->get('/admin/indexing/worker', [\KDocs\Controllers\IndexingController::class, 'worker']);
    
    $group->post('/api/consumption/consume', [ConsumptionApiController::class, 'consume']);
    $group->post('/api/consumption/consume-batch', [ConsumptionApiController::class, 'consumeBatch']);
    
    $group->post('/api/consume/scan', function($req, $res) {
        $results = (new \KDocs\Services\ConsumeFolderService())->scan();
        $res->getBody()->write(json_encode(['success' => true, 'results' => $results]));
        return $res->withHeader('Content-Type', 'application/json');
    });

    $group->get('/api/msg/status', [\KDocs\Controllers\Api\MSGImportApiController::class, 'status']);
    $group->post('/api/msg/import', [\KDocs\Controllers\Api\MSGImportApiController::class, 'import']);
    $group->get('/api/msg/{id}/attachments', [\KDocs\Controllers\Api\MSGImportApiController::class, 'getAttachments']);
    $group->get('/api/msg/thread/{threadId}', [\KDocs\Controllers\Api\MSGImportApiController::class, 'getThread']);

    $group->get('/api/email-ingestion/logs', function($req, $res) {
        $params = $req->getQueryParams();
        $service = new \KDocs\Services\EmailIngestionService();
        $accountId = isset($params['account_id']) ? (int)$params['account_id'] : null;
        $limit = isset($params['limit']) ? min((int)$params['limit'], 500) : 50;
        $logs = $service->getLogs($accountId, $limit);
        $res->getBody()->write(json_encode(['success' => true, 'logs' => $logs]));
        return $res->withHeader('Content-Type', 'application/json');
    });
    $group->post('/api/email-ingestion/process/{id}', function($req, $res, $args) {
        $service = new \KDocs\Services\EmailIngestionService();
        $result = $service->processAccount((int)$args['id']);
        $res->getBody()->write(json_encode($result));
        return $res->withHeader('Content-Type', 'application/json');
    });
    $group->post('/api/email-ingestion/process-all', function($req, $res) {
        $service = new \KDocs\Services\EmailIngestionService();
        $results = $service->processAllAccounts();
        $res->getBody()->write(json_encode(['success' => true, 'results' => $results]));
        return $res->withHeader('Content-Type', 'application/json');
    });
    $group->post('/api/email-ingestion/test/{id}', function($req, $res, $args) {
        $service = new \KDocs\Services\EmailIngestionService();
        $result = $service->testConnection((int)$args['id']);
        $res->getBody()->write(json_encode($result));
        return $res->withHeader('Content-Type', 'application/json');
    });

    // ========================================
    // K-Time - Application Timesheet
    // ========================================
    $group->get('/time', [\KDocs\Apps\Timetrack\Controllers\DashboardController::class, 'index']);
    $group->get('/time/', [\KDocs\Apps\Timetrack\Controllers\DashboardController::class, 'index']);

    // Entries
    $group->get('/time/entries', [\KDocs\Apps\Timetrack\Controllers\EntryController::class, 'index']);
    $group->post('/time/entries', [\KDocs\Apps\Timetrack\Controllers\EntryController::class, 'store']);
    $group->post('/time/entries/quick', [\KDocs\Apps\Timetrack\Controllers\EntryController::class, 'quickCreate']);
    $group->get('/time/entries/parse', [\KDocs\Apps\Timetrack\Controllers\EntryController::class, 'parsePreview']);
    $group->put('/time/entries/{id}', [\KDocs\Apps\Timetrack\Controllers\EntryController::class, 'update']);
    $group->delete('/time/entries/{id}', [\KDocs\Apps\Timetrack\Controllers\EntryController::class, 'delete']);

    // Timer
    $group->get('/time/timer', [\KDocs\Apps\Timetrack\Controllers\TimerController::class, 'status']);
    $group->post('/time/timer/start', [\KDocs\Apps\Timetrack\Controllers\TimerController::class, 'start']);
    $group->post('/time/timer/pause', [\KDocs\Apps\Timetrack\Controllers\TimerController::class, 'pause']);
    $group->post('/time/timer/resume', [\KDocs\Apps\Timetrack\Controllers\TimerController::class, 'resume']);
    $group->post('/time/timer/stop', [\KDocs\Apps\Timetrack\Controllers\TimerController::class, 'stop']);
    $group->post('/time/timer/cancel', [\KDocs\Apps\Timetrack\Controllers\TimerController::class, 'cancel']);

})->add(new AutoIndexMiddleware())->add(new RateLimitMiddleware(100, 60))->add(new \KDocs\Middleware\CSRFMiddleware())->add(new AuthMiddleware());

// Démarrer l'application
try {
    \KDocs\Core\DebugLogger::log('index.php', 'Application start', [
        'requestUri' => $_SERVER['REQUEST_URI'] ?? '',
        'requestMethod' => $_SERVER['REQUEST_METHOD'] ?? ''
    ], 'B');
    
    $app->run();
    
    \KDocs\Core\DebugLogger::log('index.php', 'Application completed successfully', [], 'B');
} catch (\Exception $e) {
    \KDocs\Core\DebugLogger::logException($e, 'index.php - Application error', 'A');
    error_log("Application error: " . $e->getMessage());
    http_response_code(500);
    echo "Une erreur est survenue. Veuillez réessayer plus tard.";
}

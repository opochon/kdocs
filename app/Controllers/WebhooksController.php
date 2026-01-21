<?php
/**
 * K-Docs - Contrôleur Webhooks
 */

namespace KDocs\Controllers;

use KDocs\Models\Webhook;
use KDocs\Services\WebhookService;
use KDocs\Core\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;

class WebhooksController
{
    private function renderTemplate(string $templatePath, array $data = []): string
    {
        extract($data);
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }

    /**
     * Liste des webhooks
     */
    public function index(Request $request, Response $response): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('WebhooksController::index', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $user = $request->getAttribute('user');
        $webhookModel = new Webhook();
        $webhookService = new WebhookService();
        
        $webhooks = $webhookModel->getAll();
        
        // Ajouter les statistiques pour chaque webhook
        foreach ($webhooks as &$webhook) {
            $webhook['stats'] = $webhookService->getStats($webhook['id'], 7);
            $webhook['events'] = json_decode($webhook['events'], true) ?? [];
        }
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/webhooks.php', [
            'webhooks' => $webhooks,
        ]);

        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Webhooks - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Webhooks'
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Formulaire de création/édition
     */
    public function showForm(Request $request, Response $response, array $args): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('WebhooksController::showForm', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $user = $request->getAttribute('user');
        $webhookModel = new Webhook();
        $webhook = null;
        $id = $args['id'] ?? null;

        if ($id) {
            $webhook = $webhookModel->getById((int)$id);
            if ($webhook) {
                $webhook['events'] = json_decode($webhook['events'], true) ?? [];
            }
        }

        // Liste des événements disponibles
        $availableEvents = [
            'document.created' => 'Document créé',
            'document.updated' => 'Document modifié',
            'document.deleted' => 'Document supprimé',
            'document.restored' => 'Document restauré',
            'document.processed' => 'Document traité (OCR + métadonnées)',
            'tag.added' => 'Tag ajouté',
            'tag.removed' => 'Tag retiré',
            'correspondent.assigned' => 'Correspondant assigné',
            'document_type.changed' => 'Type de document changé',
            'workflow.triggered' => 'Workflow déclenché',
        ];

        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/webhook_form.php', [
            'webhook' => $webhook,
            'availableEvents' => $availableEvents,
        ]);

        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => ($id ? 'Modifier' : 'Créer') . ' un webhook - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => ($id ? 'Modifier' : 'Créer') . ' un webhook'
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Sauvegarde d'un webhook
     */
    public function save(Request $request, Response $response, array $args): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('WebhooksController::save', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $webhookModel = new Webhook();
        $id = !empty($args['id']) ? (int)$args['id'] : null;
        
        $data = $request->getParsedBody();
        
        $name = trim($data['name'] ?? '');
        $url = trim($data['url'] ?? '');
        $events = $data['events'] ?? [];
        $isActive = isset($data['is_active']) && $data['is_active'] === '1';
        $timeout = !empty($data['timeout']) ? (int)$data['timeout'] : 30;
        $retryCount = !empty($data['retry_count']) ? (int)$data['retry_count'] : 3;
        
        // Validation
        if (empty($name) || empty($url)) {
            $basePath = \KDocs\Core\Config::basePath();
            return $response->withHeader('Location', $basePath . '/admin/webhooks')->withStatus(302);
        }
        
        // Validation URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $basePath = \KDocs\Core\Config::basePath();
            return $response->withHeader('Location', $basePath . '/admin/webhooks')->withStatus(302);
        }
        
        // Validation HTTPS pour la sécurité
        if (strpos($url, 'https://') !== 0) {
            $basePath = \KDocs\Core\Config::basePath();
            return $response->withHeader('Location', $basePath . '/admin/webhooks')->withStatus(302);
        }
        
        $webhookData = [
            'name' => $name,
            'url' => $url,
            'events' => $events,
            'is_active' => $isActive,
            'timeout' => $timeout,
            'retry_count' => $retryCount,
        ];
        
        if ($id) {
            $webhookModel->update($id, $webhookData);
        } else {
            $webhookData['secret'] = Webhook::generateSecret();
            $webhookModel->create($webhookData);
        }

        $basePath = \KDocs\Core\Config::basePath();
        return $response->withHeader('Location', $basePath . '/admin/webhooks')->withStatus(302);
    }

    /**
     * Suppression d'un webhook
     */
    public function delete(Request $request, Response $response, array $args): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('WebhooksController::delete', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $webhookModel = new Webhook();
        $id = (int)$args['id'];
        
        $webhookModel->delete($id);

        $basePath = \KDocs\Core\Config::basePath();
        return $response->withHeader('Location', $basePath . '/admin/webhooks')->withStatus(302);
    }

    /**
     * Test d'un webhook
     */
    public function test(Request $request, Response $response, array $args): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('WebhooksController::test', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $webhookService = new WebhookService();
        $id = (int)$args['id'];
        
        $result = $webhookService->testWebhook($id);
        
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Logs d'un webhook
     */
    public function logs(Request $request, Response $response, array $args): Response
        // #region agent log
        \KDocs\Core\DebugLogger::log('WebhooksController::logs', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion

    {
        $user = $request->getAttribute('user');
        $webhookModel = new Webhook();
        $id = (int)$args['id'];
        
        $webhook = $webhookModel->getById($id);
        if (!$webhook) {
            $basePath = \KDocs\Core\Config::basePath();
            return $response->withHeader('Location', $basePath . '/admin/webhooks')->withStatus(302);
        }
        
        $logs = $webhookModel->getLogs($id, 100);
        
        // Décoder les payloads JSON
        foreach ($logs as &$log) {
            $log['payload'] = json_decode($log['payload'], true);
        }
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/webhook_logs.php', [
            'webhook' => $webhook,
            'logs' => $logs,
        ]);

        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Logs Webhook - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Logs: ' . htmlspecialchars($webhook['name'])
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}

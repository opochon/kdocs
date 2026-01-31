<?php
/**
 * K-Docs - Snapshots Admin Controller
 * Gestion des snapshots via l'interface web
 */

namespace KDocs\Controllers\Admin;

use KDocs\Core\Database;
use KDocs\Models\Snapshot;
use KDocs\Services\SnapshotService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SnapshotsController
{
    private SnapshotService $snapshotService;

    public function __construct()
    {
        $this->snapshotService = new SnapshotService();
    }

    /**
     * Liste des snapshots
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = 20;
        $type = $params['type'] ?? null;

        $snapshots = Snapshot::getAll($perPage, ($page - 1) * $perPage, $type);
        $total = Snapshot::count($type);
        $totalPages = ceil($total / $perPage);

        // Statistiques
        $db = Database::getInstance();
        $stats = [
            'total' => Snapshot::count(),
            'manual' => Snapshot::count('manual'),
            'auto' => Snapshot::count('auto'),
            'backup' => Snapshot::count('backup'),
            'total_size' => $this->formatBytes((int)$db->query("
                SELECT COALESCE(SUM(total_size), 0) FROM snapshots
            ")->fetchColumn()),
            'latest' => $db->query("
                SELECT name, created_at FROM snapshots ORDER BY created_at DESC LIMIT 1
            ")->fetch(\PDO::FETCH_ASSOC)
        ];

        $content = $this->renderTemplate(__DIR__ . '/../../../templates/admin/snapshots.php', [
            'snapshots' => $snapshots,
            'stats' => $stats,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'type' => $type
        ]);

        return $this->renderLayout($response, $content, [
            'title' => 'Snapshots',
            'user' => $user,
            'pageTitle' => 'Gestion des snapshots'
        ]);
    }

    /**
     * Détail d'un snapshot
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = (int)$args['id'];

        $snapshot = Snapshot::findById($id);
        if (!$snapshot) {
            return $response->withHeader('Location', url('/admin/snapshots'))->withStatus(302);
        }

        // Items du snapshot
        $params = $request->getQueryParams();
        $entityType = $params['entity'] ?? null;
        $items = Snapshot::getItems($id, $entityType, 100);

        // Delta depuis le précédent
        $delta = Snapshot::calculateDelta($id);

        $content = $this->renderTemplate(__DIR__ . '/../../../templates/admin/snapshot_detail.php', [
            'snapshot' => $snapshot,
            'items' => $items,
            'delta' => $delta,
            'entityType' => $entityType
        ]);

        return $this->renderLayout($response, $content, [
            'title' => 'Snapshot: ' . $snapshot['name'],
            'user' => $user,
            'pageTitle' => 'Détail du snapshot'
        ]);
    }

    /**
     * Créer un snapshot manuel
     */
    public function create(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $user = $request->getAttribute('user');

        $name = $data['name'] ?? 'Snapshot ' . date('Y-m-d H:i');
        $description = $data['description'] ?? null;

        try {
            $snapshotId = $this->snapshotService->createSnapshot(
                $name,
                $description,
                'manual',
                $user['id'] ?? null
            );

            // Flash message
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => "Snapshot \"$name\" créé avec succès"
            ];
        } catch (\Exception $e) {
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => "Erreur: " . $e->getMessage()
            ];
        }

        return $response->withHeader('Location', url('/admin/snapshots'))->withStatus(302);
    }

    /**
     * Supprimer un snapshot
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $snapshot = Snapshot::findById($id);

        if (!$snapshot) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Snapshot non trouvé'];
            return $response->withHeader('Location', url('/admin/snapshots'))->withStatus(302);
        }

        if ($snapshot['snapshot_type'] === 'backup') {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Impossible de supprimer un snapshot de backup'];
            return $response->withHeader('Location', url('/admin/snapshots'))->withStatus(302);
        }

        Snapshot::delete($id);

        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => "Snapshot \"{$snapshot['name']}\" supprimé"
        ];

        return $response->withHeader('Location', url('/admin/snapshots'))->withStatus(302);
    }

    /**
     * Restaurer un snapshot
     */
    public function restore(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $snapshot = Snapshot::findById($id);

        if (!$snapshot) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Snapshot non trouvé'];
            return $response->withHeader('Location', url('/admin/snapshots'))->withStatus(302);
        }

        $data = $request->getParsedBody();
        $options = [
            'documents' => isset($data['documents']),
            'folders' => isset($data['folders']),
            'tags' => isset($data['tags']),
        ];

        try {
            $result = $this->snapshotService->restoreSnapshot($id, $options);

            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => sprintf(
                    "Snapshot restauré: %d documents, %d dossiers, %d tags",
                    $result['documents'] ?? 0,
                    $result['folders'] ?? 0,
                    $result['tags'] ?? 0
                )
            ];
        } catch (\Exception $e) {
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => "Erreur restauration: " . $e->getMessage()
            ];
        }

        return $response->withHeader('Location', url('/admin/snapshots/' . $id))->withStatus(302);
    }

    /**
     * Comparer deux snapshots
     */
    public function compare(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();
        $fromId = (int)($params['from'] ?? 0);
        $toId = (int)($params['to'] ?? 0);

        $snapshots = Snapshot::getAll(100);
        $diff = null;

        if ($fromId && $toId) {
            $diff = $this->snapshotService->compareSnapshots($fromId, $toId);
        }

        $content = $this->renderTemplate(__DIR__ . '/../../../templates/admin/snapshot_compare.php', [
            'snapshots' => $snapshots,
            'fromId' => $fromId,
            'toId' => $toId,
            'diff' => $diff
        ]);

        return $this->renderLayout($response, $content, [
            'title' => 'Comparer les snapshots',
            'user' => $user,
            'pageTitle' => 'Comparaison de snapshots'
        ]);
    }

    /**
     * Helper pour formater les bytes
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }

    /**
     * Helper pour rendre un template
     */
    private function renderTemplate(string $templatePath, array $data = []): string
    {
        extract($data);
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }

    /**
     * Helper pour rendre avec le layout
     */
    private function renderLayout(Response $response, string $content, array $data = []): Response
    {
        $data['content'] = $content;
        $html = $this->renderTemplate(__DIR__ . '/../../../templates/layouts/main.php', $data);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }
}

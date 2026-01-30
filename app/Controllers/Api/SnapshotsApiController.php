<?php
/**
 * K-Docs - API Controller pour les Snapshots
 */

namespace KDocs\Controllers\Api;

use KDocs\Models\Snapshot;
use KDocs\Services\SnapshotService;
use KDocs\Core\Config;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SnapshotsApiController extends ApiController
{
    private SnapshotService $snapshotService;

    public function __construct()
    {
        $this->snapshotService = new SnapshotService();
    }

    /**
     * GET /api/snapshots
     * Liste tous les snapshots avec pagination
     */
    public function index(Request $request, Response $response): Response
    {
        try {
            $params = $request->getQueryParams();
            $pagination = $this->getPaginationParams($params);
            $type = $params['type'] ?? null;

            $snapshots = Snapshot::getAll($pagination['per_page'], $pagination['offset'], $type);
            $total = Snapshot::count($type);

            return $this->paginatedResponse($response, $snapshots, $pagination['page'], $pagination['per_page'], $total);
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/snapshots/{id}
     * Détails d'un snapshot
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int)($args['id'] ?? 0);
            $snapshot = Snapshot::findById($id);

            if (!$snapshot) {
                return $this->errorResponse($response, 'Snapshot not found', 404);
            }

            // Ajouter les statistiques de delta
            $snapshot['delta'] = Snapshot::calculateDelta($id);

            return $this->successResponse($response, $snapshot);
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/snapshots
     * Crée un nouveau snapshot
     */
    public function create(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true) ?? [];

            if (empty($data['name'])) {
                return $this->errorResponse($response, 'Name is required');
            }

            $snapshotId = $this->snapshotService->createSnapshot(
                $data['name'],
                $data['description'] ?? null,
                $data['type'] ?? 'manual',
                $_SESSION['user_id'] ?? null
            );

            $snapshot = Snapshot::findById($snapshotId);

            return $this->successResponse($response, $snapshot, 'Snapshot created', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/snapshots/{id}
     * Supprime un snapshot
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int)($args['id'] ?? 0);
            $snapshot = Snapshot::findById($id);

            if (!$snapshot) {
                return $this->errorResponse($response, 'Snapshot not found', 404);
            }

            if ($snapshot['snapshot_type'] === 'backup') {
                return $this->errorResponse($response, 'Cannot delete backup snapshots', 403);
            }

            Snapshot::delete($id);

            return $this->successResponse($response, ['deleted' => true], 'Snapshot deleted');
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/snapshots/{id}/items
     * Liste les items d'un snapshot
     */
    public function items(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int)($args['id'] ?? 0);
            $snapshot = Snapshot::findById($id);

            if (!$snapshot) {
                return $this->errorResponse($response, 'Snapshot not found', 404);
            }

            $params = $request->getQueryParams();
            $entityType = $params['entity_type'] ?? null;
            $pagination = $this->getPaginationParams($params);

            $items = Snapshot::getItems($id, $entityType, $pagination['per_page'], $pagination['offset']);

            return $this->successResponse($response, [
                'snapshot_id' => $id,
                'items' => $items,
                'count' => count($items),
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/snapshots/{id}/restore
     * Restaure un snapshot
     */
    public function restore(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int)($args['id'] ?? 0);
            $snapshot = Snapshot::findById($id);

            if (!$snapshot) {
                return $this->errorResponse($response, 'Snapshot not found', 404);
            }

            $data = json_decode($request->getBody()->getContents(), true) ?? [];
            $options = [
                'documents' => $data['documents'] ?? true,
                'folders' => $data['folders'] ?? true,
                'tags' => $data['tags'] ?? false,
            ];

            $result = $this->snapshotService->restoreSnapshot($id, $options);

            return $this->successResponse($response, $result, 'Snapshot restored');
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/snapshots/compare
     * Compare deux snapshots
     */
    public function compare(Request $request, Response $response): Response
    {
        try {
            $params = $request->getQueryParams();
            $fromId = (int)($params['from'] ?? 0);
            $toId = (int)($params['to'] ?? 0);

            if (!$fromId || !$toId) {
                return $this->errorResponse($response, 'from and to parameters are required');
            }

            $diff = $this->snapshotService->compareSnapshots($fromId, $toId);

            return $this->successResponse($response, [
                'from_snapshot' => $fromId,
                'to_snapshot' => $toId,
                'diff' => $diff,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/snapshots/{id}/export
     * Exporte un snapshot en JSON
     */
    public function export(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int)($args['id'] ?? 0);
            $snapshot = Snapshot::findById($id);

            if (!$snapshot) {
                return $this->errorResponse($response, 'Snapshot not found', 404);
            }

            $tempPath = Config::get('storage.temp', sys_get_temp_dir());
            $filename = "snapshot_{$id}_" . date('Ymd_His') . '.json';
            $outputPath = $tempPath . DIRECTORY_SEPARATOR . $filename;

            if ($this->snapshotService->exportSnapshot($id, $outputPath)) {
                $content = file_get_contents($outputPath);
                unlink($outputPath);

                $response->getBody()->write($content);
                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
            }

            return $this->errorResponse($response, 'Export failed', 500);
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/snapshots/import
     * Importe un snapshot depuis un fichier JSON
     */
    public function import(Request $request, Response $response): Response
    {
        try {
            $uploadedFiles = $request->getUploadedFiles();

            if (empty($uploadedFiles['file'])) {
                return $this->errorResponse($response, 'No file uploaded');
            }

            $file = $uploadedFiles['file'];
            if ($file->getError() !== UPLOAD_ERR_OK) {
                return $this->errorResponse($response, 'Upload error: ' . $file->getError());
            }

            $tempPath = Config::get('storage.temp', sys_get_temp_dir());
            $tempFile = $tempPath . DIRECTORY_SEPARATOR . uniqid('import_') . '.json';
            $file->moveTo($tempFile);

            $snapshotId = $this->snapshotService->importSnapshot(
                $tempFile,
                $_SESSION['user_id'] ?? null
            );

            unlink($tempFile);

            $snapshot = Snapshot::findById($snapshotId);

            return $this->successResponse($response, $snapshot, 'Snapshot imported', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/snapshots/latest
     * Récupère le dernier snapshot
     */
    public function latest(Request $request, Response $response): Response
    {
        try {
            $params = $request->getQueryParams();
            $type = $params['type'] ?? null;
            $snapshot = Snapshot::getLatest($type);

            if (!$snapshot) {
                return $this->errorResponse($response, 'No snapshot found', 404);
            }

            return $this->successResponse($response, $snapshot);
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }
}

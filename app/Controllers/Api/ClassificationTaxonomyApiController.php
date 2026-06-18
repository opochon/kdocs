<?php

declare(strict_types=1);

namespace KDocs\Controllers\Api;

use KDocs\Models\User;
use KDocs\Services\Classification\TaxonomySyncService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ClassificationTaxonomyApiController extends ApiController
{
    /**
     * POST /api/classification/sync-taxonomy
     * Importe la taxonomie HTMLEDITOR et la stocke en settings.
     */
    public function sync(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$this->isAdmin($user)) {
            return $this->errorResponse($response, 'Accès réservé aux administrateurs', 403);
        }

        $body = $request->getParsedBody();
        $pathOverride = null;
        if (is_array($body) && !empty($body['path'])) {
            $pathOverride = (string) $body['path'];
        }

        $service = new TaxonomySyncService();
        if ($pathOverride === null && !$service->loadFromSource()['available']) {
            return $this->errorResponse(
                $response,
                'Taxonomie HTMLEDITOR introuvable — définir HTMLEDITOR_TAXONOMY_PATH ou fournir path',
                404
            );
        }

        try {
            $result = $service->sync($pathOverride, (int) ($user['id'] ?? 0));
        } catch (\Throwable $e) {
            return $this->errorResponse($response, 'Sync taxonomie échouée : ' . $e->getMessage(), 500);
        }

        return $this->successResponse($response, $result, 'Taxonomie HTMLEDITOR synchronisée');
    }

    /**
     * GET /api/classification/taxonomy
     * Lecture debug (BDD ou fichier live).
     */
    public function show(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$this->isAdmin($user)) {
            return $this->errorResponse($response, 'Accès réservé aux administrateurs', 403);
        }

        $query = $request->getQueryParams();
        $preferStored = !isset($query['live']) || $query['live'] !== '1';

        $service = new TaxonomySyncService();
        $payload = $service->getTaxonomyForDebug($preferStored);

        return $this->successResponse($response, $payload);
    }

    private function isAdmin(?array $user): bool
    {
        if (!$user || empty($user['id'])) {
            return false;
        }

        return User::isInAdminGroup((int) $user['id']);
    }
}

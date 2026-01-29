<?php
/**
 * K-Docs - OnlyOffice API Controller
 * Endpoints pour l'intégration OnlyOffice
 */

namespace KDocs\Controllers\Api;

use KDocs\Models\Document;
use KDocs\Services\OnlyOfficeService;
use KDocs\Core\Config;
use KDocs\Core\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class OnlyOfficeApiController
{
    private OnlyOfficeService $service;

    public function __construct()
    {
        $this->service = new OnlyOfficeService();
    }

    /**
     * GET /api/onlyoffice/config/{documentId}
     * Retourne la configuration pour l'éditeur OnlyOffice
     */
    public function getConfig(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['documentId'];
        $user = $request->getAttribute('user');
        $queryParams = $request->getQueryParams();
        $editMode = ($queryParams['mode'] ?? 'view') === 'edit';

        if (!$this->service->isAvailable()) {
            $error = $this->service->isEnabled()
                ? 'Le serveur OnlyOffice est actuellement inaccessible'
                : 'OnlyOffice n\'est pas configuré sur ce serveur';
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => $error,
                'enabled' => $this->service->isEnabled()
            ], 503);
        }

        $document = Document::findById($documentId);
        if (!$document) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'Document non trouvé'
            ], 404);
        }

        $filename = $document['filename'] ?? $document['original_filename'] ?? '';
        if (!$this->service->isSupported($filename)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'Format de fichier non supporté par OnlyOffice',
                'supported_formats' => $this->service->getSupportedFormats()
            ], 400);
        }

        $userName = $user['username'] ?? $user['first_name'] ?? ('Utilisateur ' . $user['id']);
        $config = $this->service->generateConfig($document, $user['id'], $userName, $editMode);

        return $this->jsonResponse($response, [
            'success' => true,
            'config' => $config,
            'serverUrl' => $this->service->getServerUrl(),
            'documentType' => $this->service->getDocumentType($filename)
        ]);
    }

    /**
     * GET /api/onlyoffice/download/{documentId}
     * OnlyOffice télécharge le fichier via cette URL
     */
    public function download(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['documentId'];

        // Vérifier le token JWT si configuré
        $authHeader = $request->getHeaderLine('Authorization');
        if (!empty($authHeader) && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            $payload = $this->service->verifyToken($token);
            // On pourrait vérifier le payload ici
        }

        $document = Document::findById($documentId);
        if (!$document) {
            return $response->withStatus(404);
        }

        $filePath = $document['file_path'] ?? null;
        if (!$filePath || !file_exists($filePath)) {
            // Essayer de construire le chemin
            $storagePath = Config::get('storage.documents');
            $filePath = $storagePath . '/' . ($document['storage_path'] ?? $document['filename']);

            if (!file_exists($filePath)) {
                return $response->withStatus(404);
            }
        }

        $mimeType = $document['mime_type'] ?? mime_content_type($filePath) ?? 'application/octet-stream';
        $filename = $document['original_filename'] ?? $document['filename'] ?? basename($filePath);

        $stream = fopen($filePath, 'rb');
        $body = new \Slim\Psr7\Stream($stream);

        return $response
            ->withHeader('Content-Type', $mimeType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string)filesize($filePath))
            ->withBody($body);
    }

    /**
     * POST /api/onlyoffice/callback/{documentId}
     * OnlyOffice envoie les modifications via ce callback
     */
    public function saveCallback(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['documentId'];
        $body = json_decode($request->getBody()->getContents(), true);

        // Log du callback pour debug
        error_log('OnlyOffice callback for document ' . $documentId . ': ' . json_encode($body));

        /*
         * Status codes:
         * 0 - no document with the key identifier could be found
         * 1 - document is being edited
         * 2 - document is ready for saving
         * 3 - document saving error has occurred
         * 4 - document is closed with no changes
         * 6 - document is being edited, but the current document state is saved
         * 7 - error has occurred while force saving the document
         */
        $status = $body['status'] ?? 0;

        // Statuts qui nécessitent une sauvegarde
        if (in_array($status, [2, 6])) {
            $downloadUrl = $body['url'] ?? null;

            if ($downloadUrl) {
                $document = Document::findById($documentId);

                if ($document) {
                    $filePath = $document['file_path'] ?? null;
                    if (!$filePath) {
                        $storagePath = Config::get('storage.documents');
                        $filePath = $storagePath . '/' . ($document['storage_path'] ?? $document['filename']);
                    }

                    // Télécharger le fichier modifié
                    $context = stream_context_create([
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                        ]
                    ]);

                    $newContent = @file_get_contents($downloadUrl, false, $context);

                    if ($newContent !== false) {
                        // Sauvegarder le fichier
                        file_put_contents($filePath, $newContent);

                        // Mettre à jour les métadonnées
                        $db = Database::getInstance();
                        $stmt = $db->prepare("
                            UPDATE documents
                            SET checksum = ?, file_size = ?, updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            md5_file($filePath),
                            filesize($filePath),
                            $documentId
                        ]);

                        error_log('OnlyOffice: Document ' . $documentId . ' saved successfully');
                    } else {
                        error_log('OnlyOffice: Failed to download file from ' . $downloadUrl);
                    }
                }
            }
        }

        // OnlyOffice attend {"error": 0} pour confirmer la réception
        return $this->jsonResponse($response, ['error' => 0]);
    }

    /**
     * GET /api/onlyoffice/status
     * Vérifie le statut d'OnlyOffice
     */
    public function status(Request $request, Response $response): Response
    {
        $enabled = $this->service->isEnabled();
        $available = $this->service->isAvailable();

        $status = [
            'enabled' => $enabled,
            'available' => $available,
            'server_url' => $this->service->getServerUrl(),
            'supported_formats' => $this->service->getSupportedFormats(),
        ];

        // Message explicatif
        if (!$enabled) {
            $status['message'] = 'OnlyOffice n\'est pas activé dans la configuration';
        } elseif (!$available) {
            $status['message'] = 'Le serveur OnlyOffice est inaccessible';
        } else {
            $status['message'] = 'OnlyOffice est opérationnel';
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => $status
        ]);
    }

    /**
     * GET /api/onlyoffice/public/download/{documentId}/{token}
     * Route publique pour OnlyOffice Docker - accès avec token de sécurité
     */
    public function publicDownload(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['documentId'];
        $token = $args['token'] ?? '';

        // Vérifier le token de sécurité
        if (!$this->verifyAccessToken($documentId, $token)) {
            error_log("OnlyOffice publicDownload: Invalid token for document $documentId");
            return $response->withStatus(403);
        }

        $document = Document::findById($documentId);
        if (!$document) {
            return $response->withStatus(404);
        }

        $filePath = $document['file_path'] ?? null;
        if (!$filePath || !file_exists($filePath)) {
            $storagePath = Config::get('storage.documents');
            $filePath = $storagePath . '/' . ($document['storage_path'] ?? $document['filename']);

            if (!file_exists($filePath)) {
                error_log("OnlyOffice publicDownload: File not found at $filePath");
                return $response->withStatus(404);
            }
        }

        $mimeType = $document['mime_type'] ?? mime_content_type($filePath) ?? 'application/octet-stream';
        $filename = $document['original_filename'] ?? $document['filename'] ?? basename($filePath);

        $stream = fopen($filePath, 'rb');
        $body = new \Slim\Psr7\Stream($stream);

        return $response
            ->withHeader('Content-Type', $mimeType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string)filesize($filePath))
            ->withBody($body);
    }

    /**
     * POST /api/onlyoffice/public/callback/{documentId}/{token}
     * Route publique pour callback OnlyOffice Docker
     */
    public function publicCallback(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['documentId'];
        $token = $args['token'] ?? '';

        // Vérifier le token de sécurité
        if (!$this->verifyAccessToken($documentId, $token)) {
            error_log("OnlyOffice publicCallback: Invalid token for document $documentId");
            return $this->jsonResponse($response, ['error' => 1], 403);
        }

        $body = json_decode($request->getBody()->getContents(), true);
        error_log('OnlyOffice public callback for document ' . $documentId . ': ' . json_encode($body));

        $status = $body['status'] ?? 0;

        if (in_array($status, [2, 6])) {
            $downloadUrl = $body['url'] ?? null;

            if ($downloadUrl) {
                $document = Document::findById($documentId);

                if ($document) {
                    $filePath = $document['file_path'] ?? null;
                    if (!$filePath) {
                        $storagePath = Config::get('storage.documents');
                        $filePath = $storagePath . '/' . ($document['storage_path'] ?? $document['filename']);
                    }

                    $context = stream_context_create([
                        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
                    ]);

                    $newContent = @file_get_contents($downloadUrl, false, $context);

                    if ($newContent !== false) {
                        file_put_contents($filePath, $newContent);

                        $db = Database::getInstance();
                        $stmt = $db->prepare("UPDATE documents SET checksum = ?, file_size = ?, updated_at = NOW() WHERE id = ?");
                        $stmt->execute([md5_file($filePath), filesize($filePath), $documentId]);

                        error_log('OnlyOffice: Document ' . $documentId . ' saved via public callback');
                    }
                }
            }
        }

        return $this->jsonResponse($response, ['error' => 0]);
    }

    /**
     * Génère un token d'accès pour OnlyOffice
     */
    public static function generateAccessToken(int $documentId): string
    {
        $secret = Config::get('app.key', Config::get('onlyoffice.jwt_secret', 'kdocs-onlyoffice-secret'));
        $data = $documentId . '-' . floor(time() / 3600); // Valide 1 heure
        return substr(hash_hmac('sha256', $data, $secret), 0, 32);
    }

    /**
     * Vérifie un token d'accès
     */
    private function verifyAccessToken(int $documentId, string $token): bool
    {
        $secret = Config::get('app.key', Config::get('onlyoffice.jwt_secret', 'kdocs-onlyoffice-secret'));

        // Vérifier pour l'heure actuelle et l'heure précédente (tolérance)
        for ($i = 0; $i <= 1; $i++) {
            $data = $documentId . '-' . floor(time() / 3600 - $i);
            $expected = substr(hash_hmac('sha256', $data, $secret), 0, 32);
            if (hash_equals($expected, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retourne une réponse JSON
     */
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}

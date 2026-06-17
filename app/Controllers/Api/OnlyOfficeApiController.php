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
    private \KDocs\Services\OnlyOfficeLogger $logger;

    public function __construct()
    {
        $this->service = new OnlyOfficeService();
        $this->logger = \KDocs\Services\OnlyOfficeLogger::getInstance();
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
        $remoteIp = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';

        $this->logger->logRequest('publicDownload', $documentId, [
            'token_length' => strlen($token),
            'remote_ip' => $remoteIp,
        ]);

        // Vérifier le token de sécurité
        if (!$this->verifyAccessToken($documentId, $token)) {
            $this->logger->error("Token verification failed", [
                'document_id' => $documentId,
                'remote_ip' => $remoteIp,
            ]);
            return $response->withStatus(403);
        }

        $document = Document::findById($documentId);
        if (!$document) {
            error_log("OnlyOffice publicDownload: Document $documentId not found in database");
            return $response->withStatus(404);
        }

        // Essayer plusieurs chemins possibles
        $filePath = null;
        $pathsToTry = [];

        // 1. Chemin stocké en base
        if (!empty($document['file_path'])) {
            $pathsToTry[] = $document['file_path'];
            // Version normalisée
            $pathsToTry[] = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $document['file_path']);
        }

        // 2. Chemin basé sur storage_path
        if (!empty($document['storage_path'])) {
            $storagePath = realpath(Config::get('storage.documents')) ?: Config::get('storage.documents');
            $pathsToTry[] = $storagePath . DIRECTORY_SEPARATOR . $document['storage_path'];
        }

        // 3. Chemin basé sur relative_path
        if (!empty($document['relative_path'])) {
            $storagePath = realpath(Config::get('storage.documents')) ?: Config::get('storage.documents');
            $pathsToTry[] = $storagePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $document['relative_path']);
        }

        // 4. Chemin basé sur filename uniquement
        if (!empty($document['filename'])) {
            $storagePath = realpath(Config::get('storage.documents')) ?: Config::get('storage.documents');
            $pathsToTry[] = $storagePath . DIRECTORY_SEPARATOR . $document['filename'];
        }

        // Tester chaque chemin
        foreach ($pathsToTry as $path) {
            if ($path && file_exists($path) && is_file($path)) {
                $filePath = $path;
                break;
            }
        }

        if (!$filePath) {
            $this->logger->logDownload($documentId, 'NOT_FOUND', false, 'File not found after trying all paths');
            $this->logger->debug("Tried paths", ['paths' => $pathsToTry]);
            $this->logger->debug("Document data", [
                'file_path' => $document['file_path'] ?? null,
                'storage_path' => $document['storage_path'] ?? null,
                'relative_path' => $document['relative_path'] ?? null,
                'filename' => $document['filename'] ?? null
            ]);
            return $response->withStatus(404);
        }

        $this->logger->logDownload($documentId, $filePath, true);

        $mimeType = $document['mime_type'] ?? mime_content_type($filePath) ?? 'application/octet-stream';
        $filename = $document['original_filename'] ?? $document['filename'] ?? basename($filePath);

        $stream = fopen($filePath, 'rb');
        if (!$stream) {
            error_log("OnlyOffice publicDownload: Cannot open file $filePath");
            return $response->withStatus(500);
        }
        $body = new \Slim\Psr7\Stream($stream);

        return $response
            ->withHeader('Content-Type', $mimeType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string)filesize($filePath))
            ->withHeader('Access-Control-Allow-Origin', '*')
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
        $remoteIp = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';

        $this->logger->logRequest('publicCallback', $documentId, ['remote_ip' => $remoteIp]);

        // Vérifier le token de sécurité
        if (!$this->verifyAccessToken($documentId, $token)) {
            $this->logger->error("Callback token verification failed", [
                'document_id' => $documentId,
                'remote_ip' => $remoteIp,
            ]);
            return $this->jsonResponse($response, ['error' => 1], 403);
        }

        $body = json_decode($request->getBody()->getContents(), true);
        $status = $body['status'] ?? 0;
        $downloadUrl = $body['url'] ?? null;

        $this->logger->logCallback($documentId, $status, $downloadUrl, $body);

        if (in_array($status, [2, 6])) {
            if ($downloadUrl) {
                $document = Document::findById($documentId);

                if ($document) {
                    $filePath = $document['file_path'] ?? null;
                    if (!$filePath) {
                        $storagePath = Config::get('storage.documents');
                        $filePath = $storagePath . '/' . ($document['storage_path'] ?? $document['filename']);
                    }

                    // Utiliser ssl_verify de la config
                    $sslVerify = Config::get('onlyoffice.ssl_verify', false);
                    $context = stream_context_create([
                        'ssl' => [
                            'verify_peer' => $sslVerify,
                            'verify_peer_name' => $sslVerify,
                        ],
                        'http' => [
                            'timeout' => Config::get('onlyoffice.timeout', 30),
                        ]
                    ]);

                    $this->logger->info("Downloading modified file", [
                        'document_id' => $documentId,
                        'download_url' => $downloadUrl,
                        'ssl_verify' => $sslVerify,
                    ]);

                    $newContent = @file_get_contents($downloadUrl, false, $context);

                    if ($newContent !== false) {
                        file_put_contents($filePath, $newContent);

                        $db = Database::getInstance();
                        $stmt = $db->prepare("UPDATE documents SET checksum = ?, file_size = ?, updated_at = NOW() WHERE id = ?");
                        $stmt->execute([md5_file($filePath), filesize($filePath), $documentId]);

                        $this->logger->info("Document saved successfully", [
                            'document_id' => $documentId,
                            'file_path' => $filePath,
                            'new_size' => filesize($filePath),
                        ]);
                    } else {
                        $this->logger->error("Failed to download modified file", [
                            'document_id' => $documentId,
                            'download_url' => $downloadUrl,
                            'error' => error_get_last()['message'] ?? 'Unknown error',
                        ]);
                    }
                } else {
                    $this->logger->error("Document not found in database", ['document_id' => $documentId]);
                }
            } else {
                $this->logger->warning("Callback status requires save but no download URL provided", [
                    'document_id' => $documentId,
                    'status' => $status,
                ]);
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
     * GET /api/onlyoffice/test-connectivity
     * Test la connectivité OnlyOffice (serveur + callback)
     */
    public function testConnectivity(Request $request, Response $response): Response
    {
        $this->logger->info("Connectivity test initiated");

        $results = $this->service->testConnectivity();

        return $this->jsonResponse($response, [
            'success' => $results['server_health'] && ($results['callback_reachable'] !== false),
            'data' => $results,
        ]);
    }

    /**
     * GET /api/onlyoffice/logs
     * Retourne les logs OnlyOffice récents
     */
    public function getLogs(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $lines = min((int)($queryParams['lines'] ?? 100), 500);

        $logs = $this->service->getRecentLogs($lines);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'logs' => $logs,
                'count' => count($logs),
            ],
        ]);
    }

    /**
     * POST /api/onlyoffice/logs/clear
     * Efface les logs OnlyOffice
     */
    public function clearLogs(Request $request, Response $response): Response
    {
        $this->service->clearLogs();
        $this->logger->info("Logs cleared by user");

        return $this->jsonResponse($response, [
            'success' => true,
            'message' => 'Logs effacés',
        ]);
    }

    /**
     * GET /api/onlyoffice/debug-config
     * Retourne la configuration OnlyOffice (sans secrets)
     */
    public function debugConfig(Request $request, Response $response): Response
    {
        return $this->jsonResponse($response, [
            'success' => true,
            'data' => $this->service->getDebugConfig(),
        ]);
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

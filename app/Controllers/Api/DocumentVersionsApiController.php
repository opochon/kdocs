<?php
/**
 * K-Docs - API Controller pour les Versions de Documents
 */

namespace KDocs\Controllers\Api;

use KDocs\Models\Document;
use KDocs\Models\DocumentVersion;
use KDocs\Core\Config;
use KDocs\Core\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DocumentVersionsApiController extends ApiController
{
    /**
     * GET /api/documents/{documentId}/versions
     * Liste toutes les versions d'un document
     */
    public function index(Request $request, Response $response, array $args): Response
    {
        try {
            $documentId = (int)($args['documentId'] ?? 0);
            $document = Document::findById($documentId);

            if (!$document) {
                return $this->errorResponse($response, 'Document not found', 404);
            }

            $versions = DocumentVersion::getByDocument($documentId);
            $stats = DocumentVersion::getStats($documentId);

            return $this->successResponse($response, [
                'document_id' => $documentId,
                'versions' => $versions,
                'stats' => $stats,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/documents/{documentId}/versions/{versionNumber}
     * Détails d'une version spécifique
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        try {
            $documentId = (int)($args['documentId'] ?? 0);
            $versionNumber = (int)($args['versionNumber'] ?? 0);

            $version = DocumentVersion::findByVersion($documentId, $versionNumber);

            if (!$version) {
                return $this->errorResponse($response, 'Version not found', 404);
            }

            return $this->successResponse($response, $version);
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/documents/{documentId}/versions
     * Crée une nouvelle version (upload d'un fichier modifié)
     */
    public function create(Request $request, Response $response, array $args): Response
    {
        try {
            $documentId = (int)($args['documentId'] ?? 0);
            $document = Document::findById($documentId);

            if (!$document) {
                return $this->errorResponse($response, 'Document not found', 404);
            }

            $uploadedFiles = $request->getUploadedFiles();

            if (empty($uploadedFiles['file'])) {
                return $this->errorResponse($response, 'No file uploaded');
            }

            $file = $uploadedFiles['file'];
            if ($file->getError() !== UPLOAD_ERR_OK) {
                return $this->errorResponse($response, 'Upload error: ' . $file->getError());
            }

            // Générer le nouveau nom de fichier
            $extension = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
            $basePath = Config::get('storage.documents');
            $versionCount = DocumentVersion::countByDocument($documentId) + 1;
            $newFilename = pathinfo($document['filename'], PATHINFO_FILENAME) . "_v{$versionCount}.{$extension}";
            $newPath = $basePath . DIRECTORY_SEPARATOR . $newFilename;

            // Déplacer le fichier
            $file->moveTo($newPath);

            // Calculer le checksum
            $checksum = hash_file('sha256', $newPath);

            // Extraire le contenu si possible
            $contentText = null;
            try {
                $ocrService = new \KDocs\Services\OCRService();
                $contentText = $ocrService->extractText($newPath);
            } catch (\Exception $e) {
                // Ignorer les erreurs d'extraction
            }

            $body = $request->getParsedBody();

            // Créer la version
            $versionId = DocumentVersion::create([
                'document_id' => $documentId,
                'filename' => $newFilename,
                'file_path' => $newPath,
                'file_size' => filesize($newPath),
                'mime_type' => mime_content_type($newPath) ?: 'application/octet-stream',
                'checksum' => $checksum,
                'title' => $document['title'],
                'content_text' => $contentText,
                'changes_summary' => $body['comment'] ?? null,
                'created_by' => $_SESSION['user_id'] ?? null,
                'comment' => $body['comment'] ?? null,
            ]);

            // Mettre à jour le document principal
            $db = Database::getInstance();
            $stmt = $db->prepare("
                UPDATE documents
                SET filename = :filename,
                    file_path = :file_path,
                    file_size = :file_size,
                    checksum = :checksum,
                    content = :content,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                'filename' => $newFilename,
                'file_path' => $newPath,
                'file_size' => filesize($newPath),
                'checksum' => $checksum,
                'content' => $contentText,
                'id' => $documentId,
            ]);

            $version = DocumentVersion::findById($versionId);

            return $this->successResponse($response, $version, 'Version created', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/documents/{documentId}/versions/{versionNumber}/restore
     * Restaure une version précédente
     */
    public function restore(Request $request, Response $response, array $args): Response
    {
        try {
            $documentId = (int)($args['documentId'] ?? 0);
            $versionNumber = (int)($args['versionNumber'] ?? 0);

            $document = Document::findById($documentId);
            if (!$document) {
                return $this->errorResponse($response, 'Document not found', 404);
            }

            $userId = $_SESSION['user_id'] ?? null;
            $newVersionId = DocumentVersion::restore($documentId, $versionNumber, $userId);

            if (!$newVersionId) {
                return $this->errorResponse($response, 'Version not found or restore failed', 404);
            }

            // Mettre à jour le document principal avec les données de la version restaurée
            $restoredVersion = DocumentVersion::findById($newVersionId);
            if ($restoredVersion) {
                $db = Database::getInstance();
                $stmt = $db->prepare("
                    UPDATE documents
                    SET filename = :filename,
                        file_path = :file_path,
                        file_size = :file_size,
                        checksum = :checksum,
                        content = :content,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    'filename' => $restoredVersion['filename'],
                    'file_path' => $restoredVersion['file_path'],
                    'file_size' => $restoredVersion['file_size'],
                    'checksum' => $restoredVersion['checksum'],
                    'content' => $restoredVersion['content_text'],
                    'id' => $documentId,
                ]);
            }

            return $this->successResponse($response, [
                'new_version_id' => $newVersionId,
                'restored_from' => $versionNumber,
            ], 'Version restored');
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/documents/{documentId}/versions/diff
     * Compare deux versions
     */
    public function diff(Request $request, Response $response, array $args): Response
    {
        try {
            $documentId = (int)($args['documentId'] ?? 0);
            $params = $request->getQueryParams();
            $fromVersion = (int)($params['from'] ?? 0);
            $toVersion = (int)($params['to'] ?? 0);
            $type = $params['type'] ?? 'text';

            if (!$fromVersion || !$toVersion) {
                return $this->errorResponse($response, 'from and to parameters are required');
            }

            $diff = DocumentVersion::getDiff($documentId, $fromVersion, $toVersion, $type);

            if (!$diff) {
                return $this->errorResponse($response, 'Diff failed - versions not found', 404);
            }

            return $this->successResponse($response, [
                'document_id' => $documentId,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'diff_type' => $type,
                'diff' => $diff,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/documents/{documentId}/versions/{versionNumber}/download
     * Télécharge une version spécifique
     */
    public function download(Request $request, Response $response, array $args): Response
    {
        try {
            $documentId = (int)($args['documentId'] ?? 0);
            $versionNumber = (int)($args['versionNumber'] ?? 0);

            $version = DocumentVersion::findByVersion($documentId, $versionNumber);

            if (!$version) {
                return $this->errorResponse($response, 'Version not found', 404);
            }

            $filePath = $version['file_path'];
            if (!file_exists($filePath)) {
                return $this->errorResponse($response, 'File not found on disk', 404);
            }

            $content = file_get_contents($filePath);
            $response->getBody()->write($content);

            return $response
                ->withHeader('Content-Type', $version['mime_type'])
                ->withHeader('Content-Disposition', 'attachment; filename="' . $version['filename'] . '"')
                ->withHeader('Content-Length', strlen($content))
                ->withHeader('Cache-Control', 'no-cache');
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/documents/{documentId}/versions/cleanup
     * Supprime les anciennes versions (garde les N dernières)
     */
    public function cleanup(Request $request, Response $response, array $args): Response
    {
        try {
            $documentId = (int)($args['documentId'] ?? 0);
            $document = Document::findById($documentId);

            if (!$document) {
                return $this->errorResponse($response, 'Document not found', 404);
            }

            $params = $request->getQueryParams();
            $keepCount = (int)($params['keep'] ?? 50);
            $deleted = DocumentVersion::pruneOldVersions($documentId, $keepCount);

            return $this->successResponse($response, [
                'deleted_count' => $deleted,
                'kept_count' => $keepCount,
            ], 'Cleanup completed');
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/documents/{documentId}/versions/current
     * Récupère la version courante
     */
    public function current(Request $request, Response $response, array $args): Response
    {
        try {
            $documentId = (int)($args['documentId'] ?? 0);
            $version = DocumentVersion::getCurrent($documentId);

            if (!$version) {
                return $this->errorResponse($response, 'No current version found', 404);
            }

            return $this->successResponse($response, $version);
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }
}

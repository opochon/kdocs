<?php
/**
 * K-Docs - API Controller pour import MSG (Outlook)
 * 
 * Endpoints:
 * - GET  /api/msg/status           - Vérifie si l'import MSG est disponible
 * - POST /api/msg/import           - Importe un fichier MSG avec ses pièces jointes
 * - GET  /api/msg/{id}/attachments - Récupère un mail avec ses pièces jointes
 * - GET  /api/msg/thread/{threadId} - Récupère tous les documents d'un thread
 */

namespace KDocs\Controllers\Api;

use KDocs\Services\MSGImportService;
use KDocs\Core\Config;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MSGImportApiController extends ApiController
{
    /**
     * Vérifie si l'import MSG est disponible
     * GET /api/msg/status
     */
    public function status(Request $request, Response $response): Response
    {
        $service = new MSGImportService();
        $available = $service->isAvailable();
        
        return $this->successResponse($response, [
            'available' => $available,
            'message' => $service->getInstallMessage()
        ]);
    }
    
    /**
     * Importe un fichier MSG uploadé avec ses pièces jointes
     * POST /api/msg/import
     */
    public function import(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $userId = $user['id'] ?? null;
        
        $uploadedFiles = $request->getUploadedFiles();
        
        if (empty($uploadedFiles['file'])) {
            return $this->errorResponse($response, 'Fichier MSG requis', 400);
        }
        
        $file = $uploadedFiles['file'];
        
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->errorResponse($response, 'Erreur upload: ' . $file->getError(), 400);
        }
        
        // Vérifier l'extension
        $filename = $file->getClientFilename();
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if ($ext !== 'msg') {
            return $this->errorResponse($response, 'Seuls les fichiers .msg sont acceptés', 400);
        }
        
        // Sauvegarder temporairement
        $config = Config::load();
        $tempDir = $config['storage']['temp'] ?? __DIR__ . '/../../../storage/temp';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $tempPath = $tempDir . '/' . uniqid('upload_') . '.msg';
        
        $file->moveTo($tempPath);
        
        try {
            $service = new MSGImportService();
            
            if (!$service->isAvailable()) {
                @unlink($tempPath);
                return $this->errorResponse($response, $service->getInstallMessage(), 503);
            }
            
            $result = $service->importWithAttachments($tempPath, $userId);
            
            @unlink($tempPath);
            
            if (!$result['success']) {
                return $this->errorResponse($response, $result['error'] ?? 'Import échoué', 500);
            }
            
            // Récupérer les détails du mail importé
            $mailData = $service->getMailWithAttachments($result['mail_id']);
            
            return $this->successResponse($response, [
                'mail_id' => $result['mail_id'],
                'attachment_ids' => $result['attachment_ids'],
                'thread_id' => $result['thread_id'],
                'mail' => $this->formatDocument($mailData['mail']),
                'attachments' => array_map([$this, 'formatDocument'], $mailData['attachments']),
            ], 'MSG importé avec ' . count($result['attachment_ids']) . ' pièce(s) jointe(s)');
            
        } catch (\Exception $e) {
            @unlink($tempPath);
            return $this->errorResponse($response, 'Erreur: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Récupère un mail avec ses pièces jointes
     * GET /api/msg/{id}/attachments
     */
    public function getAttachments(Request $request, Response $response, array $args): Response
    {
        $mailId = (int) $args['id'];
        
        $service = new MSGImportService();
        $data = $service->getMailWithAttachments($mailId);
        
        if (!$data['mail']) {
            return $this->errorResponse($response, 'Document non trouvé', 404);
        }
        
        return $this->successResponse($response, [
            'mail' => $this->formatDocument($data['mail']),
            'attachments' => array_map([$this, 'formatDocument'], $data['attachments']),
        ]);
    }
    
    /**
     * Récupère tous les documents d'un thread
     * GET /api/msg/thread/{threadId}
     */
    public function getThread(Request $request, Response $response, array $args): Response
    {
        $threadId = $args['threadId'];
        
        $service = new MSGImportService();
        $documents = $service->getThreadDocuments($threadId);
        
        return $this->successResponse($response, [
            'thread_id' => $threadId,
            'count' => count($documents),
            'documents' => array_map([$this, 'formatDocument'], $documents),
        ]);
    }
    
    /**
     * Formate un document pour l'API
     */
    private function formatDocument(?array $doc): ?array
    {
        if (!$doc) return null;
        
        $metadata = json_decode($doc['metadata'] ?? '{}', true);
        
        return [
            'id' => (int) $doc['id'],
            'title' => $doc['title'],
            'filename' => $doc['filename'],
            'original_filename' => $doc['original_filename'],
            'file_size' => (int) ($doc['file_size'] ?? 0),
            'mime_type' => $doc['mime_type'],
            'doc_date' => $doc['doc_date'] ?? null,
            'correspondent_id' => $doc['correspondent_id'] ? (int) $doc['correspondent_id'] : null,
            'correspondent_name' => $doc['correspondent_name'] ?? null,
            'parent_id' => isset($doc['parent_id']) && $doc['parent_id'] ? (int) $doc['parent_id'] : null,
            'created_at' => $doc['created_at'],
            'thumbnail_url' => $doc['thumbnail_path'] 
                ? '/kdocs/storage/thumbnails/' . $doc['thumbnail_path'] 
                : null,
            // Métadonnées email
            'is_email' => ($metadata['type'] ?? '') === 'email',
            'is_attachment' => ($metadata['type'] ?? '') === 'email_attachment',
            'sender' => $metadata['sender'] ?? null,
            'sender_email' => $metadata['sender_email'] ?? null,
            'recipients' => $metadata['recipients'] ?? null,
            'thread_id' => $metadata['thread_id'] ?? null,
            'attachments_count' => $metadata['attachments_count'] ?? 0,
            'attachments_imported' => $metadata['attachments_imported'] ?? 0,
            'mail_subject' => $metadata['mail_subject'] ?? null,
        ];
    }
}

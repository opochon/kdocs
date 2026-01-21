<?php
/**
 * K-Docs - API Consumption Controller
 * API pour consommer des documents depuis des sources externes
 */

namespace KDocs\Controllers\Api;

use KDocs\Models\Document;
use KDocs\Services\DocumentProcessor;
use KDocs\Services\FileRenamingService;
use KDocs\Core\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ConsumptionApiController
{
    /**
     * Consomme un document depuis une source externe
     * POST /api/consumption/consume
     */
    public function consume(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();
        
        // Vérifier l'authentification (API key ou token)
        $apiKey = $request->getHeaderLine('X-API-Key');
        if (!$this->validateApiKey($apiKey)) {
            return $response->withStatus(401)->withJson(['error' => 'Unauthorized']);
        }
        
        // Vérifier qu'un fichier est fourni
        if (empty($uploadedFiles['file'])) {
            return $response->withStatus(400)->withJson(['error' => 'No file provided']);
        }
        
        $uploadedFile = $uploadedFiles['file'];
        
        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            return $response->withStatus(400)->withJson(['error' => 'Upload error']);
        }
        
        try {
            // Sauvegarder le fichier temporairement
            $tempFile = tempnam(sys_get_temp_dir(), 'consumption_');
            $uploadedFile->moveTo($tempFile);
            
            // Créer le document
            $documentData = [
                'original_filename' => $uploadedFile->getClientFilename(),
                'title' => $data['title'] ?? $uploadedFile->getClientFilename(),
                'document_date' => $data['document_date'] ?? null,
                'correspondent_id' => $data['correspondent_id'] ?? null,
                'document_type_id' => $data['document_type_id'] ?? null,
                'storage_path_id' => $data['storage_path_id'] ?? null,
                'created_by' => $data['user_id'] ?? null
            ];
            
            // Créer le document
            $documentId = Document::createFromFile($tempFile, $documentData);
            
            // Assigner les tags
            if (!empty($data['tag_ids']) && is_array($data['tag_ids'])) {
                $db = Database::getInstance();
                foreach ($data['tag_ids'] as $tagId) {
                    $db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)")
                       ->execute([$documentId, (int)$tagId]);
                }
            }
            
            // Appliquer les règles de renommage
            FileRenamingService::applyRules($documentId);
            
            // Ajouter à la queue pour traitement en arrière-plan
            \KDocs\Services\TaskService::queue('process_document', ['document_id' => $documentId], 5);
            
            // Nettoyer le fichier temporaire
            unlink($tempFile);
            
            return $response->withJson([
                'success' => true,
                'document_id' => $documentId,
                'message' => 'Document consommé avec succès'
            ]);
            
        } catch (\Exception $e) {
            if (isset($tempFile) && file_exists($tempFile)) {
                unlink($tempFile);
            }
            return $response->withStatus(500)->withJson(['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Consomme plusieurs documents
     * POST /api/consumption/consume-batch
     */
    public function consumeBatch(Request $request, Response $response): Response
    {
        $apiKey = $request->getHeaderLine('X-API-Key');
        if (!$this->validateApiKey($apiKey)) {
            return $response->withStatus(401)->withJson(['error' => 'Unauthorized']);
        }
        
        $uploadedFiles = $request->getUploadedFiles();
        $results = [];
        
        foreach ($uploadedFiles as $file) {
            if ($file->getError() === UPLOAD_ERR_OK) {
                try {
                    $tempFile = tempnam(sys_get_temp_dir(), 'consumption_');
                    $file->moveTo($tempFile);
                    
                    $documentData = [
                        'original_filename' => $file->getClientFilename(),
                        'title' => $file->getClientFilename()
                    ];
                    
                    $documentId = Document::createFromFile($tempFile, $documentData);
                    \KDocs\Services\TaskService::queue('process_document', ['document_id' => $documentId], 5);
                    
                    $results[] = ['success' => true, 'document_id' => $documentId, 'filename' => $file->getClientFilename()];
                    unlink($tempFile);
                } catch (\Exception $e) {
                    $results[] = ['success' => false, 'filename' => $file->getClientFilename(), 'error' => $e->getMessage()];
                }
            }
        }
        
        return $response->withJson([
            'success' => true,
            'processed' => count($results),
            'results' => $results
        ]);
    }
    
    /**
     * Valide une clé API
     */
    private function validateApiKey(?string $apiKey): bool
    {
        if (!$apiKey) {
            return false;
        }
        
        // Vérifier dans la base de données ou la config
        $validKeys = \KDocs\Core\Config::get('api.keys', []);
        return in_array($apiKey, $validKeys);
    }
}

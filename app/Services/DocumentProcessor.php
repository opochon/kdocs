<?php
/**
 * K-Docs - Service de traitement de documents
 * Enchaîne OCR → Matching → Thumbnail → Workflows
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Core\Config;
use KDocs\Models\Document;
use KDocs\Services\WebhookService;
use KDocs\Services\WorkflowEngine;

class DocumentProcessor
{
    private OCRService $ocrService;
    private MetadataExtractor $metadataExtractor;
    private ThumbnailGenerator $thumbnail;
    private $db;
    
    public function __construct()
    {
        $this->ocrService = new OCRService();
        $this->metadataExtractor = new MetadataExtractor();
        $this->thumbnail = new ThumbnailGenerator();
        $this->db = Database::getInstance();
    }
    
    /**
     * Traitement complet d'un document (OCR → Matching → Thumbnail → Workflows)
     * 
     * @param int $documentId ID du document à traiter
     * @return array Résultats du traitement
     */
    public function process(int $documentId): array
    {
        $document = Document::findById($documentId);
        if (!$document) {
            throw new \Exception("Document introuvable: {$documentId}");
        }
        
        $results = [
            'ocr' => false,
            'matching' => [],
            'thumbnail' => false,
            'workflows' => []
        ];
        
        // Récupérer le chemin du fichier
        // Utiliser file_path si disponible, sinon construire depuis storage_path ou filename
        $filePath = null;
        
        if (!empty($document['file_path']) && file_exists($document['file_path'])) {
            $filePath = $document['file_path'];
        } else {
            $config = Config::load();
            $storageType = Config::get('storage.type', 'local');
            
            if ($storageType === 'kdrive') {
                // Pour KDrive, télécharger le fichier temporairement
                $relativePath = $document['storage_path'] ?? $document['filename'] ?? $document['original_filename'] ?? '';
                if ($relativePath) {
                    $tempDir = $config['storage']['temp'] ?? __DIR__ . '/../../storage/temp';
                    if (!is_dir($tempDir)) {
                        @mkdir($tempDir, 0755, true);
                    }
                    $localTempPath = $tempDir . DIRECTORY_SEPARATOR . basename($relativePath);
                    
                    // Télécharger depuis KDrive
                    $filesystemReader = new \KDocs\Services\FilesystemReader();
                    if ($filesystemReader->downloadFile($relativePath, $localTempPath)) {
                        $filePath = $localTempPath;
                    } else {
                        throw new \Exception("Impossible de télécharger le fichier depuis KDrive: {$relativePath}");
                    }
                }
            } else {
                // Stockage local
                $basePath = Config::get('storage.base_path', __DIR__ . '/../../storage/documents');
                $resolved = realpath($basePath);
                $documentsDir = rtrim($resolved ?: $basePath, '/\\');
                $relativePath = $document['storage_path'] ?? $document['filename'] ?? $document['original_filename'] ?? '';
                $filePath = $documentsDir . DIRECTORY_SEPARATOR . $relativePath;
            }
        }
        
        if (!$filePath || !file_exists($filePath)) {
            throw new \Exception("Fichier introuvable: " . ($filePath ?? 'chemin non défini'));
        }
        
        // 1. OCR si pas de contenu
        if (empty($document['content'])) {
            try {
                $content = $this->ocrService->extractText($filePath);
                if ($content) {
                    $stmt = $this->db->prepare("UPDATE documents SET content = ? WHERE id = ?");
                    $stmt->execute([$content, $documentId]);
                    $document['content'] = $content;
                    $results['ocr'] = true;
                }
            } catch (\Exception $e) {
                error_log("Erreur OCR document {$documentId}: " . $e->getMessage());
            }
        }
        
        // 2. Matching automatique
        $documentText = ($document['title'] ?? '') . ' ' . ($document['ocr_text'] ?? '') . ' ' . ($document['content'] ?? '');
        if (!empty($documentText)) {
            try {
                $matches = MatchingService::findMatches($documentText);
                
                // Appliquer les tags
                foreach ($matches['tags'] as $tagId) {
                    $this->db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)")
                       ->execute([$documentId, $tagId]);
                }
                
                // Appliquer le premier correspondent trouvé (si pas déjà assigné)
                if (!empty($matches['correspondents']) && empty($document['correspondent_id'])) {
                    $this->db->prepare("UPDATE documents SET correspondent_id = ? WHERE id = ?")
                       ->execute([$matches['correspondents'][0], $documentId]);
                }
                
                // Appliquer le premier type trouvé (si pas déjà assigné)
                if (!empty($matches['document_types']) && empty($document['document_type_id'])) {
                    $this->db->prepare("UPDATE documents SET document_type_id = ? WHERE id = ?")
                       ->execute([$matches['document_types'][0], $documentId]);
                }
                
                // Appliquer le premier storage path trouvé (si pas déjà assigné)
                if (!empty($matches['storage_paths']) && empty($document['storage_path_id'])) {
                    $this->db->prepare("UPDATE documents SET storage_path_id = ? WHERE id = ?")
                       ->execute([$matches['storage_paths'][0], $documentId]);
                }
                
                $results['matching'] = $matches;
            } catch (\Exception $e) {
                error_log("Erreur matching document {$documentId}: " . $e->getMessage());
            }
        }
        
        // 3. Générer thumbnail
        if (empty($document['thumbnail_path'])) {
            try {
                $thumbFilename = $this->thumbnail->generate($filePath, $documentId);
                if ($thumbFilename) {
                    $this->db->prepare("UPDATE documents SET thumbnail_path = ? WHERE id = ?")
                       ->execute([$thumbFilename, $documentId]);
                    $results['thumbnail'] = true;
                }
            } catch (\Exception $e) {
                error_log("Erreur thumbnail document {$documentId}: " . $e->getMessage());
            }
        }
        
        // 4. Exécuter les workflows (nouveau moteur unifié)
        try {
            $workflowEngine = new WorkflowEngine();
            $workflowResults = $workflowEngine->executeForEvent('document_added', $documentId);
            $results['workflows'] = $workflowResults;
        } catch (\Exception $e) {
            error_log("Erreur workflows document {$documentId}: " . $e->getMessage());
            $results['workflows'] = ['executed' => false, 'error' => $e->getMessage()];
        }
        
        // 5. Extraire les métadonnées et mettre à jour
        try {
            $metadata = $this->metadataExtractor->extractMetadata($documentText, $document['original_filename'] ?? '');
            $this->updateDocument($documentId, $documentText, $metadata);
        } catch (\Exception $e) {
            error_log("Erreur extraction métadonnées document {$documentId}: " . $e->getMessage());
        }
        
        // 6. Marquer comme indexé
        $this->db->prepare("UPDATE documents SET is_indexed = TRUE, indexed_at = NOW() WHERE id = ?")
           ->execute([$documentId]);
        
        // 7. Déclencher webhook document.processed
        try {
            $webhookService = new WebhookService();
            $processedDocument = Document::findById($documentId);
            if ($processedDocument) {
                $webhookService->trigger('document.processed', [
                    'id' => $documentId,
                    'title' => $processedDocument['title'] ?? $processedDocument['original_filename'],
                    'is_indexed' => true,
                    'indexed_at' => $processedDocument['indexed_at'] ?? date('c'),
                ]);
            }
        } catch (\Exception $e) {
            // Ne pas bloquer le traitement si le webhook échoue
            error_log("Erreur webhook document.processed: " . $e->getMessage());
        }
        
        return $results;
    }
    
    /**
     * Traitement d'un document (méthode legacy, utilise process())
     * 
     * @deprecated Utiliser process() à la place
     */
    public function processDocument(int $documentId): bool
    {
        try {
            $this->process($documentId);
            return true;
        } catch (\Exception $e) {
            error_log("Erreur traitement document {$documentId}: " . $e->getMessage());
            return false;
        }
    }
    
    public function processPendingDocuments(int $limit = 10): array
    {
        $stmt = $this->db->prepare("SELECT id FROM documents WHERE (is_indexed = FALSE OR is_indexed IS NULL) ORDER BY created_at ASC LIMIT ?");
        $stmt->execute([$limit]);
        $stats = ['processed' => 0, 'errors' => 0];
        foreach ($stmt->fetchAll() as $doc) {
            try {
                $this->process($doc['id']);
                $stats['processed']++;
            } catch (\Exception $e) {
                $stats['errors']++;
                error_log("Erreur traitement document {$doc['id']}: " . $e->getMessage());
            }
        }
        return $stats;
    }
    
    /**
     * Retraiter tous les documents sans contenu
     * 
     * @return array Statistiques du retraitement
     */
    public function reprocessAll(): array
    {
        $docs = $this->db->query("SELECT id FROM documents WHERE content IS NULL OR content = ''")->fetchAll();
        
        $results = ['total' => count($docs), 'success' => 0, 'errors' => []];
        
        foreach ($docs as $doc) {
            try {
                $this->process($doc['id']);
                $results['success']++;
            } catch (\Exception $e) {
                $results['errors'][] = "Doc #{$doc['id']}: " . $e->getMessage();
            }
        }
        
        return $results;
    }
    
    private function updateDocument(int $documentId, ?string $text, array $metadata): void
    {
        $stmt = $this->db->prepare("UPDATE documents SET title = COALESCE(?, title), content = ?, document_date = ?, amount = ?, is_indexed = TRUE, indexed_at = NOW() WHERE id = ?");
        $stmt->execute([$metadata['title'] ?? null, $text, $metadata['date'] ?? null, $metadata['amount'] ?? null, $documentId]);
        
        if (!empty($metadata['document_type'])) {
            $typeStmt = $this->db->prepare("SELECT id FROM document_types WHERE code = ? OR label LIKE ? LIMIT 1");
            $typeStmt->execute([$metadata['document_type'], '%' . $metadata['document_type'] . '%']);
            if ($type = $typeStmt->fetch()) {
                $this->db->prepare("UPDATE documents SET document_type_id = ? WHERE id = ?")->execute([$type['id'], $documentId]);
            }
        }
        
        if (!empty($metadata['correspondent'])) {
            $corrStmt = $this->db->prepare("SELECT id FROM correspondents WHERE name = ? LIMIT 1");
            $corrStmt->execute([$metadata['correspondent']]);
            $correspondent = $corrStmt->fetch();
            if (!$correspondent) {
                $this->db->prepare("INSERT INTO correspondents (name) VALUES (?)")->execute([$metadata['correspondent']]);
                $correspondentId = $this->db->lastInsertId();
            } else {
                $correspondentId = $correspondent['id'];
            }
            $this->db->prepare("UPDATE documents SET correspondent_id = ? WHERE id = ?")->execute([$correspondentId, $documentId]);
        }
        
        if (!empty($metadata['tags']) && is_array($metadata['tags'])) {
            foreach ($metadata['tags'] as $tagName) {
                $tagStmt = $this->db->prepare("SELECT id FROM tags WHERE name = ? LIMIT 1");
                $tagStmt->execute([$tagName]);
                $tag = $tagStmt->fetch();
                if (!$tag) {
                    $this->db->prepare("INSERT INTO tags (name) VALUES (?)")->execute([$tagName]);
                    $tagId = $this->db->lastInsertId();
                } else {
                    $tagId = $tag['id'];
                }
                $this->db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)")->execute([$documentId, $tagId]);
            }
        }
    }
}
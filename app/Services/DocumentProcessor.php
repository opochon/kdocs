<?php
namespace KDocs\Services;
use KDocs\Core\Database;
use KDocs\Models\Document;
use KDocs\Services\WebhookService;

class DocumentProcessor
{
    private OCRService $ocrService;
    private MetadataExtractor $metadataExtractor;
    private $db;
    
    public function __construct()
    {
        $this->ocrService = new OCRService();
        $this->metadataExtractor = new MetadataExtractor();
        $this->db = Database::getInstance();
    }
    
    public function processDocument(int $documentId): bool
    {
        $document = Document::findById($documentId);
        if (!$document || !file_exists($document['file_path'])) return false;
        
        try {
            $text = $this->ocrService->extractText($document['file_path']);
            $metadata = $this->metadataExtractor->extractMetadata($text ?? '', $document['filename']);
            $this->updateDocument($documentId, $text, $metadata);
            
            // Déclencher webhook document.processed
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
            $this->processDocument($doc['id']) ? $stats['processed']++ : $stats['errors']++;
        }
        return $stats;
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
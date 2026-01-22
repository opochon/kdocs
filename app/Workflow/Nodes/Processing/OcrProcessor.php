<?php
/**
 * K-Docs - OcrProcessor
 * Traite un document avec OCR (Tesseract)
 */

namespace KDocs\Workflow\Nodes\Processing;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;
use KDocs\Services\OCRService;
use KDocs\Core\Database;

class OcrProcessor extends AbstractNodeExecutor
{
    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        if (!$context->documentId) {
            return ExecutionResult::failed('Aucun document associé');
        }
        
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
            $stmt->execute([$context->documentId]);
            $document = $stmt->fetch();
            
            if (!$document) {
                return ExecutionResult::failed('Document non trouvé');
            }
            
            $ocrService = new OCRService();
            $ocrText = $ocrService->extractText($document['file_path']);
            
            // Mettre à jour le document avec le texte OCR
            $updateStmt = $db->prepare("UPDATE documents SET ocr_text = ? WHERE id = ?");
            $updateStmt->execute([$ocrText, $context->documentId]);
            
            // Ajouter au contexte
            $context->set('ocr_text', $ocrText);
            
            return ExecutionResult::success([
                'ocr_text' => $ocrText,
                'length' => strlen($ocrText),
            ]);
        } catch (\Exception $e) {
            return ExecutionResult::failed('Erreur OCR: ' . $e->getMessage());
        }
    }
    
    public function getConfigSchema(): array
    {
        return [
            'engine' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Moteur OCR (tesseract)',
                'default' => 'tesseract',
            ],
            'languages' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Langues pour OCR',
                'default' => ['fra', 'eng'],
            ],
            'dpi' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Résolution DPI',
                'default' => 300,
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace KDocs\Services\Ingest;

use KDocs\Services\Classification\IngestClassificationService;
use KDocs\Services\OCRService;

/**
 * Moteur ingest natif GED — OCR + queue UnifiedClassifier.
 */
class GedNativeIngestEngine
{
    private OCRService $ocrService;
    private IngestClassificationService $classification;

    public function __construct(
        ?OCRService $ocrService = null,
        ?IngestClassificationService $classification = null
    ) {
        $this->ocrService = $ocrService ?? new OCRService();
        $this->classification = $classification ?? new IngestClassificationService();
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    public function process(int $documentId, string $filePath, array $document): array
    {
        $result = [
            'engine' => 'native',
            'extract_done' => false,
            'classification_skipped' => false,
            'classification_queued' => false,
            'ocr' => false,
        ];

        $hasOcrError = !empty($document['ocr_text'])
            && (str_contains((string) $document['ocr_text'], 'OCR échoué')
                || str_contains((string) $document['ocr_text'], 'Erreur OCR'));

        $needsOcr = (empty($document['content']) && empty($document['ocr_text'])) || $hasOcrError;

        if ($needsOcr) {
            $result['ocr'] = $this->runOcr($documentId, $filePath);
            $result['extract_done'] = $result['ocr'];
        } else {
            $result['extract_done'] = !empty($document['content']) || !empty($document['ocr_text']);
        }

        if (filter_var(env('IA_UNIFIED_CLASSIFY_ENABLED', true), FILTER_VALIDATE_BOOLEAN)) {
            $result['classification_queued'] = $this->classification->queue($documentId);
        }

        return $result;
    }

    private function runOcr(int $documentId, string $filePath): bool
    {
        try {
            $content = $this->ocrService->extractText($filePath);
            if ($content === null || trim($content) === '') {
                return false;
            }

            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
            $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content) ?? $content;
            // Troncature en OCTETS : la colonne TEXT vaut 65 535 octets, et 65 000
            // caracteres accentues en font davantage (SQLSTATE[22001] sur un gros scan).
            $content = OCRService::truncateForTextColumn($content);

            $db = \KDocs\Core\Database::getInstance();
            $db->prepare('UPDATE documents SET content = ?, ocr_text = ? WHERE id = ?')
                ->execute([$content, $content, $documentId]);

            return true;
        } catch (\Throwable $e) {
            error_log("GedNativeIngestEngine OCR document {$documentId}: " . $e->getMessage());

            return false;
        }
    }
}

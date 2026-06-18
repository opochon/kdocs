<?php

declare(strict_types=1);

namespace KDocs\Services\PdfSplit;

use KDocs\Contracts\PdfSplitInterface;
use KDocs\Services\PDFSplitterService;

/**
 * Façade documentée pour le split PDF multi-contenu.
 * Délègue à {@see PDFSplitterService} ; sidecar ClearMyDocs prévu (segmenter.py).
 */
class PdfSplitService implements PdfSplitInterface
{
    private PDFSplitterService $legacy;

    public function __construct(?PDFSplitterService $legacy = null)
    {
        $this->legacy = $legacy ?? new PDFSplitterService();
    }

    public function detectPageGroups(int $documentId): array
    {
        if (!filter_var(env('IA_PDF_SPLIT_ENABLED', true), FILTER_VALIDATE_BOOLEAN)) {
            return [
                'should_split' => false,
                'page_groups' => [],
                'source' => 'disabled',
                'audit' => [],
            ];
        }

        // Lot 1 : détection implicite via analyzeAndSplit (legacy)
        return [
            'should_split' => true,
            'page_groups' => [],
            'source' => 'ged-legacy-delegation',
            'audit' => ['document_id' => $documentId, 'note' => 'detectPageGroups à enrichir (patterns ClearMyDocs)'],
        ];
    }

    public function split(int $documentId): ?array
    {
        $result = $this->legacy->analyzeAndSplit($documentId);
        if ($result === null || empty($result['created_documents'])) {
            return null;
        }

        return array_map('intval', (array) $result['created_documents']);
    }
}

<?php

declare(strict_types=1);

namespace KDocs\Services\PdfSplit;

use KDocs\Contracts\PdfSplitInterface;
use KDocs\Services\PDFSplitterService;

/**
 * Façade documentée pour le split PDF multi-contenu.
 * Délègue à {@see PDFSplitterService} (legacy). Le sidecar ClearMyDocs v3 a été
 * retiré (ancienne version) — la détection automatique de groupes de pages est
 * donc désactivée ; le split explicite reste disponible via {@see self::split()}.
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

        return [
            'should_split' => false,
            'page_groups' => [],
            'source' => 'ged-legacy',
            'audit' => [
                'document_id' => $documentId,
                'note' => 'Détection auto indisponible (sidecar ClearMyDocs v3 retiré) — utiliser split() legacy',
            ],
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

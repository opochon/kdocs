<?php

declare(strict_types=1);

namespace KDocs\Services\PdfSplit;

use KDocs\Contracts\PdfSplitInterface;
use KDocs\Services\PDFSplitterService;

/**
 * Façade documentée pour le split PDF multi-contenu.
 * Délègue à {@see PDFSplitterService} : détection candidate légère (page count, PDF,
 * config) via {@see PDFSplitterService::detectCandidate()}, puis analyse réelle
 * (fournisseur IA actif, repli sur règles en dur si indisponible) et séparation via
 * {@see PDFSplitterService::analyzeAndSplit()} dans {@see self::split()}.
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

        return $this->legacy->detectCandidate($documentId);
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

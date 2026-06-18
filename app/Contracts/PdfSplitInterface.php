<?php

declare(strict_types=1);

namespace KDocs\Contracts;

/**
 * Détection et séparation de PDF multi-documents (scan N pages → N pièces).
 *
 * Implémentation de référence : {@see \KDocs\Services\PDFSplitterService}
 * Patterns ClearMyDocs : segmenter heuristique + profils (`segment_patterns`).
 *
 * @see docs/IA-ROADMAP.md
 */
interface PdfSplitInterface
{
    /**
     * Analyse un PDF et retourne les groupes de pages détectés.
     *
     * @return array{
     *   should_split: bool,
     *   page_groups: list<array{start: int, end: int, label?: string, confidence?: float}>,
     *   source: string,
     *   audit: array<string, mixed>
     * }
     */
    public function detectPageGroups(int $documentId): array;

    /**
     * Exécute la séparation si {@see detectPageGroups()} recommande un split.
     *
     * @return list<int>|null IDs des documents créés, null si aucun split
     */
    public function split(int $documentId): ?array;
}

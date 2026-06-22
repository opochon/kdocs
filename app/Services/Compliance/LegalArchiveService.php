<?php
/**
 * Archivage légal WORM (phase P2 — scaffold).
 */

namespace KDocs\Services\Compliance;

class LegalArchiveService
{
    /**
     * @return array{archived: bool, blocked: string}
     */
    public function archiveDocument(int $documentId): array
    {
        return [
            'archived' => false,
            'blocked' => 'P2 not implemented — WORM/TSA/GeBüV pending',
        ];
    }
}

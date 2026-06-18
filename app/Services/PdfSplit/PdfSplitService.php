<?php

declare(strict_types=1);

namespace KDocs\Services\PdfSplit;

use KDocs\Contracts\PdfSplitInterface;
use KDocs\Core\Config;
use KDocs\Core\Database;
use KDocs\Services\ClearMyDocsSidecarClient;
use KDocs\Services\PDFSplitterService;

/**
 * Façade documentée pour le split PDF multi-contenu.
 * Délègue à {@see PDFSplitterService} ; sidecar ClearMyDocs via {@see ClearMyDocsSidecarClient}.
 */
class PdfSplitService implements PdfSplitInterface
{
    private PDFSplitterService $legacy;
    private ClearMyDocsSidecarClient $sidecar;

    public function __construct(?PDFSplitterService $legacy = null, ?ClearMyDocsSidecarClient $sidecar = null)
    {
        $this->legacy = $legacy ?? new PDFSplitterService();
        $this->sidecar = $sidecar ?? new ClearMyDocsSidecarClient();
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

        if ($this->sidecar->isEnabled()) {
            $sidecarResult = $this->detectViaSidecar($documentId);
            if ($sidecarResult !== null) {
                return $sidecarResult;
            }
        }

        return [
            'should_split' => false,
            'page_groups' => [],
            'source' => 'ged-legacy-fallback',
            'audit' => [
                'document_id' => $documentId,
                'note' => 'Sidecar ClearMyDocs indisponible ou désactivé — utiliser split() legacy',
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

    /** @return array<string, mixed>|null */
    private function detectViaSidecar(int $documentId): ?array
    {
        $pdfPath = $this->resolvePdfPath($documentId);
        if ($pdfPath === null) {
            return null;
        }

        $segment = $this->sidecar->segmentPdf($pdfPath);
        if ($segment === null) {
            return null;
        }

        return [
            'should_split' => $segment['should_split'],
            'page_groups' => $segment['page_groups'],
            'source' => $segment['source'],
            'audit' => [
                'document_id' => $documentId,
                'pdf_path' => $pdfPath,
                'segment_count' => $segment['segment_count'],
                'sidecar_url' => $this->sidecar->baseUrl(),
            ],
        ];
    }

    private function resolvePdfPath(int $documentId): ?string
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare('SELECT mime_type, file_path, storage_path, filename FROM documents WHERE id = ?');
            $stmt->execute([$documentId]);
            $document = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return null;
        }

        if (!$document || ($document['mime_type'] ?? '') !== 'application/pdf') {
            return null;
        }

        $config = Config::load();
        $documentsPath = $config['storage']['documents'] ?? dirname(__DIR__, 3) . '/storage/documents';

        $candidates = [];
        if (!empty($document['file_path'])) {
            $candidates[] = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $document['file_path']);
        }
        if (!empty($document['storage_path'])) {
            $candidates[] = $documentsPath . DIRECTORY_SEPARATOR . $document['storage_path'];
        }
        if (!empty($document['filename'])) {
            $candidates[] = $documentsPath . DIRECTORY_SEPARATOR . $document['filename'];
        }

        foreach ($candidates as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }

        return null;
    }
}

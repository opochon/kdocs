<?php

declare(strict_types=1);

namespace KDocs\Services\Ingest;

use KDocs\Models\Document;

/**
 * Route l'ingest documentaire vers CMD v4 (factures) ou le pipeline natif GED.
 *
 * Le connecteur ClearMyDocs v3 (sidecar couplé) a été retiré — ancienne version,
 * remplacée par CMD v4 (`CmdV4IngestEngine`). L'ingest natif GED reste toujours
 * disponible (`GedNativeIngestEngine`).
 */
class IngestEngineRouter
{
    private CmdV4CapabilityProbe $v4Probe;
    private CmdV4IngestEngine $v4Engine;
    private GedNativeIngestEngine $nativeEngine;

    public function __construct(
        ?CmdV4CapabilityProbe $v4Probe = null,
        ?CmdV4IngestEngine $v4Engine = null,
        ?GedNativeIngestEngine $nativeEngine = null
    ) {
        $this->v4Probe = $v4Probe ?? new CmdV4CapabilityProbe();
        $this->v4Engine = $v4Engine ?? new CmdV4IngestEngine();
        $this->nativeEngine = $nativeEngine ?? new GedNativeIngestEngine();
    }

    /** @return array<string, mixed> */
    public function getStatus(): array
    {
        return [
            'active_engine' => 'native',
            'cmd_v4' => $this->v4Probe->probe(),
        ];
    }

    /**
     * @param array<string, mixed>|null $document
     *
     * @return array<string, mixed>
     */
    public function process(int $documentId, string $filePath, ?array $document = null): array
    {
        if ($document === null) {
            $document = Document::findById($documentId);
        }

        if (!$document) {
            throw new \RuntimeException("Document introuvable: {$documentId}");
        }

        $v4Status = $this->v4Probe->probe();
        $probePayload = ['cmd_v4' => $v4Status];

        if ($this->shouldTryCmdV4($filePath, $document, $v4Status)) {
            $v4Result = $this->v4Engine->process($documentId, $filePath, $document);
            if (($v4Result['sidecar_error'] ?? null) === null && ($v4Result['invoice_enriched'] ?? false)) {
                return array_merge($v4Result, ['probe' => $probePayload]);
            }
        }

        $nativeResult = $this->nativeEngine->process($documentId, $filePath, $document);

        return array_merge($nativeResult, [
            'fallback_from_v4' => ($v4Status['invoice_routing_available'] ?? false)
                && $this->v4Probe->isInvoiceCandidate($filePath, $document),
            'probe' => $probePayload,
        ]);
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $v4Status
     */
    private function shouldTryCmdV4(string $filePath, array $document, array $v4Status): bool
    {
        if (!($v4Status['invoice_routing_available'] ?? false)) {
            return false;
        }

        return $this->v4Probe->isInvoiceCandidate($filePath, $document);
    }
}

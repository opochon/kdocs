<?php

declare(strict_types=1);

namespace KDocs\Services\Ingest;

use KDocs\Models\Document;

/**
 * Route l'ingest documentaire vers ClearMyDocs v3 ou le pipeline natif GED.
 */
class IngestEngineRouter
{
    private ClearMyDocsCapabilityProbe $probe;
    private ClearMyDocsIngestEngine $coupledEngine;
    private GedNativeIngestEngine $nativeEngine;

    public function __construct(
        ?ClearMyDocsCapabilityProbe $probe = null,
        ?ClearMyDocsIngestEngine $coupledEngine = null,
        ?GedNativeIngestEngine $nativeEngine = null
    ) {
        $this->probe = $probe ?? new ClearMyDocsCapabilityProbe();
        $this->coupledEngine = $coupledEngine ?? new ClearMyDocsIngestEngine();
        $this->nativeEngine = $nativeEngine ?? new GedNativeIngestEngine();
    }

    /** @return array<string, mixed> */
    public function getStatus(): array
    {
        return $this->probe->probe();
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

        $status = $this->probe->probe();
        $useCoupled = (bool) ($status['coupled_available'] ?? false);

        if ($this->probe->requiresCoupledEngine() && !$useCoupled) {
            return array_merge(
                $this->nativeEngine->process($documentId, $filePath, $document),
                [
                    'engine' => 'coupled_unavailable',
                    'coupled_required' => true,
                    'probe' => $status,
                ]
            );
        }

        if ($useCoupled) {
            $coupledResult = $this->coupledEngine->process($documentId, $filePath, $document);
            if (($coupledResult['sidecar_error'] ?? null) === null && ($coupledResult['extract_done'] ?? false)) {
                return array_merge($coupledResult, ['probe' => $status]);
            }

            if ($this->probe->configuredEngineMode() === 'coupled') {
                return array_merge($coupledResult, [
                    'engine' => 'coupled_failed',
                    'probe' => $status,
                ]);
            }
        }

        $nativeResult = $this->nativeEngine->process($documentId, $filePath, $document);

        return array_merge($nativeResult, [
            'fallback_from_coupled' => $useCoupled,
            'probe' => $status,
        ]);
    }
}

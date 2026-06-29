<?php

declare(strict_types=1);

namespace KDocs\Services\Ingest;

use KDocs\Models\Document;

/**

 * Route l'ingest documentaire vers CMD v4 (factures), ClearMyDocs v3 ou le pipeline natif GED.

 */

class IngestEngineRouter

{

    private ClearMyDocsCapabilityProbe $probe;

    private CmdV4CapabilityProbe $v4Probe;

    private CmdV4IngestEngine $v4Engine;

    private ClearMyDocsIngestEngine $coupledEngine;

    private GedNativeIngestEngine $nativeEngine;

    public function __construct(

        ?ClearMyDocsCapabilityProbe $probe = null,

        ?CmdV4CapabilityProbe $v4Probe = null,

        ?CmdV4IngestEngine $v4Engine = null,

        ?ClearMyDocsIngestEngine $coupledEngine = null,

        ?GedNativeIngestEngine $nativeEngine = null

    ) {

        $this->probe = $probe ?? new ClearMyDocsCapabilityProbe();

        $this->v4Probe = $v4Probe ?? new CmdV4CapabilityProbe();

        $this->v4Engine = $v4Engine ?? new CmdV4IngestEngine();

        $this->coupledEngine = $coupledEngine ?? new ClearMyDocsIngestEngine();

        $this->nativeEngine = $nativeEngine ?? new GedNativeIngestEngine();

    }

    /** @return array<string, mixed> */

    public function getStatus(): array

    {

        return array_merge(

            $this->probe->probe(),

            ['cmd_v4' => $this->v4Probe->probe()]

        );

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

        $v4Status = $this->v4Probe->probe();

        $probePayload = array_merge($status, ['cmd_v4' => $v4Status]);

        if ($this->shouldTryCmdV4($filePath, $document, $v4Status)) {

            $v4Result = $this->v4Engine->process($documentId, $filePath, $document);

            if (($v4Result['sidecar_error'] ?? null) === null && ($v4Result['invoice_enriched'] ?? false)) {

                return array_merge($v4Result, ['probe' => $probePayload]);

            }

        }

        $useCoupled = (bool) ($status['coupled_available'] ?? false);

        if ($this->probe->requiresCoupledEngine() && !$useCoupled) {

            return array_merge(

                $this->nativeEngine->process($documentId, $filePath, $document),

                [

                    'engine' => 'coupled_unavailable',

                    'coupled_required' => true,

                    'probe' => $probePayload,

                ]

            );

        }

        if ($useCoupled) {

            $coupledResult = $this->coupledEngine->process($documentId, $filePath, $document);

            if (($coupledResult['sidecar_error'] ?? null) === null && ($coupledResult['extract_done'] ?? false)) {

                return array_merge($coupledResult, ['probe' => $probePayload]);

            }

            if ($this->probe->configuredEngineMode() === 'coupled') {

                return array_merge($coupledResult, [

                    'engine' => 'coupled_failed',

                    'probe' => $probePayload,

                ]);

            }

        }

        $nativeResult = $this->nativeEngine->process($documentId, $filePath, $document);

        return array_merge($nativeResult, [

            'fallback_from_coupled' => $useCoupled,

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

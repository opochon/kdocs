<?php

declare(strict_types=1);

namespace KDocs\Services\Ingest;

use KDocs\Services\Classification\IngestClassificationService;
use KDocs\Services\Classification\TaxonomySyncService;
use KDocs\Services\ClearMyDocsSidecarClient;
use KDocs\Services\PdfSplit\PdfSplitService;

/**
 * Moteur ingest couplé — délègue extract/segment/analyze au sidecar CMD v3.
 */
class ClearMyDocsIngestEngine
{
    private ClearMyDocsSidecarClient $client;
    private CmdResultMapper $mapper;
    private PdfSplitService $pdfSplit;
    private IngestClassificationService $classification;
    private TaxonomySyncService $taxonomySync;

    public function __construct(
        ?ClearMyDocsSidecarClient $client = null,
        ?CmdResultMapper $mapper = null,
        ?PdfSplitService $pdfSplit = null,
        ?IngestClassificationService $classification = null,
        ?TaxonomySyncService $taxonomySync = null
    ) {
        $this->client = $client ?? new ClearMyDocsSidecarClient();
        $this->mapper = $mapper ?? new CmdResultMapper();
        $this->pdfSplit = $pdfSplit ?? new PdfSplitService();
        $this->classification = $classification ?? new IngestClassificationService();
        $this->taxonomySync = $taxonomySync ?? new TaxonomySyncService();
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    public function process(int $documentId, string $filePath, array $document): array
    {
        $result = [
            'engine' => 'coupled',
            'extract_done' => false,
            'classification_skipped' => false,
            'classification_queued' => false,
            'split' => false,
            'sidecar_error' => null,
        ];

        $ingest = $this->client->ingestFile($filePath, $this->buildIngestOptions());
        if ($ingest === null) {
            $result['sidecar_error'] = 'ingest_failed';

            return $result;
        }

        if (!empty($ingest['extract']) && is_array($ingest['extract'])) {
            $this->mapper->applyExtract($documentId, $ingest['extract']);
            $result['extract_done'] = trim((string) ($ingest['extract']['text'] ?? '')) !== '';
        }

        if (!empty($ingest['segment']['should_split']) && is_array($ingest['segment'])) {
            $childIds = $this->pdfSplit->split($documentId);
            if ($childIds !== null && $childIds !== []) {
                $this->mapper->applySplitParent($documentId, $ingest['segment'], $childIds);
                foreach ($childIds as $childId) {
                    $this->classification->queue((int) $childId);
                }
                $result['split'] = true;
                $result['child_documents'] = $childIds;
                $result['classification_skipped'] = true;

                return $result;
            }
        }

        if (!empty($ingest['analyze']) && is_array($ingest['analyze'])) {
            $confident = $this->mapper->applyAnalysis($documentId, $ingest['analyze']);
            $result['classification_skipped'] = $confident;
            if (!$confident && filter_var(env('IA_UNIFIED_CLASSIFY_ENABLED', true), FILTER_VALIDATE_BOOLEAN)) {
                $result['classification_queued'] = $this->classification->queue($documentId);
            }
        } elseif (filter_var(env('IA_UNIFIED_CLASSIFY_ENABLED', true), FILTER_VALIDATE_BOOLEAN)) {
            $result['classification_queued'] = $this->classification->queue($documentId);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function buildIngestOptions(): array
    {
        $options = [
            'profile' => 'legal_ch',
            'min_pages_per_segment' => 1,
            'use_llm_confirm' => false,
            'run_segment' => filter_var(env('IA_PDF_SPLIT_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'run_analyze' => true,
        ];

        $stored = $this->taxonomySync->getStored();
        $taxonomy = is_array($stored['taxonomy'] ?? null) ? $stored['taxonomy'] : null;
        if ($taxonomy === null) {
            try {
                $taxonomy = $this->taxonomySync->loadFromSource();
            } catch (\Throwable) {
                $taxonomy = null;
            }
        }

        if (is_array($taxonomy)) {
            $options['taxonomy_categories'] = $this->collectTaxonomyLabels($taxonomy, 'sections');
            $options['taxonomy_tags'] = $this->collectTaxonomyLabels($taxonomy, 'tags');
        }

        return $options;
    }

    /** @param array<string, mixed> $taxonomy */
    private function collectTaxonomyLabels(array $taxonomy, string $key): array
    {
        $items = $taxonomy[$key] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $labels = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $labels[] = $item;
                continue;
            }
            if (is_array($item)) {
                $label = $item['label'] ?? $item['name'] ?? $item['id'] ?? null;
                if ($label !== null && $label !== '') {
                    $labels[] = (string) $label;
                }
            }
        }

        return array_values(array_unique($labels));
    }
}

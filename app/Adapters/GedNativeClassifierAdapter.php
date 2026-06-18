<?php

declare(strict_types=1);

namespace KDocs\Adapters;

use KDocs\Contracts\ClassifierInterface;
use KDocs\Services\AIClassifierService;

/**
 * Adapter cascade GED native (Claude → Ollama → règles via AIClassifierService).
 */
class GedNativeClassifierAdapter implements ClassifierInterface
{
    private AIClassifierService $native;

    public function __construct(?AIClassifierService $native = null)
    {
        $this->native = $native ?? new AIClassifierService();
    }

    public function getName(): string
    {
        return 'ged-native';
    }

    public function isAvailable(): bool
    {
        return $this->native->isAvailable();
    }

    public function classify(array $context): array
    {
        $documentId = (int) ($context['document_id'] ?? 0);
        if ($documentId <= 0) {
            return $this->emptyResult('missing_document_id');
        }

        $aiResult = $this->native->classify($documentId);
        if ($aiResult === null) {
            return $this->emptyResult('native_unavailable');
        }

        $confidence = (float) ($aiResult['confidence'] ?? 0.7);

        return [
            'category' => $aiResult['document_type'] ?? $aiResult['matched']['document_type_name'] ?? null,
            'tags' => $aiResult['tags'] ?? [],
            'externalIds' => [],
            'suggestions' => [
                'document_type' => $aiResult['document_type'] ?? null,
                'correspondent' => $aiResult['correspondent'] ?? null,
                'tag_names' => $aiResult['tags'] ?? [],
                'summary' => $aiResult['summary'] ?? null,
                'matched' => $aiResult['matched'] ?? [],
            ],
            'confidence' => $confidence,
            'source' => $this->getName(),
            'raw' => $aiResult,
            'audit' => ['adapter' => 'AIClassifierService', 'document_id' => $documentId],
        ];
    }

    public function syncTaxonomy(): array
    {
        return [
            'source' => $this->getName(),
            'note' => 'Taxonomie GED native (document_types, tags, correspondents) — lue à la volée',
        ];
    }

    /** @return array<string, mixed> */
    private function emptyResult(string $reason): array
    {
        return [
            'category' => null,
            'tags' => [],
            'externalIds' => [],
            'suggestions' => [],
            'confidence' => 0.0,
            'source' => $this->getName(),
            'raw' => ['reason' => $reason],
            'audit' => ['adapter' => 'AIClassifierService', 'reason' => $reason],
        ];
    }
}

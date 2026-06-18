<?php

declare(strict_types=1);

namespace KDocs\DTO;

/**
 * Résultat normalisé de classification documentaire (ingest UnifiedClassifier).
 */
final class ClassificationResult
{
    /** @param list<string> $tags */
    /** @param array<string, string> $externalIds */
    public function __construct(
        public readonly ?string $category,
        public readonly array $tags,
        public readonly float $confidence,
        public readonly array $externalIds,
        public readonly string $source,
        public readonly array $raw,
        public readonly array $suggestions = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $suggestions = is_array($payload['suggestions'] ?? null) ? $payload['suggestions'] : [];

        return new self(
            category: isset($payload['category']) ? (string) $payload['category'] : null,
            tags: array_values(array_map('strval', $payload['tags'] ?? $suggestions['tag_names'] ?? [])),
            confidence: (float) ($payload['confidence'] ?? 0.0),
            externalIds: is_array($payload['externalIds'] ?? null)
                ? $payload['externalIds']
                : (is_array($suggestions['externalIds'] ?? null) ? $suggestions['externalIds'] : []),
            source: (string) ($payload['source'] ?? 'unknown'),
            raw: is_array($payload['raw'] ?? null) ? $payload['raw'] : $payload,
            suggestions: $suggestions,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'tags' => $this->tags,
            'confidence' => $this->confidence,
            'externalIds' => $this->externalIds,
            'source' => $this->source,
            'raw' => $this->raw,
            'suggestions' => $this->suggestions,
        ];
    }

    /** @return array<string, mixed> Format persisté dans classification_suggestions */
    public function toPersistencePayload(): array
    {
        return [
            'method_used' => 'unified_' . $this->source,
            'unified' => $this->toArray(),
            'final' => [
                'document_type_name' => $this->category,
                'tag_names' => $this->tags,
                'confidence' => $this->confidence,
                'external_ids' => $this->externalIds,
            ],
            'confidence' => $this->confidence,
            'should_review' => $this->confidence < (float) env('IA_UNIFIED_MIN_CONFIDENCE', 0.75),
            'classified_at' => date('c'),
        ];
    }
}

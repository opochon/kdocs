<?php

declare(strict_types=1);

namespace KDocs\Services\Classifiers;

use KDocs\Contracts\ClassifierInterface;
use KDocs\Services\AIClassifierService;

/**
 * Façade plugin — agrège les adapters (GED native, HTMLEDITOR, ClearMyDocs sidecar).
 * Lot 1 : délègue à la cascade GED existante.
 */
class UnifiedClassifier implements ClassifierInterface
{
    private AIClassifierService $native;

    /** @var list<ClassifierInterface> */
    private array $adapters = [];

    public function __construct(?AIClassifierService $native = null)
    {
        $this->native = $native ?? new AIClassifierService();
    }

    public function registerAdapter(ClassifierInterface $adapter): void
    {
        $this->adapters[] = $adapter;
    }

    public function getName(): string
    {
        return 'unified';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function classify(array $context): array
    {
        foreach ($this->adapters as $adapter) {
            if (!$adapter->isAvailable()) {
                continue;
            }
            $result = $adapter->classify($context);
            if (($result['confidence'] ?? 0) >= (float) env('IA_UNIFIED_MIN_CONFIDENCE', 0.75)) {
                $result['source'] = $adapter->getName();
                return $result;
            }
        }

        $documentId = (int) ($context['document_id'] ?? 0);
        $suggestions = $documentId > 0
            ? ($this->native->classify($documentId) ?? [])
            : [];

        return [
            'suggestions' => $suggestions,
            'confidence' => (float) ($suggestions['confidence'] ?? 0.5),
            'source' => 'ged-native',
            'audit' => ['adapter' => 'AIClassifierService', 'document_id' => $documentId],
        ];
    }

    public function syncTaxonomy(): array
    {
        $snapshots = [];
        foreach ($this->adapters as $adapter) {
            if ($adapter->isAvailable()) {
                $snapshots[$adapter->getName()] = $adapter->syncTaxonomy();
            }
        }

        return [
            'adapters' => $snapshots,
            'synced_at' => date('c'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace KDocs\Adapters;

use KDocs\Contracts\ClassifierInterface;

/**
 * Stub provider Infomaniak AI — classification cloud non branchée.
 *
 * Infomaniak : kDrive (stockage WebDAV) déjà intégré ; API IA documentée uniquement
 * via ClearMyDocs (`providers_infomaniak.py`). Activer quand spec API stable.
 *
 * @see docs/IA-ROADMAP.md § Infomaniak
 * @see htmleditor/Release/ARCHITECTURE-INFOMANIAK.md (hébergement, pas API IA)
 */
class InfomaniakClassifierAdapter implements ClassifierInterface
{
    public function getName(): string
    {
        return 'infomaniak-ai';
    }

    public function isAvailable(): bool
    {
        return filter_var(env('INFOMANIAK_AI_ENABLED', false), FILTER_VALIDATE_BOOLEAN)
            && env('INFOMANIAK_AI_API_KEY') !== null
            && env('INFOMANIAK_AI_API_KEY') !== '';
    }

    public function classify(array $context): array
    {
        return [
            'category' => null,
            'tags' => [],
            'externalIds' => [],
            'suggestions' => [],
            'confidence' => 0.0,
            'source' => $this->getName(),
            'raw' => ['status' => 'stub', 'todo' => 'Brancher API Infomaniak ou sidecar ClearMyDocs'],
            'audit' => ['adapter' => 'InfomaniakClassifierAdapter', 'document_id' => $context['document_id'] ?? null],
        ];
    }

    public function syncTaxonomy(): array
    {
        return [
            'source' => $this->getName(),
            'status' => 'stub',
            'todo' => 'Sync taxonomie via kDrive export ou API Flowy quand disponible',
        ];
    }
}

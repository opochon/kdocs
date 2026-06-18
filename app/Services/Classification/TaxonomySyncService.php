<?php

declare(strict_types=1);

namespace KDocs\Services\Classification;

use KDocs\Adapters\HtmleditorTaxonomyAdapter;
use KDocs\Models\Setting;

/**
 * Persiste la taxonomie HTMLEDITOR en base (table settings).
 */
class TaxonomySyncService
{
    public const SETTING_KEY = 'classification.taxonomy.htmleditor';

    private HtmleditorTaxonomyAdapter $adapter;

    public function __construct(?HtmleditorTaxonomyAdapter $adapter = null)
    {
        $this->adapter = $adapter ?? new HtmleditorTaxonomyAdapter();
    }

    /** @return array<string, mixed> */
    public function loadFromSource(?string $pathOverride = null): array
    {
        return $this->adapter->loadExport($pathOverride);
    }

    /** @return array<string, mixed> */
    public function sync(?string $pathOverride = null, ?int $userId = null): array
    {
        $taxonomy = $this->loadFromSource($pathOverride);
        $payload = [
            'taxonomy' => $taxonomy,
            'synced_at' => date('c'),
            'source_path' => $taxonomy['source_path'] ?? null,
            'counts' => [
                'variables' => count($taxonomy['variables'] ?? []),
                'sets' => count($taxonomy['sets'] ?? []),
                'sections' => count($taxonomy['sections'] ?? []),
                'tags' => count($taxonomy['tags'] ?? []),
                'external_ids' => count($taxonomy['externalIds'] ?? []),
            ],
        ];

        Setting::set(self::SETTING_KEY, $payload, 'json', $userId);

        return $payload;
    }

    /** @return array<string, mixed>|null */
    public function getStored(): ?array
    {
        $stored = Setting::get(self::SETTING_KEY);
        return is_array($stored) ? $stored : null;
    }

    /** @return array<string, mixed> */
    public function getTaxonomyForDebug(bool $preferStored = true): array
    {
        if ($preferStored) {
            $stored = $this->getStored();
            if ($stored !== null) {
                return [
                    'source' => 'database',
                    'stored' => $stored,
                    'live_available' => $this->adapter->isAvailable(),
                ];
            }
        }

        $live = $this->loadFromSource();

        return [
            'source' => 'live-file',
            'taxonomy' => $live,
            'stored' => null,
        ];
    }
}

<?php

declare(strict_types=1);

namespace KDocs\Adapters;

use KDocs\Contracts\ClassifierInterface;

/**
 * Lit la taxonomie HTMLEDITOR depuis un export `_variables.json` (+ sections/tags futurs).
 *
 * Chemin configuré via HTMLEDITOR_TAXONOMY_PATH (.env).
 */
class HtmleditorTaxonomyAdapter implements ClassifierInterface
{
    private ?array $cached = null;
    private ?string $cachedPath = null;

    public function getName(): string
    {
        return 'htmleditor-taxonomy';
    }

    public function isAvailable(): bool
    {
        $path = $this->taxonomyPath();
        return $path !== null && is_readable($path);
    }

    public function classify(array $context): array
    {
        $taxonomy = $this->loadTaxonomy();

        return [
            'suggestions' => [
                'variables' => $taxonomy['variables'] ?? [],
                'sets' => $taxonomy['sets'] ?? [],
                'sections' => $taxonomy['sections'] ?? [],
                'tags' => $taxonomy['tags'] ?? [],
                'externalIds' => $taxonomy['externalIds'] ?? [],
                'project_key' => $context['project_key'] ?? null,
            ],
            'confidence' => $this->isAvailable() ? 0.6 : 0.0,
            'source' => $this->getName(),
            'audit' => ['path' => $this->taxonomyPath()],
        ];
    }

    public function syncTaxonomy(): array
    {
        return $this->loadExport();
    }

    /** @return array<string, mixed> */
    public function loadExport(?string $pathOverride = null): array
    {
        $this->cached = null;
        $this->cachedPath = null;

        return $this->loadTaxonomy($pathOverride);
    }

    private function taxonomyPath(?string $override = null): ?string
    {
        $path = $override ?? env('HTMLEDITOR_TAXONOMY_PATH');
        if ($path === null || $path === '') {
            return null;
        }

        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $path);
    }

    /** @return array<string, mixed> */
    private function loadTaxonomy(?string $pathOverride = null): array
    {
        $path = $this->taxonomyPath($pathOverride);
        if ($path !== null && $this->cached !== null && $this->cachedPath === $path) {
            return $this->cached;
        }

        if ($path === null || !is_readable($path)) {
            return $this->cached = [
                'variables' => [],
                'sets' => [],
                'sections' => [],
                'tags' => [],
                'externalIds' => [],
                'available' => false,
                'source_path' => $path,
            ];
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            return $this->cached = [
                'variables' => [],
                'sets' => [],
                'sections' => [],
                'tags' => [],
                'externalIds' => [],
                'available' => false,
                'source_path' => $path,
            ];
        }

        $this->cachedPath = $path;

        return $this->cached = $this->normalizeExport($raw, $path);
    }

    /** @return array<string, mixed> */
    private function normalizeExport(array $raw, string $path): array
    {
        $variables = $raw['variables'] ?? [];
        if (!is_array($variables) && !isset($raw['sets'])) {
            $variables = $raw;
        }

        $externalIds = $raw['externalIds'] ?? [];
        if (!is_array($externalIds)) {
            $externalIds = [];
        }

        if ($externalIds === [] && is_array($variables)) {
            foreach ($variables as $name => $def) {
                if (!is_array($def)) {
                    continue;
                }
                if (!empty($def['externalId'])) {
                    $externalIds[(string) $name] = (string) $def['externalId'];
                } elseif (!empty($def['external_id'])) {
                    $externalIds[(string) $name] = (string) $def['external_id'];
                }
            }
        }

        return [
            'version' => $raw['version'] ?? null,
            'variables' => is_array($variables) ? $variables : [],
            'sets' => is_array($raw['sets'] ?? null) ? $raw['sets'] : [],
            'sections' => is_array($raw['sections'] ?? null) ? $raw['sections'] : [],
            'tags' => is_array($raw['tags'] ?? null) ? $raw['tags'] : [],
            'externalIds' => $externalIds,
            'docOverlay' => is_array($raw['docOverlay'] ?? null) ? $raw['docOverlay'] : [],
            'updatedAt' => $raw['updatedAt'] ?? null,
            'available' => true,
            'source_file' => basename($path),
            'source_path' => $path,
        ];
    }
}

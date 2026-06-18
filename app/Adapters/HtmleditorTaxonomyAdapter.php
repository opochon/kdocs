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
                'project_key' => $context['project_key'] ?? null,
            ],
            'confidence' => $this->isAvailable() ? 0.6 : 0.0,
            'source' => $this->getName(),
            'audit' => ['path' => $this->taxonomyPath()],
        ];
    }

    public function syncTaxonomy(): array
    {
        return $this->loadTaxonomy();
    }

    private function taxonomyPath(): ?string
    {
        $path = env('HTMLEDITOR_TAXONOMY_PATH');
        if ($path === null || $path === '') {
            return null;
        }

        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $path);
    }

    /** @return array<string, mixed> */
    private function loadTaxonomy(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $path = $this->taxonomyPath();
        if ($path === null || !is_readable($path)) {
            return $this->cached = [
                'variables' => [],
                'sets' => [],
                'sections' => [],
                'available' => false,
            ];
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            return $this->cached = ['variables' => [], 'sets' => [], 'available' => false];
        }

        return $this->cached = [
            'variables' => $raw['variables'] ?? $raw,
            'sets' => $raw['sets'] ?? [],
            'sections' => $raw['sections'] ?? [],
            'available' => true,
            'source_file' => basename($path),
        ];
    }
}

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
        $taxonomy = $this->resolveTaxonomy($context);
        $text = mb_strtolower(trim((string) ($context['text'] ?? '')));

        $matchedTags = $this->matchTaxonomyTags($taxonomy['tags'] ?? [], $text);
        $category = $this->matchSectionCategory($taxonomy['sections'] ?? [], $text);
        $externalIds = $this->matchExternalIds($taxonomy, $text);

        $confidence = 0.0;
        if ($this->isAvailable() || !empty($taxonomy['available'])) {
            $confidence = 0.55;
            if ($matchedTags !== []) {
                $confidence += min(0.25, count($matchedTags) * 0.05);
            }
            if ($category !== null) {
                $confidence += 0.15;
            }
            $confidence = min(0.95, $confidence);
        }

        return [
            'category' => $category,
            'tags' => $matchedTags,
            'externalIds' => $externalIds,
            'suggestions' => [
                'variables' => $taxonomy['variables'] ?? [],
                'sets' => $taxonomy['sets'] ?? [],
                'sections' => $taxonomy['sections'] ?? [],
                'tags' => $taxonomy['tags'] ?? [],
                'externalIds' => $taxonomy['externalIds'] ?? [],
                'matched_tags' => $matchedTags,
                'project_key' => $context['project_key'] ?? null,
            ],
            'confidence' => $confidence,
            'source' => $this->getName(),
            'audit' => [
                'path' => $taxonomy['source_path'] ?? $this->taxonomyPath(),
                'taxonomy_source' => $context['taxonomy_source'] ?? 'file',
            ],
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

    /** @param array<string, mixed> $context */
    /** @return array<string, mixed> */
    private function resolveTaxonomy(array $context): array
    {
        if (!empty($context['taxonomy']) && is_array($context['taxonomy'])) {
            return $context['taxonomy'];
        }

        return $this->loadTaxonomy();
    }

    /** @param list<string|mixed> $tags */
    /** @return list<string> */
    private function matchTaxonomyTags(array $tags, string $text): array
    {
        if ($text === '') {
            return [];
        }

        $matched = [];
        foreach ($tags as $tag) {
            $label = is_array($tag) ? (string) ($tag['name'] ?? $tag['label'] ?? '') : (string) $tag;
            $label = trim($label);
            if ($label !== '' && mb_strpos($text, mb_strtolower($label)) !== false) {
                $matched[] = $label;
            }
        }

        return array_values(array_unique($matched));
    }

    /** @param list<array<string, mixed>|mixed> $sections */
    private function matchSectionCategory(array $sections, string $text): ?string
    {
        if ($text === '') {
            return null;
        }

        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $title = trim((string) ($section['title'] ?? $section['name'] ?? ''));
            if ($title !== '' && mb_strpos($text, mb_strtolower($title)) !== false) {
                return $title;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $taxonomy */
    /** @return array<string, string> */
    private function matchExternalIds(array $taxonomy, string $text): array
    {
        if ($text === '') {
            return [];
        }

        $matched = [];
        foreach ($taxonomy['externalIds'] ?? [] as $name => $externalId) {
            $needle = mb_strtolower((string) $name);
            if ($needle !== '' && mb_strpos($text, $needle) !== false) {
                $matched[(string) $name] = (string) $externalId;
            }
        }

        return $matched;
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

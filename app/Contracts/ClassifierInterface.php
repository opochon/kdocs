<?php

declare(strict_types=1);

namespace KDocs\Contracts;

/**
 * Contrat unifié pour les moteurs de classification documentaire GEDv1.
 *
 * Implémentations prévues : cascade GED native, taxonomie HTMLEDITOR, CMD v4 (factures).
 *
 * @see docs/IA-ROADMAP.md
 */
interface ClassifierInterface
{
    /**
     * @param array{
     *   document_id?: int,
     *   text?: string,
     *   mime_type?: string,
     *   file_path?: string|null,
     *   project_key?: string|null,
     *   external_ids?: array<string, string>
     * } $context
     *
     * @return array{
     *   suggestions: array<string, mixed>,
     *   confidence: float,
     *   source: string,
     *   audit: array<string, mixed>
     * }
     */
    public function classify(array $context): array;

    /** @return array<string, mixed> Snapshot taxonomie (types, tags, champs) */
    public function syncTaxonomy(): array;

    public function getName(): string;

    public function isAvailable(): bool;
}

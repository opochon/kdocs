<?php

declare(strict_types=1);

namespace KDocs\Services\Ingest;

/**

 * Détecte la disponibilité ClearMyDocs v4 pour l'enrichissement factures.

 */

class CmdV4CapabilityProbe

{

    private CmdV4Client $client;

    public function __construct(?CmdV4Client $client = null)

    {

        $this->client = $client ?? new CmdV4Client();

    }

    public function installPath(): ?string

    {

        $path = env('CMD_V4_PATH');

        if ($path === null || trim((string) $path) === '') {

            return null;

        }

        $resolved = realpath((string) $path);

        return ($resolved !== false && is_dir($resolved)) ? $resolved : null;

    }

    public function invoiceEnrichmentEnabled(): bool

    {

        if (!$this->client->isEnabled()) {

            return false;

        }

        return filter_var(env('CMD_V4_INVOICE_ENABLED', true), FILTER_VALIDATE_BOOLEAN);

    }

    /** @return array<string, mixed> */

    public function probe(bool $forceHealthCheck = true): array

    {

        $enabled = $this->client->isEnabled();

        $path = $this->installPath();

        $url = $this->client->baseUrl();

        $invoiceEnabled = $this->invoiceEnrichmentEnabled();

        $health = null;

        $remoteOk = false;

        $version = null;

        if ($forceHealthCheck && $enabled) {

            $health = $this->client->health();

            $remoteOk = is_array($health) && $health['ok'] === true;

            $version = is_array($health) ? ($health['version'] ?? null) : null;

        }

        $pathOk = $path !== null;

        $v4Available = $enabled && $pathOk && $remoteOk;

        $invoiceRouting = $v4Available && $invoiceEnabled;

        return [

            'enabled' => $enabled,

            'invoice_enrichment_enabled' => $invoiceEnabled,

            'v4_available' => $v4Available,

            'invoice_routing_available' => $invoiceRouting,

            'install_path' => $path,

            'install_path_ok' => $pathOk,

            'api_url' => $url,

            'remote_ok' => $remoteOk,

            'version' => $version,

            'health' => $health,

            'profile' => $this->client->projectProfile(),

        ];

    }

    public function isAvailable(): bool

    {

        return (bool) ($this->probe()['v4_available'] ?? false);

    }

    public function shouldRouteInvoices(): bool

    {

        if (strtolower(trim((string) env('INGEST_ENGINE', 'auto'))) === 'native') {

            return false;

        }

        return (bool) ($this->probe()['invoice_routing_available'] ?? false);

    }

    /**

     * @param array<string, mixed> $document

     */

    public function isInvoiceCandidate(string $filePath, array $document = []): bool

    {

        $mime = strtolower((string) ($document['mime_type'] ?? ''));

        $filename = strtolower((string) ($document['original_filename'] ?? $document['filename'] ?? basename($filePath)));

        $isPdf = str_ends_with(strtolower($filePath), '.pdf')

            || $mime === 'application/pdf';

        if (!$isPdf) {

            return false;

        }

        if (!filter_var(env('CMD_V4_INVOICE_STRICT', false), FILTER_VALIDATE_BOOLEAN)) {

            return true;

        }

        foreach (['facture', 'invoice', 'rechnung', 'fournisseur'] as $keyword) {

            if (str_contains($filename, $keyword)) {

                return true;

            }

        }

        return false;

    }

}

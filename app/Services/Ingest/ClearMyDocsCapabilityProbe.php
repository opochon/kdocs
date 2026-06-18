<?php

declare(strict_types=1);

namespace KDocs\Services\Ingest;

use KDocs\Services\ClearMyDocsSidecarClient;

/**
 * Détecte la disponibilité ClearMyDocs v3 pour le mode ingest couplé.
 */
class ClearMyDocsCapabilityProbe
{
    private ClearMyDocsSidecarClient $client;

    public function __construct(?ClearMyDocsSidecarClient $client = null)
    {
        $this->client = $client ?? new ClearMyDocsSidecarClient();
    }

    public function configuredEngineMode(): string
    {
        $mode = strtolower(trim((string) env('INGEST_ENGINE', 'auto')));
        if (!in_array($mode, ['auto', 'coupled', 'native'], true)) {
            return 'auto';
        }

        return $mode;
    }

    public function minVersion(): string
    {
        return (string) env('CLEARMYDOCS_MIN_VERSION', '3.0.0');
    }

    public function installPath(): ?string
    {
        $path = env('CLEARMYDOCS_PATH');
        if ($path === null || trim((string) $path) === '') {
            return null;
        }

        $resolved = realpath((string) $path);

        return ($resolved !== false && is_dir($resolved)) ? $resolved : null;
    }

    /** @return array<string, mixed> */
    public function probe(bool $forceHealthCheck = true): array
    {
        $mode = $this->configuredEngineMode();
        $enabled = filter_var(env('CLEARMYDOCS_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        $path = $this->installPath();
        $sidecarUrl = $this->client->baseUrl();
        $minVersion = $this->minVersion();

        $health = null;
        $versionOk = false;
        $sidecarOk = false;

        if ($forceHealthCheck && $enabled) {
            $health = $this->client->request('GET', '/health');
            $sidecarOk = ($health['status'] ?? null) === 'ok';
            if ($sidecarOk && isset($health['version'])) {
                $versionOk = version_compare((string) $health['version'], $minVersion, '>=');
            }
        }

        $pathOk = $path !== null;
        $capabilities = is_array($health['capabilities'] ?? null) ? $health['capabilities'] : [];

        $coupledAvailable = $enabled && $pathOk && $sidecarOk && $versionOk;

        $activeEngine = match ($mode) {
            'native' => 'native',
            'coupled' => $coupledAvailable ? 'coupled' : 'coupled_unavailable',
            default => $coupledAvailable ? 'coupled' : 'native',
        };

        return [
            'configured_mode' => $mode,
            'active_engine' => $activeEngine,
            'coupled_available' => $coupledAvailable,
            'clearmydocs_enabled' => $enabled,
            'install_path' => $path,
            'install_path_ok' => $pathOk,
            'sidecar_url' => $sidecarUrl,
            'sidecar_ok' => $sidecarOk,
            'sidecar_version' => $health['version'] ?? null,
            'min_version' => $minVersion,
            'version_ok' => $versionOk,
            'capabilities' => $capabilities,
            'health' => $health,
        ];
    }

    public function shouldUseCoupledEngine(): bool
    {
        $status = $this->probe();
        $mode = $status['configured_mode'];

        if ($mode === 'native') {
            return false;
        }

        if ($mode === 'coupled') {
            return (bool) $status['coupled_available'];
        }

        return (bool) $status['coupled_available'];
    }

    public function requiresCoupledEngine(): bool
    {
        return $this->configuredEngineMode() === 'coupled';
    }
}

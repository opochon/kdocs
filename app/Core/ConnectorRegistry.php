<?php

declare(strict_types=1);

namespace KDocs\Core;

use KDocs\Services\Ingest\ClearMyDocsCapabilityProbe;
use KDocs\Services\WinBiz\WinBizBridgeClient;

/**
 * Registre unifié connecteurs (ingest, ERP) et plugins métier (apps/).
 * Spec : docs/CONNECTEURS-PLUGINS.md — lot P1.
 */
class ConnectorRegistry
{
    private static ?array $config = null;

    /** @return array<string, mixed> */
    public static function config(): array
    {
        if (self::$config === null) {
            $file = dirname(__DIR__, 2) . '/config/connectors.php';
            self::$config = is_file($file) ? require $file : [];
        }

        return self::$config;
    }

    /**
     * Santé de tous les connecteurs et plugins.
     *
     * @return array{generated_at: string, connectors: array<string, array>, plugins: array<string, array>}
     */
    public static function healthAll(bool $probeRemote = true): array
    {
        $cfg = self::config();
        $connectors = [];

        foreach ($cfg['ingest'] ?? [] as $id => $def) {
            $connectors[$id] = self::healthIngest((string) $id, $def, $probeRemote);
        }

        foreach ($cfg['erp'] ?? [] as $id => $def) {
            $connectors[$id] = self::healthErp((string) $id, $def, $probeRemote);
        }

        $plugins = [];
        foreach ($cfg['apps'] ?? [] as $id => $def) {
            $plugins[$id] = self::healthPlugin((string) $id, $def, $connectors);
        }

        return [
            'generated_at' => date('c'),
            'connectors' => $connectors,
            'plugins' => $plugins,
        ];
    }

    public static function isConnectorAvailable(string $connectorId): bool
    {
        $all = self::healthAll(false);
        $conn = $all['connectors'][$connectorId] ?? null;

        return is_array($conn) && ($conn['available'] ?? false) === true;
    }

    public static function isPluginAvailable(string $pluginId): bool
    {
        $all = self::healthAll(false);
        $plugin = $all['plugins'][$pluginId] ?? null;

        return is_array($plugin) && ($plugin['status'] ?? '') === 'available';
    }

    /**
     * @param array<string, mixed> $def
     *
     * @return array<string, mixed>
     */
    private static function healthIngest(string $id, array $def, bool $probeRemote): array
    {
        $base = [
            'id' => $id,
            'group' => 'ingest',
            'label' => (string) ($def['label'] ?? $id),
            'description' => (string) ($def['description'] ?? ''),
            'capabilities' => $def['capabilities'] ?? [],
        ];

        if (!empty($def['always'])) {
            return array_merge($base, [
                'enabled' => true,
                'available' => true,
                'status' => 'available',
                'message' => 'Socle GED — toujours actif',
            ]);
        }

        $enabled = self::envBool((string) ($def['enabled_env'] ?? ''));
        $url = self::envString((string) ($def['url_env'] ?? ''));
        $path = self::resolvePath((string) ($def['path_env'] ?? ''));

        if (!$enabled) {
            return array_merge($base, [
                'enabled' => false,
                'available' => false,
                'status' => 'disabled',
                'url' => $url,
                'path' => $path,
                'message' => 'Non activé dans .env',
            ]);
        }

        if ($id === 'ingest-cmd-v3') {
            $probe = (new ClearMyDocsCapabilityProbe())->probe($probeRemote);

            return array_merge($base, [
                'enabled' => true,
                'available' => (bool) ($probe['coupled_available'] ?? false),
                'status' => ($probe['coupled_available'] ?? false) ? 'available' : 'unavailable',
                'url' => $probe['sidecar_url'] ?? $url,
                'path' => $probe['install_path'] ?? $path,
                'active_engine' => $probe['active_engine'] ?? null,
                'sidecar_version' => $probe['sidecar_version'] ?? null,
                'message' => ($probe['coupled_available'] ?? false)
                    ? 'Sidecar joignable'
                    : 'Activé mais sidecar ou chemin indisponible',
                'details' => $probe,
            ]);
        }

        if ($id === 'ingest-cmd-v4') {
            $remote = $probeRemote ? self::httpHealth($url) : ['ok' => false, 'skipped' => true];

            return array_merge($base, [
                'enabled' => true,
                'available' => $remote['ok'] && $path !== null,
                'status' => ($remote['ok'] && $path !== null) ? 'available' : 'unavailable',
                'url' => $url,
                'path' => $path,
                'remote_ok' => $remote['ok'],
                'version' => $remote['version'] ?? null,
                'message' => $remote['ok']
                    ? 'API v4 joignable'
                    : ($path === null ? 'Chemin CMD_V4_PATH invalide' : 'API v4 injoignable'),
            ]);
        }

        return array_merge($base, [
            'enabled' => $enabled,
            'available' => false,
            'status' => 'unavailable',
            'message' => 'Connecteur ingest non instrumenté',
        ]);
    }

    /**
     * @param array<string, mixed> $def
     *
     * @return array<string, mixed>
     */
    private static function healthErp(string $id, array $def, bool $probeRemote): array
    {
        $base = [
            'id' => $id,
            'group' => 'erp',
            'label' => (string) ($def['label'] ?? $id),
            'description' => (string) ($def['description'] ?? ''),
            'capabilities' => $def['capabilities'] ?? [],
            'external_repo' => $def['external_repo'] ?? null,
        ];

        $enabled = self::envBool((string) ($def['enabled_env'] ?? ''));
        $url = self::envString((string) ($def['url_env'] ?? ''));

        if (!$enabled) {
            return array_merge($base, [
                'enabled' => false,
                'available' => false,
                'status' => 'disabled',
                'url' => $url,
                'message' => 'Non activé dans .env',
            ]);
        }

        if ($url === '') {
            return array_merge($base, [
                'enabled' => true,
                'available' => false,
                'status' => 'unavailable',
                'url' => null,
                'message' => 'URL bridge non configurée',
            ]);
        }

        $bridgeOk = false;
        $bridgeMessage = 'Probe désactivé';

        if ($probeRemote) {
            $health = (new WinBizBridgeClient($url))->health();
            $bridgeOk = $health['ok'];
            $bridgeMessage = $bridgeOk
                ? 'Bridge joignable'
                : (string) ($health['error'] ?? 'Bridge injoignable');
        }

        return array_merge($base, [
            'enabled' => true,
            'available' => $bridgeOk,
            'status' => $bridgeOk ? 'available' : 'unavailable',
            'url' => $url,
            'message' => $bridgeMessage,
            'note' => $def['note'] ?? null,
        ]);
    }

    /**
     * @param array<string, array<string, mixed>> $connectorsHealth
     * @param array<string, mixed> $def
     *
     * @return array<string, mixed>
     */
    private static function healthPlugin(string $id, array $def, array $connectorsHealth): array
    {
        $enabled = self::envBool((string) ($def['enabled_env'] ?? ''))
            || PluginRegistry::isEnabled($id);

        $base = [
            'id' => $id,
            'label' => (string) ($def['label'] ?? $id),
            'enabled' => $enabled,
            'requires' => $def['requires'] ?? [],
        ];

        if (!$enabled) {
            return array_merge($base, [
                'status' => 'disabled',
                'available' => false,
                'message' => 'Plugin désactivé',
            ]);
        }

        $blockedBy = [];
        foreach ($def['requires'] ?? [] as $reqId) {
            $req = $connectorsHealth[$reqId] ?? null;
            if (!is_array($req) || !($req['available'] ?? false)) {
                $blockedBy[] = (string) $reqId;
            }
        }

        if ($blockedBy !== []) {
            return array_merge($base, [
                'status' => 'blocked',
                'available' => false,
                'blocked_by' => $blockedBy,
                'message' => 'Connecteur requis indisponible : ' . implode(', ', $blockedBy),
            ]);
        }

        return array_merge($base, [
            'status' => 'available',
            'available' => true,
            'message' => 'Plugin actif',
        ]);
    }

    private static function envBool(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        return filter_var(env($key, false), FILTER_VALIDATE_BOOLEAN);
    }

    private static function envString(string $key): ?string
    {
        if ($key === '') {
            return null;
        }

        $value = trim((string) env($key, ''));

        return $value !== '' ? $value : null;
    }

    private static function resolvePath(string $pathEnvKey): ?string
    {
        if ($pathEnvKey === '') {
            return null;
        }

        $raw = env($pathEnvKey);
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        $resolved = realpath((string) $raw);

        return ($resolved !== false && is_dir($resolved)) ? $resolved : null;
    }

    /**
     * @return array{ok: bool, version?: string, error?: string}
     */
    private static function httpHealth(?string $baseUrl): array
    {
        if ($baseUrl === null || $baseUrl === '') {
            return ['ok' => false, 'error' => 'URL vide'];
        }

        $ch = curl_init(rtrim($baseUrl, '/') . '/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['ok' => false, 'error' => $err];
        }

        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'error' => "HTTP {$status}"];
        }

        $json = json_decode((string) $body, true);
        $version = is_array($json) ? ($json['version'] ?? null) : null;

        return ['ok' => true, 'version' => is_string($version) ? $version : null];
    }
}

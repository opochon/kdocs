<?php

namespace KDocs\Core;

use Slim\Routing\RouteCollectorProxy;

/**
 * Charge dynamiquement les routes des apps satellites (apps/.../routes.php).
 */
class PluginRegistry
{
    /**
     * @return list<string> Apps chargées
     */
    public static function registerAppRoutes(RouteCollectorProxy $group): array
    {
        if (!function_exists('env')) {
            require_once dirname(__DIR__) . '/helpers.php';
        }

        $loaded = [];
        $pattern = dirname(__DIR__, 2) . '/apps/*/routes.php';

        foreach (glob($pattern) ?: [] as $routesFile) {
            $appDir = dirname($routesFile);
            $appName = basename($appDir);

            // K-Time reste enregistré explicitement dans index.php (historique)
            if ($appName === 'timetrack') {
                continue;
            }

            $configFile = $appDir . '/config.php';
            if (is_file($configFile)) {
                $config = require $configFile;
                if (!($config['app']['enabled'] ?? false)) {
                    continue;
                }
            }

            $register = require $routesFile;
            if (is_callable($register)) {
                $register($group);
                $loaded[] = $appName;
            }
        }

        return $loaded;
    }

    /**
     * Indique si une app satellite est activée (gating UI côté templates).
     * Source de vérité : apps/{name}/config.php → app.enabled.
     */
    public static function isEnabled(string $appName): bool
    {
        if (!function_exists('env')) {
            require_once dirname(__DIR__) . '/helpers.php';
        }

        $configFile = dirname(__DIR__, 2) . '/apps/' . $appName . '/config.php';
        if (!is_file($configFile)) {
            return false;
        }

        $config = require $configFile;

        return (bool) ($config['app']['enabled'] ?? false);
    }
}

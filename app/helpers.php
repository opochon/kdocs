<?php
/**
 * Fonctions helper globales pour K-Docs
 */

use KDocs\Core\Config;

if (!function_exists('loadEnv')) {
    /**
     * Charge un fichier .env simple (KEY=VALUE) vers $_ENV / putenv.
     */
    function loadEnv(?string $path = null): void
    {
        $path = $path ?? dirname(__DIR__) . '/.env';
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = trim($value, " \t\"'");
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

loadEnv();

if (!function_exists('env')) {
    /**
     * Lit une variable d'environnement avec valeur par défaut.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);
        return ($value === false || $value === null || $value === '') ? $default : $value;
    }
}

if (!function_exists('url')) {
    /**
     * Génère une URL avec le base path de l'application
     * 
     * @param string $path Chemin relatif (ex: '/login' ou 'login')
     * @return string URL complète avec base path
     */
    function url(string $path = ''): string
    {
        $basePath = Config::basePath();
        $path = ltrim($path, '/');
        return $basePath . ($path ? '/' . $path : '');
    }
}

if (!function_exists('asset')) {
    /**
     * URL d'un asset statique sous public/ (CSS, JS, images).
     * Ex. asset('css/tailwind.css') → /kdocs/public/css/tailwind.css
     */
    function asset(string $path): string
    {
        return url('public/' . ltrim($path, '/'));
    }
}

if (!function_exists('isAppDebug')) {
    function isAppDebug(): bool
    {
        return filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN);
    }
}

if (!function_exists('documentVisibilitySql')) {
    /**
     * Filtre SQL excluant les documents de test (test_*) hors mode debug.
     *
     * @param string $alias Alias table documents (ex. "d")
     */
    function documentVisibilitySql(string $alias = 'documents'): string
    {
        if (isAppDebug()) {
            return '1=1';
        }

        $t = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'documents';

        return "({$t}.title NOT LIKE 'test\\_%' AND ({$t}.original_filename IS NULL OR {$t}.original_filename NOT LIKE 'test\\_%'))";
    }
}

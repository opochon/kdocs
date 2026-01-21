<?php
/**
 * Fonctions helper globales pour K-Docs
 */

use KDocs\Core\Config;

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

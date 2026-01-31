<?php
/**
 * Fonctions utilitaires partagees
 *
 * @package KDocs\Shared\Helpers
 */

if (!function_exists('app_path')) {
    /**
     * Retourne le chemin vers une app
     */
    function app_path(string $app, string $path = ''): string
    {
        $base = dirname(__DIR__, 2) . '/apps/' . $app;
        return $path ? $base . '/' . ltrim($path, '/') : $base;
    }
}

if (!function_exists('shared_path')) {
    /**
     * Retourne le chemin vers le dossier shared
     */
    function shared_path(string $path = ''): string
    {
        $base = dirname(__DIR__);
        return $path ? $base . '/' . ltrim($path, '/') : $base;
    }
}

if (!function_exists('storage_path')) {
    /**
     * Retourne le chemin vers le dossier storage
     */
    function storage_path(string $path = ''): string
    {
        $base = dirname(__DIR__, 2) . '/storage';
        return $path ? $base . '/' . ltrim($path, '/') : $base;
    }
}

if (!function_exists('format_duration')) {
    /**
     * Formate une duree en heures decimales vers HH:MM
     */
    function format_duration(float $hours): string
    {
        $h = floor($hours);
        $m = round(($hours - $h) * 60);
        return sprintf('%d:%02d', $h, $m);
    }
}

if (!function_exists('parse_duration')) {
    /**
     * Parse une duree (2.5h, 2h30, 2:30) vers heures decimales
     */
    function parse_duration(string $input): ?float
    {
        $input = trim($input);

        // Format: 2.5h ou 2,5h
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*h?$/i', $input, $m)) {
            return floatval(str_replace(',', '.', $m[1]));
        }

        // Format: 2h30 ou 2h30m
        if (preg_match('/^(\d+)\s*h\s*(\d+)\s*m?$/i', $input, $m)) {
            return intval($m[1]) + intval($m[2]) / 60;
        }

        // Format: 2:30
        if (preg_match('/^(\d+):(\d{2})$/', $input, $m)) {
            return intval($m[1]) + intval($m[2]) / 60;
        }

        return null;
    }
}

if (!function_exists('format_money')) {
    /**
     * Formate un montant en CHF
     */
    function format_money(float $amount, string $currency = 'CHF'): string
    {
        return number_format($amount, 2, '.', "'") . ' ' . $currency;
    }
}

if (!function_exists('slugify')) {
    /**
     * Genere un slug a partir d'une chaine
     */
    function slugify(string $text): string
    {
        // Remplacer les caracteres non-ASCII
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
        // Garder uniquement lettres, chiffres, tirets
        $text = preg_replace('/[^a-z0-9-]/', '-', $text);
        // Supprimer les tirets multiples
        $text = preg_replace('/-+/', '-', $text);
        return trim($text, '-');
    }
}

if (!function_exists('is_kdocs_available')) {
    /**
     * Verifie si K-Docs Core est disponible
     */
    function is_kdocs_available(): bool
    {
        return class_exists('KDocs\\Core\\Database');
    }
}

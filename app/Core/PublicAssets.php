<?php
/**
 * Servir les fichiers statiques sous public/ (CSS, JS, fonts, images).
 * Utilisé par router.php (dev PHP built-in) et la route Slim /public/*.
 */

declare(strict_types=1);

namespace KDocs\Core;

class PublicAssets
{
    private const MIME_TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'map' => 'application/json',
    ];

    public static function publicRoot(): string
    {
        return dirname(__DIR__, 2) . '/public';
    }

    public static function sanitizeRelativePath(string $relativePath): string
    {
        return str_replace(['..', '\\'], ['', '/'], $relativePath);
    }

    public static function resolveFile(string $relativePath): ?string
    {
        $relativePath = self::sanitizeRelativePath($relativePath);
        $file = self::publicRoot() . '/' . ltrim($relativePath, '/');

        return is_file($file) ? $file : null;
    }

    public static function mimeType(string $file): string
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        return self::MIME_TYPES[$ext] ?? 'application/octet-stream';
    }

    /**
     * Envoie le fichier au client (router.php). Retourne false si introuvable.
     */
    public static function serve(string $relativePath): bool
    {
        $file = self::resolveFile($relativePath);
        if ($file === null) {
            return false;
        }

        header('Content-Type: ' . self::mimeType($file));
        header('Cache-Control: public, max-age=3600');
        readfile($file);

        return true;
    }
}

<?php

declare(strict_types=1);

namespace KDocs\Services\Storage;

use KDocs\Core\Config;

/**
 * Dossiers pipeline GED — masqués de l'arborescence utilisateur.
 * L'arborescence « Dossiers » reflète le stockage métier existant, pas consume/toclassify/etc.
 */
class InternalFolderRegistry
{
  /** @return list<string> */
    public static function defaultHiddenNames(): array
    {
        return [
            'toclassify',
            'consume',
            'processed',
            'trash',
            'temp',
            'thumbnails',
            'pending',
            '_incoming',
            '.trash',
            '.git',
            'node_modules',
            'vendor',
            '__MACOSX',
            'Thumbs.db',
        ];
    }

    /** @return list<string> */
    public static function hiddenNames(): array
    {
        $configured = Config::get('storage.internal_folders', []);
        if (is_string($configured)) {
            $configured = array_map('trim', explode(',', $configured));
        }
        if (!is_array($configured)) {
            $configured = [];
        }

        $legacy = Config::get('storage.ignore_folders', []);
        if (is_string($legacy)) {
            $legacy = array_map('trim', explode(',', $legacy));
        }
        if (!is_array($legacy)) {
            $legacy = [];
        }

        $merged = array_merge(self::defaultHiddenNames(), $legacy, $configured);
        $normalized = [];
        foreach ($merged as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $normalized[] = $name;
            }
        }

        return array_values(array_unique($normalized));
    }

    public static function isHiddenFolderName(string $name): bool
    {
        if ($name === '' || $name === '.' || $name === '..') {
            return true;
        }
        if ($name[0] === '.') {
            return true;
        }

        $lower = strtolower($name);
        foreach (self::hiddenNames() as $hidden) {
            if (strtolower($hidden) === $lower) {
                return true;
            }
        }

        return false;
    }

    public static function isHiddenPath(string $relativePath): bool
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '') {
            return false;
        }

        foreach (explode('/', $relativePath) as $segment) {
            if (self::isHiddenFolderName($segment)) {
                return true;
            }
        }

        return false;
    }
}

<?php
/**
 * K-Docs - System Helper
 * Fonctions utilitaires cross-platform (Windows/Linux)
 */

namespace KDocs\Helpers;

class SystemHelper
{
    /**
     * Commande pour trouver un exécutable dans le PATH
     */
    public static function whichCommand(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
    }

    /**
     * Redirection null pour les erreurs shell
     */
    public static function nullRedirect(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? '2>nul' : '2>/dev/null';
    }

    /**
     * Vérifie si un exécutable existe dans le PATH
     */
    public static function commandExists(string $command): bool
    {
        $which = self::whichCommand();
        $null = self::nullRedirect();
        exec("$which $command $null", $output, $code);
        return $code === 0;
    }

    /**
     * Trouve le chemin d'un exécutable
     */
    public static function findExecutable(string $command, array $fallbackPaths = []): ?string
    {
        // D'abord dans le PATH
        if (self::commandExists($command)) {
            return $command;
        }

        // Sinon dans les chemins fallback
        foreach ($fallbackPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Chemins par défaut selon l'OS
     */
    public static function getDefaultPaths(string $tool): array
    {
        $paths = [
            'tesseract' => [
                'Windows' => [
                    'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
                    'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
                ],
                'Linux' => ['/usr/bin/tesseract', '/usr/local/bin/tesseract'],
            ],
            'ghostscript' => [
                'Windows' => [], // Utiliser glob pour trouver la version
                'Linux' => ['/usr/bin/gs', '/usr/local/bin/gs'],
            ],
            'imagemagick' => [
                'Windows' => ['C:\\Program Files\\ImageMagick-7.1.2-Q16-HDRI\\magick.exe'],
                'Linux' => ['/usr/bin/convert', '/usr/bin/magick'],
            ],
            'pdftotext' => [
                'Windows' => [
                    'C:\\Program Files\\Git\\mingw64\\bin\\pdftotext.exe',
                    'C:\\Program Files\\poppler\\bin\\pdftotext.exe',
                ],
                'Linux' => ['/usr/bin/pdftotext'],
            ],
            'pdftoppm' => [
                'Windows' => [
                    'C:\\Program Files\\Git\\mingw64\\bin\\pdftoppm.exe',
                    'C:\\Program Files\\poppler\\bin\\pdftoppm.exe',
                ],
                'Linux' => ['/usr/bin/pdftoppm'],
            ],
            'libreoffice' => [
                'Windows' => [
                    'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
                    'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
                ],
                'Linux' => ['/usr/bin/libreoffice', '/usr/bin/soffice', '/snap/bin/libreoffice'],
            ],
        ];

        $os = PHP_OS_FAMILY === 'Windows' ? 'Windows' : 'Linux';
        return $paths[$tool][$os] ?? [];
    }

    /**
     * Trouve Ghostscript avec support des versions
     */
    public static function findGhostscript(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Chercher les différentes versions installées
            $paths = glob('C:/Program Files/gs/gs*/bin/gswin64c.exe');
            if (!empty($paths)) {
                rsort($paths); // Prendre la version la plus récente
                return $paths[0];
            }
            $paths = glob('C:/Program Files (x86)/gs/gs*/bin/gswin32c.exe');
            if (!empty($paths)) {
                rsort($paths);
                return $paths[0];
            }
        } else {
            foreach (['/usr/bin/gs', '/usr/local/bin/gs'] as $path) {
                if (file_exists($path)) {
                    return $path;
                }
            }
        }

        // Essayer dans le PATH
        if (self::commandExists('gs')) {
            return 'gs';
        }

        return null;
    }

    /**
     * Trouve LibreOffice
     */
    public static function findLibreOffice(): ?string
    {
        $paths = self::getDefaultPaths('libreoffice');
        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        // Essayer dans le PATH
        $commands = PHP_OS_FAMILY === 'Windows' ? ['soffice.exe', 'soffice'] : ['libreoffice', 'soffice'];
        foreach ($commands as $cmd) {
            if (self::commandExists($cmd)) {
                return $cmd;
            }
        }

        return null;
    }

    /**
     * Retourne le séparateur de chemin selon l'OS
     */
    public static function pathSeparator(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? '\\' : '/';
    }

    /**
     * Normalise un chemin selon l'OS
     */
    public static function normalizePath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        return rtrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * Vérifie si on est sur Windows
     */
    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * Vérifie si on est sur Linux
     */
    public static function isLinux(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }
}

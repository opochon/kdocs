<?php

declare(strict_types=1);

namespace Ged\Apercu;

/**
 * Détection des outils de rendu présents sur le poste et conversions associées (PDF → PNG, image → PNG,
 * bureautique → PDF). Aucun outil n'est obligatoire : chaque absence produit un message nommé, jamais une
 * exception muette. Adapté de l'expérience GEDv1 (`ThumbnailGenerator`, `OfficeThumbnailService`), réécrit.
 */
final class Outils
{
    public const MSG_LIBREOFFICE = 'aperçu indisponible : LibreOffice absent';
    public const MSG_FORMAT = 'aperçu indisponible : format non pris en charge';
    public const MSG_VIGNETTE = 'aperçu indisponible : miniature non générée';
    public const MSG_RENDU = 'aperçu indisponible : outil de rendu PDF absent';
    public const MSG_IMAGEMAGICK = 'aperçu indisponible : ImageMagick absent';
    public const MSG_CONVERSION = 'aperçu indisponible : conversion impossible';

    /** @var array<string, ?string> */
    private static array $cache = [];

    public static function pdftoppm(): ?string
    {
        return self::binaire('pdftoppm', ['pdftoppm']);
    }

    public static function ghostscript(): ?string
    {
        return self::binaire('ghostscript', ['gs', 'gswin64c', 'gswin32c']);
    }

    public static function magick(): ?string
    {
        return self::binaire('magick', ['magick', 'convert']);
    }

    public static function libreOffice(): ?string
    {
        $trouve = self::binaire('libreoffice', ['soffice', 'libreoffice']);
        if ($trouve !== null) {
            return $trouve;
        }
        // Emplacements d'installation habituels, non garantis dans le PATH.
        $candidats = [
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
            '/usr/bin/libreoffice',
            '/usr/local/bin/soffice',
            '/Applications/LibreOffice.app/Contents/MacOS/soffice',
        ];
        foreach ($candidats as $candidat) {
            if (is_file($candidat)) {
                return self::$cache['libreoffice'] = $candidat;
            }
        }

        return null;
    }

    /**
     * Convertit la première page d'un PDF en PNG. Renvoie null si la conversion a réussi, sinon un message nommé.
     */
    public static function pdfVersPng(string $pdf, string $png, int $largeur): ?string
    {
        $pdftoppm = self::pdftoppm();
        if ($pdftoppm !== null) {
            // -singlefile nomme exactement le PNG demandé, sans suffixe de page.
            $base = preg_replace('/\.png$/i', '', $png) ?? $png;
            self::executer([$pdftoppm, '-png', '-f', '1', '-l', '1', '-singlefile', '-scale-to', (string) $largeur, $pdf, $base]);
            if (is_file($png)) {
                return null;
            }
        }

        $gs = self::ghostscript();
        if ($gs !== null) {
            self::executer([$gs, '-dNOPAUSE', '-dBATCH', '-sDEVICE=png16m', '-dFirstPage=1', '-dLastPage=1',
                '-dPDFFitPage', '-r150', '-sOutputFile=' . $png, $pdf]);
            if (is_file($png)) {
                return null;
            }
        }

        return $gs === null && $pdftoppm === null ? self::MSG_RENDU : self::MSG_CONVERSION;
    }

    /** Convertit une image (y compris TIFF, non lisible par GD) en PNG. */
    public static function imageVersPng(string $image, string $png, int $largeur): ?string
    {
        if (in_array(strtolower(pathinfo($image, PATHINFO_EXTENSION)), ['tif', 'tiff'], true)) {
            $magick = self::magick();
            if ($magick === null) {
                return self::MSG_IMAGEMAGICK;
            }
            self::executer([$magick, $image, '-resize', $largeur . 'x', '-flatten', $png]);
            if (is_file($png)) {
                return null;
            }

            return self::MSG_CONVERSION;
        }

        $source = self::chargerImage($image);
        if ($source === null) {
            return self::MSG_CONVERSION;
        }
        $ok = self::ecrirePng($source, $png, $largeur);
        imagedestroy($source);

        return $ok ? null : self::MSG_CONVERSION;
    }

    /** Convertit un document bureautique en PDF via LibreOffice headless. */
    public static function versPdf(string $source, string $pdf): ?string
    {
        $soffice = self::libreOffice();
        if ($soffice === null) {
            return self::MSG_LIBREOFFICE;
        }
        $dossier = dirname($pdf);
        if (!is_dir($dossier)) {
            mkdir($dossier, 0777, true);
        }
        $attendu = $dossier . DIRECTORY_SEPARATOR . pathinfo($source, PATHINFO_FILENAME) . '.pdf';
        self::executer([$soffice, '--headless', '--convert-to', 'pdf', '--outdir', $dossier, $source]);
        if (is_file($attendu) && $attendu !== $pdf && !@rename($attendu, $pdf)) {
            return self::MSG_CONVERSION;
        }

        return is_file($pdf) ? null : self::MSG_CONVERSION;
    }

    private static function chargerImage(string $image): ?\GdImage
    {
        $extension = strtolower(pathinfo($image, PATHINFO_EXTENSION));
        $chargeur = match ($extension) {
            'png' => 'imagecreatefrompng',
            'jpg', 'jpeg' => 'imagecreatefromjpeg',
            'gif' => 'imagecreatefromgif',
            'webp' => 'imagecreatefromwebp',
            'bmp' => 'imagecreatefrombmp',
            'avif' => 'imagecreatefromavif',
            default => null,
        };
        if ($chargeur === null || !function_exists($chargeur)) {
            return null;
        }

        return @$chargeur($image) ?: null;
    }

    private static function ecrirePng(\GdImage $source, string $png, int $largeurMax): bool
    {
        $largeur = imagesx($source);
        $hauteur = imagesy($source);
        $ratio = min(1.0, $largeurMax / max(1, $largeur));
        $cible = imagecreatetruecolor(max(1, (int) ($largeur * $ratio)), max(1, (int) ($hauteur * $ratio)));
        imagefill($cible, 0, 0, imagecolorallocate($cible, 255, 255, 255));
        imagecopyresampled($cible, $source, 0, 0, 0, 0, imagesx($cible), imagesy($cible), $largeur, $hauteur);
        $ok = imagepng($cible, $png);
        imagedestroy($cible);

        return $ok;
    }

    /** @param list<string> $commande */
    private static function executer(array $commande): void
    {
        $ligne = implode(' ', array_map('escapeshellarg', $commande));
        exec($ligne . ' 2>&1', $sortie, $code);
    }

    /** @param list<string> $noms */
    private static function binaire(string $cle, array $noms): ?string
    {
        if (array_key_exists($cle, self::$cache)) {
            return self::$cache[$cle];
        }

        return self::$cache[$cle] = self::chercher($noms);
    }

    /**
     * Cherche un exécutable dans le PATH. On teste aussi les suffixes Windows sans dépendre de la casse.
     *
     * @param list<string> $noms
     */
    private static function chercher(array $noms): ?string
    {
        $dossiers = array_filter(explode(PATH_SEPARATOR, (string) getenv('PATH')));
        $suffixes = PHP_OS_FAMILY === 'Windows' ? ['', '.exe', '.cmd', '.bat'] : [''];
        foreach ($noms as $nom) {
            foreach ($suffixes as $suffixe) {
                foreach ($dossiers as $dossier) {
                    $candidat = rtrim($dossier, '/\\') . DIRECTORY_SEPARATOR . $nom . $suffixe;
                    if (is_file($candidat)) {
                        return $candidat;
                    }
                }
            }
        }

        return null;
    }
}

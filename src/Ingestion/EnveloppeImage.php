<?php

declare(strict_types=1);

namespace Ged\Ingestion;

/**
 * Enveloppe PDF d'une image, en mémoire : une page à la taille de l'image, un XObject image. Ghostscript
 * ne lit pas une image seule, mais lit un PDF ; il reçoit donc ce PDF d'une page et produit la copie
 * PDF/A-2b finale.
 *
 * - JPEG : les octets d'origine sont réutilisés tels quels (`/DCTDecode`), sans ré-encodage ;
 * - autres formats bitmap (PNG, GIF, WEBP, BMP, AVIF) : décodés par GD puis écrits en RVB brut compressé
 *   (`/FlateDecode`). Le TIFF, que GD ne lit pas, n'est pas enveloppé ici.
 *
 * Aucune dépendance externe : c'est du PDF minimal écrit à la main, réservé à l'entrée du convertisseur.
 */
final class EnveloppeImage
{
    /** Rend les octets du PDF d'une page, ou null si l'image n'est pas reconnue. */
    public static function depuis(string $fichier): ?string
    {
        $dimensions = @getimagesize($fichier);
        if ($dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1) {
            return null;
        }
        [$largeur, $hauteur, $type] = [$dimensions[0], $dimensions[1], $dimensions[2]];

        $image = $type === IMAGETYPE_JPEG ? self::jpeg($fichier, $largeur, $hauteur) : self::bitmap($fichier, $largeur, $hauteur);
        if ($image === null) {
            return null;
        }

        return self::assembler($largeur, $hauteur, $image);
    }

    /**
     * @return array{0:string, 1:string, 2:string, 3:string}|null [filtre, couleur, données, decode ou '']
     */
    private static function jpeg(string $fichier, int $largeur, int $hauteur): ?array
    {
        $octets = @file_get_contents($fichier);
        if ($octets === false || $octets === '') {
            return null;
        }
        $composantes = self::composantesJpeg($octets);
        if ($composantes === 1) {
            return ['/DCTDecode', '/DeviceGray', $octets, ''];
        }
        if ($composantes === 3) {
            return ['/DCTDecode', '/DeviceRGB', $octets, ''];
        }
        // CMYK/Adobe (4 composantes) ou inconnu : on laisse GD décoder en RVB.
        return self::bitmap($fichier, $largeur, $hauteur);
    }

    /**
     * @return array{0:string, 1:string, 2:string, 3:string}|null
     */
    private static function bitmap(string $fichier, int $largeur, int $hauteur): ?array
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $source = @imagecreatefromstring((string) file_get_contents($fichier));
        if (!$source instanceof \GdImage) {
            return null;
        }
        $rvb = imagecreatetruecolor($largeur, $hauteur);
        imagecopy($rvb, $source, 0, 0, 0, 0, $largeur, $hauteur);
        imagedestroy($source);
        $brut = '';
        for ($y = 0; $y < $hauteur; $y++) {
            for ($x = 0; $x < $largeur; $x++) {
                $couleur = imagecolorat($rvb, $x, $y);
                $brut .= chr(($couleur >> 16) & 0xFF) . chr(($couleur >> 8) & 0xFF) . chr($couleur & 0xFF);
            }
        }
        imagedestroy($rvb);
        $compresse = gzcompress($brut, 6);
        if ($compresse === false) {
            return null;
        }

        return ['/FlateDecode', '/DeviceRGB', $compresse, ''];
    }

    /** Nombre de composantes d'un JPEG, lu sur le marqueur SOF (0 si introuvable). */
    private static function composantesJpeg(string $octets): int
    {
        $position = 2;
        $longueur = strlen($octets);
        while ($position + 4 < $longueur) {
            if ($octets[$position] !== "\xFF") {
                $position++;
                continue;
            }
            $marqueur = ord($octets[$position + 1]);
            if ($marqueur === 0xD8 || $marqueur === 0x01 || ($marqueur >= 0xD0 && $marqueur <= 0xD7)) {
                $position += 2;
                continue;
            }
            if ($marqueur === 0xD9 || $marqueur === 0xDA) {
                break;
            }
            $taille = unpack('n', substr($octets, $position + 2, 2))[1] ?? 0;
            $estSof = $marqueur >= 0xC0 && $marqueur <= 0xCF && !in_array($marqueur, [0xC4, 0xC8, 0xCC], true);
            if ($estSof && $position + 9 < $longueur) {
                return ord($octets[$position + 9]);
            }
            $position += 2 + $taille;
        }

        return 0;
    }

    /**
     * @param array{0:string, 1:string, 2:string, 3:string} $image
     */
    private static function assembler(int $largeur, int $hauteur, array $image): string
    {
        [$filtre, $couleur, $donnees, $decode] = $image;
        $objets = [];
        $objets[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objets[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objets[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $largeur $hauteur] "
            . '/Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>';
        $objets[4] = "<< /Type /XObject /Subtype /Image /Width $largeur /Height $hauteur /ColorSpace $couleur "
            . "/BitsPerComponent 8 /Filter $filtre$decode /Length " . strlen($donnees) . " >>\nstream\n" . $donnees . "\nendstream";
        $contenu = "q $largeur 0 0 $hauteur 0 0 cm /Im0 Do Q";
        $objets[5] = "<< /Length " . strlen($contenu) . " >>\nstream\n$contenu\nendstream";

        ksort($objets);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objets as $n => $corps) {
            $offsets[$n] = strlen($pdf);
            $pdf .= "$n 0 obj\n$corps\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objets) + 1) . "\n0000000000 65535 f \n";
        for ($n = 1; $n <= count($objets); $n++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objets) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";

        return $pdf;
    }
}

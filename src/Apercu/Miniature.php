<?php

declare(strict_types=1);

namespace Ged\Apercu;

/**
 * Miniature de première page : un artefact fabriqué HORS requête (commande `php tools/miniatures.php`),
 * rangé à côté des documents dans un dossier caché `.apercu/`, puis seulement servi par une route.
 */
final class Miniature
{
    /**
     * Fabrique la vignette PNG d'une pièce. Renvoie null en cas de succès, sinon un message nommé.
     */
    public function generer(Piece $piece, string $dest): ?string
    {
        $dossier = dirname($dest);
        if (!is_dir($dossier) && !mkdir($dossier, 0777, true) && !is_dir($dossier)) {
            return Outils::MSG_CONVERSION;
        }
        if ($piece->estPdf()) {
            return Outils::pdfVersPng($piece->chemin, $dest, 400);
        }
        if ($piece->estImage()) {
            return Outils::imageVersPng($piece->chemin, $dest, 400);
        }
        if ($piece->estBureautique()) {
            // Conversion bureautique → PDF puis vignette ; sans LibreOffice, le message est déjà nommé.
            $pdf = self::cheminApercuPdf($piece, $dossier);
            $raison = Outils::versPdf($piece->chemin, $pdf);
            if ($raison !== null) {
                return $raison;
            }

            return Outils::pdfVersPng($pdf, $dest, 400);
        }

        return Outils::MSG_FORMAT;
    }

    public static function dossier(Source $source): string
    {
        return rtrim(str_replace('\\', '/', $source->racine()), '/') . '/.apercu';
    }

    public static function cheminVignette(Piece $piece, string $dossier): string
    {
        return $dossier . '/' . $piece->id . '.vignette.png';
    }

    /** Image de prévisualisation d'un TIFF, convertie hors requête (un navigateur n'affiche pas le TIFF). */
    public static function cheminApercuImage(Piece $piece, string $dossier): string
    {
        return $dossier . '/' . $piece->id . '.apercu.png';
    }

    /** PDF de prévisualisation d'un document bureautique, converti hors requête. */
    public static function cheminApercuPdf(Piece $piece, string $dossier): string
    {
        return $dossier . '/' . $piece->id . '.apercu.pdf';
    }
}

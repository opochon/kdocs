<?php

declare(strict_types=1);

namespace Ged\Ingestion;

use Ged\Apercu\Miniature;
use Ged\Apercu\Outils;
use Ged\Apercu\Piece;
use Ged\Apercu\Source;

/**
 * Lecture d'une pièce : métadonnées, nombre de pages, texte et vignette.
 *
 * Équivalent GEDv2 de `documents.parsers.DocumentParser` et de ses sous-classes (rasterisée, texte,
 * Tika). GEDv2 ne réimplémente pas de parseurs Python : l'OCR et la découpe vivent dans le moteur externe
 * (`Ingestion\Cmd4Decoupeur`), les messages dans `Jonctions\Courriel`, les vignettes dans `Apercu`. Ce
 * service est l'aiguillage qui répond aux mêmes questions que Paperless — « combien de pages, quel
 * texte, quelle vignette » — en RÉUTILISANT ces briques, sans en dupliquer aucune.
 *
 * Seules les opérations purement locales sont faites ici : compter les pages d'un PDF, lire un fichier
 * texte (octets invalides remplacés, jamais une exception), déléguer la vignette à `Apercu`. Le texte
 * d'un document rasterisé vient de l'OCR externe, pas d'ici.
 */
final class ParseurPiece
{
    /**
     * Famille de parseur d'un type MIME (`RegistreParseurs`), ou null.
     */
    public static function famille(string $mime): ?string
    {
        return RegistreParseurs::parseurPourMime($mime);
    }

    /**
     * Nombre de pages d'une pièce : pages d'un PDF, 1 pour une image, null si indéterminable (jamais un
     * nombre inventé). Le comptage lit les objets `/Type /Page` du fichier, sans démon.
     */
    public static function nombreDePages(string $chemin, string $mime): ?int
    {
        if (!is_file($chemin)) {
            return null;
        }
        if ($mime === 'application/pdf') {
            $contenu = (string) @file_get_contents($chemin);
            $pages = preg_match_all('#/Type\s*/Page(?![A-Za-z])#', $contenu);

            return is_int($pages) && $pages > 0 ? $pages : null;
        }
        if (str_starts_with($mime, 'image/')) {
            return 1;
        }

        return null;
    }

    /**
     * Texte d'une pièce déjà lisible : fichiers texte et CSV. Les octets invalides sont remplacés (comme
     * le repli de Paperless), jamais une exception : un texte mal encodé reste indexable.
     */
    public static function texte(string $chemin, string $mime): ?string
    {
        if (!is_file($chemin) || !in_array($mime, ['text/plain', 'text/csv', 'text/markdown'], true)) {
            return null;
        }
        $contenu = (string) file_get_contents($chemin);
        if (!mb_check_encoding($contenu, 'UTF-8')) {
            $contenu = mb_scrub($contenu, 'UTF-8');
        }

        return $contenu;
    }

    /**
     * Métadonnées déterministes d'une pièce : type MIME, famille de parseur, nombre de pages, et si un
     * texte local a pu être lu.
     *
     * @return array{mime:string, famille:?string, pages:?int, texte_lu:bool}
     */
    public static function metadonnees(string $chemin, string $mime): array
    {
        return [
            'mime' => $mime,
            'famille' => self::famille($mime),
            'pages' => self::nombreDePages($chemin, $mime),
            'texte_lu' => self::texte($chemin, $mime) !== null,
        ];
    }

    /**
     * Vignette d'une pièce, déléguée à `Apercu` (PDF → PNG, image → PNG, bureautique → PDF → PNG).
     * Rend null si la vignette a été produite, sinon un message NOMMÉ d'`Outils` : jamais un échec muet.
     */
    public static function vignette(string $chemin, string $mime, string $dest): ?string
    {
        if (!is_file($chemin)) {
            return Outils::MSG_CONVERSION;
        }
        $extension = strtolower(pathinfo($chemin, PATHINFO_EXTENSION));
        $piece = new Piece(
            id: 0,
            chemin: $chemin,
            titre: pathinfo($chemin, PATHINFO_FILENAME),
            categorie: Source::categorie($extension),
            extension: $extension,
            pages: self::nombreDePages($chemin, $mime) ?? 1,
        );

        return (new Miniature())->generer($piece, $dest);
    }
}

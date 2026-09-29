<?php

declare(strict_types=1);

namespace Ged\Archivage;

/**
 * Les empreintes du scellement. On scelle une empreinte composite : le sha256 du fichier ET celui de son
 * `.meta.json`, liés de façon déterministe. Vérifier plus tard, c'est recalculer cette même chaîne.
 */
final class Empreintes
{
    /** sha256 (hex) du contenu d'un fichier ; un fichier illisible refuse le scellement (jamais un hash vide). */
    public static function fichier(string $absolu): string
    {
        $empreinte = is_file($absolu) ? hash_file('sha256', $absolu) : false;
        if ($empreinte === false) {
            throw ErreurScellement::scellement("fichier illisible : $absolu");
        }

        return $empreinte;
    }

    /** sha256 (hex) du `<fichier>.meta.json` situé à côté du document. */
    public static function meta(string $absoluDocument): string
    {
        $fichier = $absoluDocument . '.meta.json';
        if (!is_file($fichier)) {
            throw ErreurScellement::scellement("métadonnées absentes : $fichier");
        }
        $empreinte = hash_file('sha256', $fichier);
        if ($empreinte === false) {
            throw ErreurScellement::scellement("métadonnées illisibles : $fichier");
        }

        return $empreinte;
    }

    /** Empreinte composite scellée et horodatée par la TSA. Déterministe : fichier puis métadonnées. */
    public static function scellee(string $empreinteFichier, string $empreinteMeta): string
    {
        return hash('sha256', $empreinteFichier . "\n" . $empreinteMeta);
    }

    /** Repasse d'une empreinte hexadécimale à ses 32 octets binaires, pour le MessageImprint RFC 3161. */
    public static function binaire(string $empreinteHex): string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $empreinteHex) !== 1) {
            throw ErreurScellement::scellement('empreinte hexadécimale invalide');
        }
        $binaire = hex2bin($empreinteHex);
        if ($binaire === false) {
            throw ErreurScellement::scellement('empreinte hexadécimale illisible');
        }

        return $binaire;
    }
}

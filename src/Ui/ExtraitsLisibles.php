<?php

declare(strict_types=1);

namespace Ged\Ui;

/**
 * V2-10c — lisibilité d'un extrait OCR. Un encadré de recherche ne doit pas afficher un amas de
 * symboles ou une bouillie de consonnes : mieux vaut pas d'extrait qu'un extrait illisible.
 *
 * L'heuristique est sobre et volontairement sans dictionnaire :
 * - assez de lettres dans le texte (un vrai texte OCR en porte au moins 60 %) ;
 * - une majorité de mots portent une voyelle — tout mot français en a une, un bruit OCR non.
 *
 * Elle vit dans le secteur Interface : c'est une règle d'affichage, jamais une donnée métier.
 */
final class ExtraitsLisibles
{
    /** Part minimale de lettres dans le texte (hors espaces). */
    private const RATIO_LETTRES = 0.6;

    /** Part minimale de mots portant une voyelle. */
    private const RATIO_MOTS = 0.6;

    /** Nombre minimal de mots pour juger (en dessous, on ne montre pas). */
    private const MOTS_MINIMUM = 3;

    /** Un extrait jugé lisible : assez de lettres et des mots plausibles. */
    public static function lisible(string $texte): bool
    {
        $compact = (string) preg_replace('/\s+/u', '', trim($texte));
        $total = mb_strlen($compact);
        if ($total < 8) {
            return false;   // trop court pour dire quoi que ce soit : on ne montre pas
        }
        $lettres = preg_match_all('/\p{L}/u', $compact);
        if ($lettres === false || $lettres / $total < self::RATIO_LETTRES) {
            return false;
        }

        preg_match_all('/\p{L}{2,25}/u', $texte, $trouves);
        $mots = $trouves[0] ?? [];
        if (count($mots) < self::MOTS_MINIMUM) {
            return false;
        }
        $portentVoyelle = array_filter(
            $mots,
            static fn (string $mot): bool => preg_match('/[aeiouyàâäéèêëîïôöùûüœæ]/iu', $mot) === 1);
        unset($trouves);

        return count($portentVoyelle) / count($mots) >= self::RATIO_MOTS;
    }

    /**
     * Ne retient que les extraits lisibles, dans l'ordre, et borne le nombre affiché.
     *
     * @param list<list<array{t:string, hit:bool}>> $extraits
     * @return list<list<array{t:string, hit:bool}>>
     */
    public static function retenir(array $extraits, int $max = 3): array
    {
        $gardes = [];
        foreach ($extraits as $extrait) {
            if (count($gardes) >= $max) {
                break;
            }
            $texte = implode('', array_map(
                static fn (mixed $segment): string => is_array($segment) ? (string) ($segment['t'] ?? '') : '',
                $extrait));
            if (self::lisible($texte)) {
                $gardes[] = $extrait;
            }
        }

        return $gardes;
    }
}

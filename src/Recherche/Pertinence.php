<?php

declare(strict_types=1);

namespace Ged\Recherche;

/**
 * Score de pertinence en PHP, sur un nombre borné de candidats : il départage titre et corps de façon
 * lisible (pertinence = 3 × occurrence dans le titre + occurrences du corps, plafonnées). Le comptage ne
 * lit que le début d'un texte très long.
 *
 * Partagé par la recherche (`Service`) et les documents semblables (`Semblables`) : un seul barème.
 */
final class Pertinence
{
    /** Nombre de caractères du corps réellement comptés. */
    public const LONGUEUR_MAX = 20000;
    private const POIDS_TITRE = 3.0;
    private const PLAFOND_TITRE = 3;
    private const PLAFOND_CORPS = 5;

    /** @param list<string> $termes */
    public static function calculer(string $titre, string $contenu, array $termes): float
    {
        if ($termes === []) {
            return 0.0;
        }
        $titreBas = mb_strtolower($titre);
        $corpsBas = mb_strtolower(mb_substr($contenu, 0, self::LONGUEUR_MAX));
        $score = 0.0;
        foreach ($termes as $terme) {
            $terme = mb_strtolower($terme);
            if ($terme === '') {
                continue;
            }
            $score += self::POIDS_TITRE * min(substr_count($titreBas, $terme), self::PLAFOND_TITRE);
            $score += (float) min(substr_count($corpsBas, $terme), self::PLAFOND_CORPS);
        }

        return $score;
    }
}

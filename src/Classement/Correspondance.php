<?php

declare(strict_types=1);

namespace Ged\Classement;

/**
 * Correspondance automatique d'un texte de pièce avec le motif d'une règle de classement.
 *
 * Écrit à partir du comportement observé de Paperless-ngx 2.20 (`documents.matching.matches`,
 * `_split_match`) : aucun code GPL n'est repris, les algorithmes sont réimplémentés à la manière de
 * GEDv2. Les modes sont ceux du modèle de Paperless :
 *
 * - `MATCH_NONE`    : jamais de correspondance (un motif vide non plus) ;
 * - `MATCH_ALL`     : chaque mot du motif est présent, mot entier (`\b…\b`) ;
 * - `MATCH_ANY`     : au moins un mot du motif est présent, mot entier ;
 * - `MATCH_LITERAL` : le motif entier est présent, encadré de limites de mot ;
 * - `MATCH_REGEX`   : le motif est une expression régulière ; un motif invalide ne correspond jamais ;
 * - `MATCH_FUZZY`   : similarité partielle entre le motif et la meilleure fenêtre du texte ;
 * - `MATCH_AUTO`    : laissé à l'apprentissage, donc sans correspondance textuelle ici.
 *
 * Les mots peuvent être groupés par des guillemets (`"lazy  dogs"`) : les espaces internes valent
 * alors `\s+`, donc plusieurs espaces sont tolérés. La sensibilité à la casse est un paramètre :
 * `$insensible = true` active le mode insensible de Paperless (`is_insensitive`).
 */
final class Correspondance
{
    public const AUCUN = 'MATCH_NONE';
    public const TOUS = 'MATCH_ALL';
    public const UN = 'MATCH_ANY';
    public const LITTERAL = 'MATCH_LITERAL';
    public const REGEX = 'MATCH_REGEX';
    public const FLOU = 'MATCH_FUZZY';
    public const AUTO = 'MATCH_AUTO';

    /** Seuil du mode flou, aligné sur rapidfuzz `score_cutoff=90` utilisé par Paperless. */
    public const SEUIL_FLOU = 90.0;

    /**
     * Le motif correspond-il au texte, selon l'algorithme demandé ?
     *
     * Un motif vide (ou blanc) ne correspond jamais, quel que soit l'algorithme, comme Paperless.
     * Un algorithme inconnu, `MATCH_NONE` ou `MATCH_AUTO` ne correspond jamais : GEDv2 ne lève pas
     * d'erreur de classement pour un mode non textuel.
     */
    public static function correspond(
        string $algorithme,
        string $motif,
        string $texte,
        bool $insensible = false,
    ): bool {
        if (trim($motif) === '') {
            return false;
        }
        $drapeaux = $insensible ? 'iu' : 'u';

        return match ($algorithme) {
            self::TOUS => self::tousLesMots($motif, $texte, $drapeaux),
            self::UN => self::unMot($motif, $texte, $drapeaux),
            self::REGEX => self::expressionReguliere($motif, $texte, $drapeaux),
            default => false,
        };
    }

    /** Chaque mot du motif doit se lire comme un mot entier du texte. */
    private static function tousLesMots(string $motif, string $texte, string $drapeaux): bool
    {
        $mots = self::mots($motif);
        if ($mots === []) {
            return false;
        }
        foreach ($mots as $mot) {
            if (preg_match('/\b' . $mot . '\b/' . $drapeaux, $texte) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** Au moins un mot du motif se lit comme un mot entier du texte. */
    private static function unMot(string $motif, string $texte, string $drapeaux): bool
    {
        foreach (self::mots($motif) as $mot) {
            if (preg_match('/\b' . $mot . '\b/' . $drapeaux, $texte) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Le motif est une expression régulière ; un motif invalide ou non compilable ne correspond pas. */
    private static function expressionReguliere(string $motif, string $texte, string $drapeaux): bool
    {
        // `@` : preg_match émet un avertissement sur un motif invalide, que l'on traite explicitement
        // comme une non-correspondance (comportement de safe_regex_search). Rien n'est avalé en silence :
        // le retour `false` est testé et mène au refus.
        $resultat = @preg_match('~' . str_replace('~', '\\~', $motif) . '~' . $drapeaux, $texte);

        return $resultat === 1;
    }

    /**
     * Découpe le motif en mots (`"groupe entre guillemets"` ou jeton), normalise les espaces internes
     * et échappe chaque mot. Un espace interne devient `\s+` : « lazy  dogs » tolère plusieurs espaces.
     *
     * @return list<string>
     */
    private static function mots(string $motif): array
    {
        if (preg_match_all('/"([^"]+)"|(\S+)/u', $motif, $trouves, PREG_SET_ORDER) === false) {
            return [];
        }
        $mots = [];
        foreach ($trouves as $trouve) {
            $brut = ($trouve[1] ?? '') !== '' ? (string) $trouve[1] : (string) ($trouve[2] ?? '');
            $normalise = trim((string) preg_replace('/\s+/u', ' ', $brut));
            if ($normalise === '') {
                continue;
            }
            $mots[] = implode('\s+', array_map(
                static fn (string $partie): string => preg_quote($partie, '/'),
                explode(' ', $normalise),
            ));
        }

        return $mots;
    }
}

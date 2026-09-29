<?php

declare(strict_types=1);

namespace Ged\Recherche;

/**
 * Extraits surlignés : découpe un texte en segments marqués ou non, pour que le gabarit se contente
 * d'afficher `<mark>` — aucune donnée ne passe par du HTML brut (donc aucune injection possible).
 */
final class Extraits
{
    /** Longueur de fenêtre autour de la première occurrence : assez pour lire, borné pour rester rapide. */
    private const AVANT = 90;
    private const APRES = 130;

    /**
     * Découpe `$texte` en segments [texte, trouvé]. Les termes sont comparés sans tenir compte de la casse.
     *
     * @param list<string> $termes
     * @return list<array{t:string, hit:bool}>
     */
    public static function segments(string $texte, array $termes): array
    {
        $termes = array_values(array_filter($termes, static fn (string $t): bool => $t !== ''));
        if ($texte === '' || $termes === []) {
            return [['t' => $texte, 'hit' => false]];
        }
        $motif = self::alternance($termes);
        $parts = preg_split('/(?i)(' . $motif . ')/u', $texte, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$texte];
        $connus = array_map(static fn (string $t): string => mb_strtolower($t), $termes);

        return array_map(static fn (string $p): array => [
            't' => $p,
            'hit' => in_array(mb_strtolower($p), $connus, true),
        ], $parts);
    }

    /**
     * Jusqu'à `$max` fenêtres de contexte autour des termes trouvés, chacune découpée en segments.
     *
     * @param list<string> $termes
     * @return list<list<array{t:string, hit:bool}>>
     */
    public static function extraire(string $contenu, array $termes, int $max = 3): array
    {
        if ($contenu === '' || $termes === []) {
            return [];
        }
        $extraits = [];
        $vues = [];
        $longueur = mb_strlen($contenu);
        foreach ($termes as $terme) {
            if (count($extraits) >= $max) {
                break;
            }
            $position = mb_stripos($contenu, $terme);
            if ($position === false) {
                continue;
            }
            $debut = max(0, $position - self::AVANT);
            $fenetre = intdiv($debut, 60);
            if (isset($vues[$fenetre])) {
                continue;   // deux termes proches ne font pas deux fois le même extrait
            }
            $vues[$fenetre] = true;
            $fin = min($longueur, $position + mb_strlen($terme) + self::APRES);
            $brut = trim(preg_replace('/\s+/u', ' ', mb_substr($contenu, $debut, $fin - $debut)) ?? '');
            if ($debut > 0) {
                $brut = '… ' . $brut;
            }
            if ($fin < $longueur) {
                $brut .= ' …';
            }
            $extraits[] = self::segments($brut, $termes);
        }

        return $extraits;
    }

    /** @param list<string> $termes */
    private static function alternance(array $termes): string
    {
        $mots = array_map(static fn (string $t): string => preg_quote($t, '/'), $termes);
        usort($mots, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return implode('|', $mots);
    }
}

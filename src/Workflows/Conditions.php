<?php

declare(strict_types=1);

namespace Ged\Workflows;

/**
 * Évaluation des conditions d'une règle contre une fiche de document. Fonction pure : aucune écriture,
 * donc l'essai à blanc et l'exécution réelle partagent exactement le même verdict.
 *
 * Champs : type, emetteur, titre, montant, date, etiquettes, texte, delai_echeance_jours.
 * Opérateurs : egal, different, contient, superieur, superieur_ou_egal, inferieur, inferieur_ou_egal,
 * dans, non_vide.
 */
final class Conditions
{
    public const CHAMPS = ['type', 'emetteur', 'titre', 'montant', 'date', 'etiquette', 'etiquettes', 'texte', 'delai_echeance_jours'];
    public const OPERATEURS = ['egal', 'different', 'contient', 'superieur', 'superieur_ou_egal', 'inferieur', 'inferieur_ou_egal', 'dans', 'non_vide'];

    /**
     * @param list<array<string, mixed>> $conditions
     * @param array<string, mixed>       $contexte
     */
    public static function satisfaites(array $conditions, array $contexte): bool
    {
        foreach ($conditions as $condition) {
            if (is_array($condition) && !self::satisfait($condition, $contexte)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $condition
     * @param array<string, mixed> $contexte
     */
    public static function satisfait(array $condition, array $contexte): bool
    {
        $champ = (string) ($condition['champ'] ?? '');
        $operateur = (string) ($condition['operateur'] ?? 'egal');
        $attendu = $condition['valeur'] ?? null;
        // « etiquette » et « etiquettes » désignent la même liste, qu'on lise une fiche brute ou un contexte normalisé.
        $valeur = $contexte[$champ] ?? (in_array($champ, ['etiquette', 'etiquettes'], true) ? ($contexte['etiquettes'] ?? null) : null);

        return match ($operateur) {
            'non_vide' => is_array($valeur) ? $valeur !== [] : ($valeur !== null && trim((string) $valeur) !== ''),
            'egal' => self::egal($valeur, $attendu),
            'different' => !self::egal($valeur, $attendu),
            'contient' => $valeur !== null && mb_stripos((string) $valeur, (string) $attendu) !== false,
            'dans' => self::dans($valeur, $attendu, $champ),
            'superieur', 'superieur_ou_egal', 'inferieur', 'inferieur_ou_egal' => self::compare($valeur, $attendu, $operateur),
            default => false,
        };
    }

    /** Étiquette(s) : la comparaison ignore la casse et les espaces de bord. */
    private static function dans(mixed $valeur, mixed $attendu, string $champ): bool
    {
        if (is_array($valeur)) {
            foreach ($valeur as $item) {
                if (self::egal($item, $attendu)) {
                    return true;
                }
            }

            return false;
        }
        if (is_array($attendu)) {
            foreach ($attendu as $item) {
                if (self::egal($valeur, $item)) {
                    return true;
                }
            }

            return false;
        }

        return $champ !== '' && self::egal($valeur, $attendu);
    }

    private static function egal(mixed $gauche, mixed $droite): bool
    {
        if (is_numeric($gauche) && is_numeric($droite)) {
            return abs((float) $gauche - (float) $droite) < 0.000001;
        }
        if ($gauche === null || $droite === null) {
            return $gauche === $droite;
        }

        return self::normaliser((string) $gauche) === self::normaliser((string) $droite);
    }

    private static function compare(mixed $gauche, mixed $attendu, string $operateur): bool
    {
        if (!is_numeric($gauche) || !is_numeric($attendu)) {
            return false;
        }
        $g = (float) $gauche;
        $a = (float) $attendu;

        return match ($operateur) {
            'superieur' => $g > $a,
            'superieur_ou_egal' => $g >= $a,
            'inferieur' => $g < $a,
            'inferieur_ou_egal' => $g <= $a,
            default => false,
        };
    }

    private static function normaliser(string $valeur): string
    {
        return mb_strtolower(trim($valeur));
    }
}

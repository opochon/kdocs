<?php

declare(strict_types=1);

namespace Ged\Recherche;

/**
 * Termes significatifs d'un document, pour en chercher les semblables.
 *
 * On ne peut pas passer le texte entier à `MATCH … AGAINST` : la mode naturel de MyISAM écarte les mots
 * présents dans 50 % des lignes (fatal dans un fonds homogène de factures) et `WITH QUERY EXPANSION`
 * laisse dériver l'expansion à partir d'un top de lignes. On extrait donc les mots porteurs du document
 * (titre pondéré, corps ensuite), et la requête FULLTEXT en mode booléen — sans seuil de 50 % — s'en sert
 * comme d'un OR.
 *
 * Contraintes du serveur lues en tête : MyISAM `ft_min_word_len = 4` (les mots plus courts ne sont pas
 * indexés) et liste de mots vides intégrée ; on filtre donc localement sur la longueur minimale et une
 * poignée de mots vides courants, sans jamais dépendre d'eux pour la correction.
 */
final class TermesSemblables
{
    /** `ft_min_word_len` du FULLTEXT MyISAM : en deçà, le mot n'est pas indexé. */
    public const LONGUEUR_MIN = 4;
    /** Nombre de termes gardés : assez pour cerner le document, borné pour rester rapide. */
    private const MAX_TERMES = 24;
    /** Poids d'un mot du titre par rapport à un mot du corps. */
    private const POIDS_TITRE = 3;
    /** Mots vides courants : évite de noyer la requête ; MySQL retire aussi les siens. */
    private const MOTS_VIDES = [
        'alors' => true, 'ainsi' => true, 'aussi' => true, 'autre' => true, 'avant' => true, 'avec' => true,
        'avoir' => true, 'cette' => true, 'comme' => true, 'dans' => true, 'depuis' => true, 'donc' => true,
        'dont' => true, 'elle' => true, 'elles' => true, 'entre' => true, 'était' => true, 'être' => true,
        'faire' => true, 'fait' => true, 'leur' => true, 'leurs' => true, 'mais' => true, 'même' => true,
        'nous' => true, 'parce' => true, 'pour' => true, 'plus' => true, 'quand' => true, 'quel' => true,
        'quelle' => true, 'sans' => true, 'selon' => true, 'sera' => true, 'sont' => true, 'sous' => true,
        'sur' => true, 'tout' => true, 'tous' => true, 'toute' => true, 'très' => true, 'vous' => true,
        'the' => true, 'and' => true, 'that' => true, 'with' => true, 'this' => true, 'from' => true,
        'have' => true, 'will' => true, 'your' => true,
    ];

    /**
     * Termes significatifs, du plus fort au plus faible : un mot du titre pèse trois mots du corps, puis
     * la longueur départage (un mot long est plus rare, donc plus discriminant).
     *
     * @return list<string>
     */
    public static function depuis(string $titre, string $contenu): array
    {
        $poids = [];
        self::compter(mb_strtolower($titre), self::POIDS_TITRE, $poids);
        self::compter(mb_strtolower($contenu), 1, $poids);

        // Une clé numérique (« 1001 ») revient en entier : on force la liste en chaînes, contrat de l'API.
        $mots = array_map(strval(...), array_keys($poids));
        usort($mots, static function (string $a, string $b) use ($poids): int {
            return [$poids[$b] ?? 0, mb_strlen($b), $a] <=> [$poids[$a] ?? 0, mb_strlen($a), $b];
        });

        return array_slice($mots, 0, self::MAX_TERMES);
    }

    /**
     * Expression FULLTEXT booléenne : chaque terme est optionnel (`*` = préfixe), donc un document ressort
     * dès qu'il partage au moins un terme ; le classement les ordonne ensuite par score.
     *
     * @param list<string> $termes
     */
    public static function expression(array $termes): string
    {
        return implode(' ', array_map(static fn (string $terme): string => $terme . '*', $termes));
    }

    /**
     * @param array<string, int> $poids
     */
    private static function compter(string $texte, int $poidsTerme, array &$poids): void
    {
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $texte, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $mot) {
            if (mb_strlen($mot) < self::LONGUEUR_MIN || isset(self::MOTS_VIDES[$mot])) {
                continue;
            }
            $poids[$mot] = ($poids[$mot] ?? 0) + $poidsTerme;
        }
    }
}

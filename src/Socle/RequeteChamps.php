<?php

declare(strict_types=1);

namespace Ged\Socle;

use RuntimeException;

/**
 * Requête JSON sur les champs personnalisés (modèle Paperless `custom_field_query`) : langage
 * d'expressions `[champ, opérateur, valeur]` et logiques `["AND"|"OR"|"NOT", [expressions]]`.
 * Équivalent fonctionnel de `CustomFieldQueryParser` : GEDv2 le traduit en clauses SQL paramétrées
 * contre `documents.meta.champs` (les valeurs vivent dans le `.meta.json`, jamais dans une table).
 *
 * Sûreté : le nom du champ est validé (`[A-Za-z0-9_]`), les chemins JSON passent en marqueurs `?`,
 * les valeurs aussi. Bornes identiques à Paperless (profondeur ≤ 10, atomes ≤ 20). Toute expression
 * invalide est refusée d'un message nommé : un filtre faux n'est jamais silencieusement ignoré.
 */
final class RequeteChamps
{
    private const PROFONDEUR_MAX = 10;
    private const ATOMES_MAX = 20;

    /**
     * Opérateurs d'atome admis (ceux des tests Paperless test_api_filter_by_custom_fields.py).
     *
     * @return list<string>
     */
    private static function operateurs(): array
    {
        return ['exact', 'in', 'isnull', 'exists', 'icontains', 'istartswith',
            'iendswith', 'gt', 'gte', 'lt', 'lte', 'range'];
    }

    /**
     * @return array{0:string, 1:list<mixed>} clause SQL et ses marqueurs
     * @throws RuntimeException expression invalide (refus nommé, jamais un filtre ignoré)
     */
    public static function clauses(string $json, string $alias = 'd'): array
    {
        $brut = trim($json);
        if ($brut === '') {
            throw new RuntimeException('custom_field_query : expression vide');
        }
        $expression = json_decode($brut, true);
        if (!is_array($expression)) {
            throw new RuntimeException('custom_field_query : JSON invalide');
        }
        $atomes = 0;
        [$sql, $params] = self::compiler($expression, $alias, 0, $atomes);

        return ['(' . $sql . ')', $params];
    }

    /**
     * @param array<mixed> $expression
     * @return array{0:string, 1:list<mixed>}
     */
    private static function compiler(array $expression, string $alias, int $profondeur, int &$atomes): array
    {
        if ($profondeur > self::PROFONDEUR_MAX) {
            throw new RuntimeException('custom_field_query : expression trop profonde (maximum ' . self::PROFONDEUR_MAX . ')');
        }
        $cle = $expression[0] ?? null;
        if (is_string($cle) && in_array($cle, ['AND', 'OR', 'NOT'], true)) {
            return self::compilerLogique($cle, $expression, $alias, $profondeur, $atomes);
        }

        return self::compilerAtome($expression, $alias, $atomes);
    }

    /**
     * @param array<mixed> $expression
     * @return array{0:string, 1:list<mixed>}
     */
    private static function compilerLogique(string $operateur, array $expression, string $alias, int $profondeur, int &$atomes): array
    {
        $enfants = $expression[1] ?? null;
        if (!is_array($enfants) || $enfants === [] || !array_is_list($enfants)) {
            throw new RuntimeException("custom_field_query : « $operateur » attend une liste d'expressions");
        }
        $morceaux = [];
        $params = [];
        foreach ($enfants as $enfant) {
            if (!is_array($enfant)) {
                throw new RuntimeException("custom_field_query : « $operateur » attend des expressions");
            }
            [$sql, $p] = self::compiler($enfant, $alias, $profondeur + 1, $atomes);
            $morceaux[] = $sql;
            $params = [...$params, ...$p];
        }
        if ($operateur === 'NOT') {
            return ['NOT (' . implode(' AND ', $morceaux) . ')', $params];
        }

        return ['(' . implode(' ' . $operateur . ' ', $morceaux) . ')', $params];
    }

    /**
     * @param array<mixed> $expression
     * @return array{0:string, 1:list<mixed>}
     */
    private static function compilerAtome(array $expression, string $alias, int &$atomes): array
    {
        if (count($expression) !== 3) {
            throw new RuntimeException('custom_field_query : une expression attend [champ, opérateur, valeur]');
        }
        $champ = $expression[0];
        $operateur = $expression[1];
        $valeur = $expression[2];
        if (!is_string($champ) || preg_match('/^[A-Za-z0-9_]+$/', $champ) !== 1) {
            throw new RuntimeException('custom_field_query : nom de champ invalide');
        }
        if (!is_string($operateur) || !in_array($operateur, self::operateurs(), true)) {
            throw new RuntimeException("custom_field_query : opérateur invalide « " . (is_scalar($operateur) ? (string) $operateur : '?' ) . ' »');
        }
        ++$atomes;
        if ($atomes > self::ATOMES_MAX) {
            throw new RuntimeException('custom_field_query : trop d’atomes (maximum ' . self::ATOMES_MAX . ')');
        }

        $chemin = '$.champs.' . $champ;
        if ($operateur === 'exists') {
            if (!is_bool($valeur)) {
                throw new RuntimeException('custom_field_query : « exists » attend un booléen');
            }
            $clause = 'JSON_CONTAINS_PATH(' . $alias . '.meta, ?, ?)';
            $param = $valeur ? 'one' : 'all';

            return [$valeur ? $clause : 'NOT ' . $clause, [$param, $chemin]];
        }
        if ($operateur === 'isnull') {
            if (!is_bool($valeur)) {
                throw new RuntimeException('custom_field_query : « isnull » attend un booléen');
            }
            $extrait = 'JSON_UNQUOTE(JSON_EXTRACT(' . $alias . '.meta, ?))';

            return [$valeur ? $extrait . ' IS NULL' : $extrait . ' IS NOT NULL', [$chemin]];
        }

        $texte = 'JSON_UNQUOTE(JSON_EXTRACT(' . $alias . '.meta, ?))';
        switch ($operateur) {
            case 'exact':
                return [$texte . ' = ?', [$chemin, self::scalaire($valeur)]];
            case 'in':
                if (!is_array($valeur) || $valeur === []) {
                    throw new RuntimeException('custom_field_query : « in » attend une liste non vide');
                }
                $valeurs = array_map([self::class, 'scalaire'], array_values($valeur));

                return [$texte . ' IN (' . implode(', ', array_fill(0, count($valeurs), '?')) . ')', [$chemin, ...$valeurs]];
            case 'icontains':
                return [$texte . ' LIKE ?', [$chemin, '%' . self::echapper((string) $valeur) . '%']];
            case 'istartswith':
                return [$texte . ' LIKE ?', [$chemin, self::echapper((string) $valeur) . '%']];
            case 'iendswith':
                return [$texte . ' LIKE ?', [$chemin, '%' . self::echapper((string) $valeur)]];
            case 'gt':
            case 'gte':
            case 'lt':
            case 'lte':
                $symbole = ['gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$operateur];

                return ['CAST(' . $texte . ' AS DECIMAL(20,6)) ' . $symbole . ' ?', [$chemin, self::numerique($valeur)]];
            case 'range':
                if (!is_array($valeur) || count($valeur) !== 2) {
                    throw new RuntimeException('custom_field_query : « range » attend [min, max]');
                }

                return ['CAST(' . $texte . ' AS DECIMAL(20,6)) BETWEEN ? AND ?',
                    [$chemin, self::numerique($valeur[0]), self::numerique($valeur[1])]];
        }

        throw new RuntimeException("custom_field_query : opérateur non porté « $operateur »");
    }

    private static function scalaire(mixed $valeur): string
    {
        return is_scalar($valeur) ? (string) $valeur : '';
    }

    private static function numerique(mixed $valeur): float
    {
        if (!is_numeric($valeur)) {
            throw new RuntimeException('custom_field_query : une comparaison numérique attend un nombre');
        }

        return (float) $valeur;
    }

    private static function echapper(string $motif): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $motif);
    }
}

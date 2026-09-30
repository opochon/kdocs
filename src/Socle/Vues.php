<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Config;
use RuntimeException;

/**
 * Vues enregistrées (V2-04) : des FILTRES TYPÉS COMPILÉS (type, émetteur, période, étiquette, champ),
 * jamais un déplacement de fichier — la vue lit le fonds et ne retient que ce qui la satisfait.
 * La compilation valide et normalise les filtres à l'enregistrement (opérateur cohérent avec la
 * nature du champ, dates réelles, valeurs typées) : une vue enregistrée est toujours évaluable.
 *
 * Elles vivent dans le fonds (`.ged/vues.json`, via Registre), pas en base : le plafond de tables
 * de l'installateur (20, oracle V2-01) est déjà plein, et une vue fait partie de la configuration
 * du fonds — elle survit à toute reconstruction (F12) et suit un déménagement du dossier.
 */
final class Vues
{
    /** Registre du fonds qui porte les vues enregistrées. */
    private const FICHIER = 'vues';

    private readonly Registre $registre;
    private readonly Champs $champs;

    public function __construct(Config $config)
    {
        $this->registre = new Registre($config);
        $this->champs = new Champs($config);
    }

    /**
     * Compile des filtres bruts (requête, formulaire) en filtres typés normalisés. Les champs vides
     * sont ignorés ; toute incohérence est une erreur nommée, pas un filtre silencieusement lâche.
     *
     * @param array<string, mixed> $brut
     * @return array{type:?string, emetteur:?string, de:?string, a:?string, etiquettes:list<string>,
     *               champ:array{cle:string, op:string, valeur:mixed}|null}
     */
    public function compiler(array $brut): array
    {
        $filtres = ['type' => null, 'emetteur' => null, 'de' => null, 'a' => null, 'etiquettes' => [], 'champ' => null];

        $type = trim((string) ($brut['type'] ?? ''));
        $filtres['type'] = $type !== '' ? $type : null;

        $emetteur = trim((string) ($brut['emetteur'] ?? ''));
        $filtres['emetteur'] = $emetteur !== '' ? $emetteur : null;

        foreach (['de', 'a'] as $borne) {
            $valeur = trim((string) ($brut[$borne] ?? ''));
            if ($valeur === '') {
                continue;
            }
            if (!self::estDate($valeur)) {
                throw new RuntimeException("filtre période : « $valeur » n’est pas une date AAAA-MM-JJ valide (borne $borne)");
            }
            $filtres[$borne] = $valeur;
        }

        foreach (isset($brut['etiquette']) ? (array) $brut['etiquette'] : [] as $etiquette) {
            $etiquette = trim((string) $etiquette);
            if ($etiquette !== '' && !in_array($etiquette, $filtres['etiquettes'], true)) {
                $filtres['etiquettes'][] = $etiquette;
            }
        }

        $cle = trim((string) ($brut['champ_cle'] ?? ''));
        $op = trim((string) ($brut['champ_op'] ?? ''));
        $valeur = trim((string) ($brut['champ_valeur'] ?? ''));
        if ($cle === '' && $op === '' && $valeur === '') {
            return $filtres;
        }
        if ($cle === '') {
            throw new RuntimeException('filtre champ : la clé du champ manque');
        }

        // La clé doit exister chez au moins un type, et l'opérateur convenir à au moins une de ses natures.
        $natures = [];
        foreach ($this->champs->parCle($cle) as $def) {
            $natures[$def['nature']] = true;
        }
        if ($natures === []) {
            throw new RuntimeException("filtre champ : aucun champ « $cle » dans la nomenclature");
        }
        $opsValides = [];
        foreach (array_keys($natures) as $nature) {
            $opsValides = [...$opsValides, ...array_column(Champs::operateurs()[$nature] ?? [], 'v')];
        }
        if (!in_array($op, $opsValides, true)) {
            throw new RuntimeException("filtre champ : l’opérateur « $op » ne convient à aucune nature du champ « $cle »");
        }

        $filtres['champ'] = ['cle' => $cle, 'op' => $op, 'valeur' => $this->typerValeur($op, $valeur)];

        return $filtres;
    }

    /**
     * Enregistre une vue : les filtres sont compilés ici, une vue stockée est donc toujours valide.
     * Rend l'identifiant de la vue.
     *
     * @param array<string, mixed> $brut
     */
    public function enregistrer(string $nom, array $brut, string $auteur): int
    {
        $nom = trim($nom);
        if ($nom === '' || mb_strlen($nom) > 100) {
            throw new RuntimeException('vue : nom vide ou trop long (100 caractères au plus)');
        }
        $filtres = $this->compiler($brut);
        foreach ($this->lister() as $vue) {
            if (mb_strtolower($vue['nom']) === mb_strtolower($nom)) {
                throw new RuntimeException("vue : « $nom » existe déjà");
            }
        }

        $id = 0;
        $this->registre->modifier(self::FICHIER, static function (array $etat) use ($nom, $filtres, $auteur, &$id): array {
            // Id attribué SOUS VERROU (l'équivalent déterministe de l'auto-incrément, sans table) ;
            // un homonyme posé entre-temps par un autre administrateur reste refusé.
            $id = self::prochainId($etat);
            $etat[] = ['id' => $id, 'nom' => $nom, 'filtres' => $filtres, 'auteur' => $auteur];

            return $etat;
        });

        return $id;
    }

    /** @return array{id:int, nom:string, filtres:array<string, mixed>, auteur:string}|null */
    public function lire(int $id): ?array
    {
        foreach ($this->lister() as $vue) {
            if ($vue['id'] === $id) {
                return $vue;
            }
        }

        return null;
    }

    /** La vue désignée par le slug de son nom (liens du gabarit), ou null. */
    public function parSlug(string $slug): ?array
    {
        foreach ($this->lister() as $vue) {
            if (Fichiers::slug($vue['nom']) === $slug) {
                return $vue;
            }
        }

        return null;
    }

    /** @return list<array{id:int, nom:string, filtres:array<string, mixed>, auteur:string}> */
    public function lister(): array
    {
        $vues = [];
        foreach ($this->registre->lire(self::FICHIER) as $v) {
            $v = (array) $v;
            $filtres = (array) ($v['filtres'] ?? []);
            $filtres += ['type' => null, 'emetteur' => null, 'de' => null, 'a' => null, 'etiquettes' => [], 'champ' => null];
            $vues[] = ['id' => (int) $v['id'], 'nom' => (string) $v['nom'], 'filtres' => $filtres, 'auteur' => (string) ($v['auteur'] ?? '')];
        }

        return $vues;
    }

    /**
     * Applique des filtres compilés à une liste de documents du fonds (forme des entrées de Fonds::tous()).
     * Pure : aucune base, aucun fichier — une vue ne déplace rien, elle ne retient que ce qui la satisfait.
     *
     * @param list<array<string, mixed>> $docs
     * @param array<string, mixed> $filtres
     * @return list<array<string, mixed>>
     */
    public static function filtrerDocs(array $docs, array $filtres): array
    {
        return array_values(array_filter($docs, static fn (array $d): bool => self::satisfaitTout($d, $filtres)));
    }

    private static function satisfaitTout(array $d, array $filtres): bool
    {
        if (($filtres['type'] ?? null) !== null && (string) ($d['type'] ?? '') !== (string) $filtres['type']) {
            return false;
        }
        if (($filtres['emetteur'] ?? null) !== null && (string) ($d['emetteur'] ?? '') !== (string) $filtres['emetteur']) {
            return false;
        }
        $date = is_string($d['date'] ?? null) ? $d['date'] : '';
        if (($filtres['de'] ?? null) !== null && ($date === '' || strcmp($date, (string) $filtres['de']) < 0)) {
            return false;
        }
        if (($filtres['a'] ?? null) !== null && ($date === '' || strcmp($date, (string) $filtres['a']) > 0)) {
            return false;
        }
        foreach ((array) ($filtres['etiquettes'] ?? []) as $etiquette) {
            if (!in_array($etiquette, (array) ($d['etiquettes'] ?? []), true)) {
                return false;
            }
        }

        return ($filtres['champ'] ?? null) === null || self::satisfaitChamp($d, $filtres['champ']);
    }

    /**
     * Le document satisfait-il le filtre de champ ? La comparaison se fait sur la forme de la valeur :
     * une date répond aux opérateurs de date, un nombre aux bornes — sans relire la définition (une
     * même clé peut vivre sur plusieurs types). Sans valeur posée, le document ne satisfait pas.
     *
     * @param array<string, mixed> $d
     * @param array{cle:string, op:string, valeur:mixed} $champ
     */
    private static function satisfaitChamp(array $d, array $champ): bool
    {
        $poses = isset($d['champs']) && is_array($d['champs']) ? $d['champs'] : [];
        $valeur = $poses[$champ['cle']] ?? null;
        if ($valeur === null) {
            return false;
        }
        [$op, $attendu] = [(string) $champ['op'], $champ['valeur']];

        if (in_array($op, ['avant_jours', 'avant', 'apres'], true)) {
            if (!is_string($valeur) || !self::estDate($valeur)) {
                return false;
            }
            $horizon = $op === 'avant_jours' ? date('Y-m-d', time() + ((int) $attendu) * 86400) : (string) $attendu;

            return $op === 'apres' ? strcmp($valeur, $horizon) > 0 : strcmp($valeur, $horizon) <= 0;
        }
        if (in_array($op, ['min', 'max'], true) || ($op === 'egal' && is_float($attendu))) {
            if (!is_numeric($valeur) || !is_numeric($attendu)) {
                return false;
            }
            $v = (float) $valeur;

            return match ($op) {
                'min' => $v >= (float) $attendu, 'max' => $v <= (float) $attendu,
                default => abs($v - (float) $attendu) < 0.005,   // égalité au centime près
            };
        }
        if ($op === 'est' && is_bool($valeur)) {
            return $valeur === (mb_strtolower((string) $attendu) === 'oui');
        }
        if ($op === 'contient') {
            return mb_stripos((string) $valeur, (string) $attendu) !== false;
        }

        return mb_strtolower(trim((string) $valeur)) === mb_strtolower(trim((string) $attendu));
    }

    private static function estDate(string $valeur): bool
    {
        return preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $valeur, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Typage de la valeur d'un opérateur à l'enregistrement : jours en entier, dates vérifiées, nombres en float. */
    private function typerValeur(string $op, string $valeur): mixed
    {
        if ($valeur === '') {
            throw new RuntimeException('filtre champ : la valeur manque');
        }
        if ($op === 'avant_jours') {
            if (!preg_match('#^\d+$#', $valeur) || (int) $valeur < 1) {
                throw new RuntimeException("filtre champ : « $valeur » jours : attendu un nombre entier positif de jours");
            }

            return (int) $valeur;
        }
        if (in_array($op, ['avant', 'apres'], true)) {
            if (!self::estDate($valeur)) {
                throw new RuntimeException("filtre champ : « $valeur » n’est pas une date AAAA-MM-JJ valide");
            }

            return $valeur;
        }
        if (in_array($op, ['min', 'max', 'egal'], true) && is_numeric($valeur)) {
            return (float) $valeur;
        }

        return $valeur;
    }

    /** Le prochain identifiant libre des vues : un de plus que le plus grand posé. */
    private static function prochainId(array $vues): int
    {
        $max = 0;
        foreach ($vues as $v) {
            $max = max($max, (int) (((array) $v)['id'] ?? 0));
        }

        return $max + 1;
    }
}

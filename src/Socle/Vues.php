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

    /** Champs affichables d'une vue (modèle Paperless `SavedView.DisplayFields`, adapté à GEDv2). */
    public const AFFICHAGES = ['title', 'created', 'added', 'asn', 'correspondent', 'document_type', 'tags',
        'storage_path', 'owner', 'notes', 'shared', 'page_count', 'custom_fields', 'title_custom'];

    /** Modes d'affichage (modèle Paperless `SavedView.DisplayMode`). */
    public const MODES = ['table', 'small_cards', 'large_cards'];

    /** Champs de tri admis (le tri vit dans les métadonnées du document, pas dans une table). */
    public const TRIS = ['added', 'created', 'modified', 'title', 'correspondent', 'document_type',
        'storage_path', 'page_count', 'asn'];

    public const TAILLE_PAGE_MIN = 10;
    public const TAILLE_PAGE_MAX = 1000;

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
     * Compile les réglages d'affichage d'une vue (champs affichés, mode, taille de page, barre latérale,
     * tableau de bord, tri). Vide veut dire « réglage laissé au défaut » ; toute valeur hors liste ou hors
     * bornes est une erreur nommée, jamais un réglage silencieusement lâche.
     *
     * @param array<string, mixed> $brut
     * @return array{affichage:?list<string>, mode:?string, taille_page:?int, barre_laterale:bool,
     *               tableau_bord:bool, tri:?string, tri_inverse:bool}
     */
    public function compilerReglages(array $brut): array
    {
        $reglages = ['affichage' => null, 'mode' => null, 'taille_page' => null, 'barre_laterale' => true,
            'tableau_bord' => false, 'tri' => null, 'tri_inverse' => false];

        if (array_key_exists('display_fields', $brut)) {
            $champs = array_values(array_filter(array_map('strval', (array) $brut['display_fields']), static fn (string $c): bool => $c !== ''));
            foreach ($champs as $champ) {
                if (!in_array($champ, self::AFFICHAGES, true)) {
                    throw new RuntimeException("vue : champ affiché inconnu « $champ »");
                }
            }
            $reglages['affichage'] = $champs === [] ? null : $champs;   // liste vide = aucun champ affiché
        }
        if (array_key_exists('display_mode', $brut) && trim((string) $brut['display_mode']) !== '') {
            $mode = trim((string) $brut['display_mode']);
            if (!in_array($mode, self::MODES, true)) {
                throw new RuntimeException("vue : mode d’affichage inconnu « $mode » (" . implode(', ', self::MODES) . ')');
            }
            $reglages['mode'] = $mode;
        }
        if (array_key_exists('page_size', $brut) && $brut['page_size'] !== null && $brut['page_size'] !== '') {
            $taille = (int) $brut['page_size'];
            if ($taille < self::TAILLE_PAGE_MIN || $taille > self::TAILLE_PAGE_MAX) {
                throw new RuntimeException(sprintf('vue : taille de page hors bornes (%d–%d)',
                    self::TAILLE_PAGE_MIN, self::TAILLE_PAGE_MAX));
            }
            $reglages['taille_page'] = $taille;
        }
        foreach (['show_in_sidebar' => 'barre_laterale', 'show_on_dashboard' => 'tableau_bord', 'sort_reverse' => 'tri_inverse'] as $cle => $cible) {
            if (array_key_exists($cle, $brut)) {
                $reglages[$cible] = (bool) $brut[$cle];
            }
        }
        if (array_key_exists('sort_field', $brut)) {
            $tri = trim((string) $brut['sort_field']);
            if (mb_strlen($tri) > 64) {
                throw new RuntimeException('vue : le champ de tri est trop long (64 caractères au plus)');
            }
            $reglages['tri'] = $tri === '' ? null : $tri;
        }

        return $reglages;
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
        $reglages = $this->compilerReglages($brut);
        foreach ($this->lister() as $vue) {
            if (mb_strtolower($vue['nom']) === mb_strtolower($nom)) {
                throw new RuntimeException("vue : « $nom » existe déjà");
            }
        }

        $id = 0;
        $this->registre->modifier(self::FICHIER, static function (array $etat) use ($nom, $filtres, $reglages, $auteur, &$id): array {
            // Id attribué SOUS VERROU (l'équivalent déterministe de l'auto-incrément, sans table) ;
            // un homonyme posé entre-temps par un autre administrateur reste refusé.
            $id = self::prochainId($etat);
            $etat[] = ['id' => $id, 'nom' => $nom, 'filtres' => $filtres, 'reglages' => $reglages, 'auteur' => $auteur];

            return $etat;
        });

        return $id;
    }

    /**
     * Une recherche est rangée dans le même registre de vues que la Bibliothèque, sans perdre son texte.
     *
     * @param array<string, mixed> $brut
     */
    public function enregistrerRecherche(string $nom, array $brut, string $auteur): int
    {
        $nom = trim($nom);
        if ($nom === '' || mb_strlen($nom) > 100) {
            throw new RuntimeException('vue : nom vide ou trop long (100 caractères au plus)');
        }
        $filtres = $this->compiler([
            'type' => $brut['type'] ?? '', 'emetteur' => $brut['emetteur'] ?? '',
            'de' => $brut['date_debut'] ?? '', 'a' => $brut['date_fin'] ?? '',
        ]);
        foreach ($this->lister() as $vue) {
            if (mb_strtolower($vue['nom']) === mb_strtolower($nom)) {
                throw new RuntimeException("vue : « $nom » existe déjà");
            }
        }
        $recherche = ['q' => trim((string) ($brut['q'] ?? '')),
            'montant_min' => trim((string) ($brut['montant_min'] ?? '')),
            'montant_max' => trim((string) ($brut['montant_max'] ?? ''))];
        $id = 0;
        $this->registre->modifier(self::FICHIER, static function (array $etat) use ($nom, $filtres, $auteur, $recherche, &$id): array {
            $id = self::prochainId($etat);
            $etat[] = ['id' => $id, 'nom' => $nom, 'filtres' => $filtres, 'recherche' => $recherche, 'auteur' => $auteur];
            return $etat;
        });

        return $id;
    }

    /** @return array{id:int, nom:string, filtres:array<string, mixed>, auteur:string}|null */
    public function lire(int $id): ?array
    {
        foreach ($this->lister(false) as $vue) {
            if ($vue['id'] === $id) {
                return $vue;
            }
        }

        return null;
    }

    /**
     * La vue désignée par le slug de son nom (liens du gabarit), ou null.
     *
     * @return array<string, mixed>|null
     */
    public function parSlug(string $slug): ?array
    {
        foreach ($this->lister(false) as $vue) {
            if (Fichiers::slug($vue['nom']) === $slug) {
                return $vue;
            }
        }

        return null;
    }

    /**
     * Met une vue en veille (`actif = false`, modèle Paperless : une vue retirée n'est plus proposée dans
     * la barre latérale ni par l'API, elle reste lisible). Idempotent ; aucune donnée n'est supprimée.
     */
    public function definirActif(int $id, bool $actif): void
    {
        if ($this->lire($id) === null) {
            throw new RuntimeException("vue : aucune vue nº $id");
        }
        $this->registre->modifier(self::FICHIER, static function (array $etat) use ($id, $actif): array {
            foreach ($etat as &$vue) {
                /** @var array<string, mixed> $vue */
                $vue = (array) $vue;
                if ((int) ($vue['id'] ?? 0) === $id) {
                    $vue['actif'] = $actif;
                }
            }

            return $etat;
        });
    }

    /** @return list<array{id:int, nom:string, filtres:array<string, mixed>, reglages:array<string, mixed>, auteur:string, actif:bool}> */
    public function lister(bool $actifsSeuls = true): array
    {
        $vues = [];
        foreach ($this->registre->lire(self::FICHIER) as $v) {
            $v = (array) $v;
            $filtres = (array) ($v['filtres'] ?? []);
            $filtres += ['type' => null, 'emetteur' => null, 'de' => null, 'a' => null, 'etiquettes' => [], 'champ' => null];
            $vues[] = ['id' => (int) $v['id'], 'nom' => (string) $v['nom'], 'filtres' => $filtres,
                'reglages' => self::normaliserReglages((array) ($v['reglages'] ?? [])),
                'recherche' => (array) ($v['recherche'] ?? []), 'auteur' => (string) ($v['auteur'] ?? ''),
                'actif' => (bool) ($v['actif'] ?? true)];
        }

        return $actifsSeuls ? array_values(array_filter($vues, static fn (array $v): bool => $v['actif'])) : $vues;
    }

    /**
     * Réglages normalisés d'une vue relue : les clés absentes prennent leur défaut, jamais un trou.
     *
     * @param array<string, mixed> $brut
     * @return array{affichage:?list<string>, mode:?string, taille_page:?int, barre_laterale:bool,
     *               tableau_bord:bool, tri:?string, tri_inverse:bool}
     */
    private static function normaliserReglages(array $brut): array
    {
        $affichage = $brut['affichage'] ?? null;

        return [
            'affichage' => is_array($affichage) ? array_values(array_map('strval', $affichage)) : null,
            'mode' => isset($brut['mode']) && is_string($brut['mode']) ? $brut['mode'] : null,
            'taille_page' => isset($brut['taille_page']) && is_numeric($brut['taille_page']) ? (int) $brut['taille_page'] : null,
            'barre_laterale' => (bool) ($brut['barre_laterale'] ?? true),
            'tableau_bord' => (bool) ($brut['tableau_bord'] ?? false),
            'tri' => isset($brut['tri']) && is_string($brut['tri']) && $brut['tri'] !== '' ? $brut['tri'] : null,
            'tri_inverse' => (bool) ($brut['tri_inverse'] ?? false),
        ];
    }

    /**
     * Change les seuls réglages d'affichage d'une vue existante : les filtres ne bougent pas, le nom non plus.
     *
     * @param array<string, mixed> $brut
     */
    public function modifierReglages(int $id, array $brut, string $auteur): void
    {
        if ($this->lire($id) === null) {
            throw new RuntimeException("vue : aucune vue nº $id");
        }
        $reglages = $this->compilerReglages($brut);
        $this->registre->modifier(self::FICHIER, static function (array $etat) use ($id, $reglages, $auteur): array {
            foreach ($etat as &$v) {
                if ((int) (((array) $v)['id'] ?? 0) === $id) {
                    $v = (array) $v;
                    $v['reglages'] = $reglages;
                    $v['auteur'] = $auteur;
                }
            }

            return $etat;
        });
    }

    /**
     * Les vues montrées sur le tableau de bord, dans l'ordre de création.
     *
     * @return list<array<string, mixed>>
     */
    public function tableauDeBord(): array
    {
        return array_values(array_filter($this->lister(), static fn (array $v): bool => (bool) $v['reglages']['tableau_bord']));
    }

    /**
     * Les vues montrées dans la barre latérale, dans l'ordre de création.
     *
     * @return list<array<string, mixed>>
     */
    public function barreLaterale(): array
    {
        return array_values(array_filter($this->lister(), static fn (array $v): bool => (bool) $v['reglages']['barre_laterale']));
    }

    /**
     * Traduit une vue issue de Recherche vers les paramètres de son unique moteur plein texte.
     * Les vues de Bibliothèque n'ont pas de clé `recherche` : elles continuent donc de n'utiliser
     * que leurs filtres documentaires, sans changer leur comportement.
     *
     * @param array<string, mixed> $vue
     * @return array<string, mixed>
     */
    public function criteresRecherche(array $vue): array
    {
        $filtres = (array) ($vue['filtres'] ?? []);
        $recherche = (array) ($vue['recherche'] ?? []);

        return [
            'q' => (string) ($recherche['q'] ?? ''),
            'type' => (string) ($filtres['type'] ?? ''),
            'emetteur' => (string) ($filtres['emetteur'] ?? ''),
            'date_debut' => (string) ($filtres['de'] ?? ''),
            'date_fin' => (string) ($filtres['a'] ?? ''),
            'montant_min' => (string) ($recherche['montant_min'] ?? ''),
            'montant_max' => (string) ($recherche['montant_max'] ?? ''),
        ];
    }

    /**
     * Le CONTENU d'une vue enregistrée : ses filtres déjà compilés, appliqués à une liste de documents
     * du fonds (forme des entrées de `Fonds::tous()`). M-Files « view contents ». Une vue de Recherche
     * garde EN PLUS un texte : la servir ici laisserait ce critère de côté, elle est donc refusée
     * nommément — son contenu se rejoue par le moteur de Recherche, jamais par un second moteur.
     *
     * @param list<array<string, mixed>> $docs
     * @return list<array<string, mixed>>
     */
    public function contenu(int $id, array $docs): array
    {
        return self::filtrerDocs($docs, $this->filtresDeVue($id));
    }

    /**
     * Le COMPTE du contenu d'une vue enregistrée (M-Files « view contents count »), sans second parcours.
     *
     * @param list<array<string, mixed>> $docs
     */
    public function compter(int $id, array $docs): int
    {
        return count($this->contenu($id, $docs));
    }

    /**
     * Les filtres compilés d'une vue relue ; refuse une vue de Recherche (son texte ne vit pas dans les
     * filtres documentaires) et une vue inconnue, jamais un résultat silencieusement partiel.
     *
     * @return array<string, mixed>
     */
    private function filtresDeVue(int $id): array
    {
        $vue = $this->lire($id);
        if ($vue === null) {
            throw new RuntimeException("vue : aucune vue nº $id");
        }
        if (self::estVueRecherche($vue)) {
            throw new RuntimeException("vue : la vue nº $id est une vue de recherche, son contenu se rejoue par le moteur de recherche");
        }

        return (array) $vue['filtres'];
    }

    /**
     * Une vue de Recherche garde un tableau non vide dans le registre ; une vue documentaire non.
     *
     * @param array<string, mixed> $vue
     */
    private static function estVueRecherche(array $vue): bool
    {
        return isset($vue['recherche']) && is_array($vue['recherche']) && $vue['recherche'] !== [];
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

    /**
     * @param array<string, mixed> $d
     * @param array<string, mixed> $filtres
     */
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

    /**
     * Le prochain identifiant libre des vues : un de plus que le plus grand posé.
     *
     * @param array<mixed> $vues liste de vues (id lu si présent) ; tolère un tableau générique du registre
     */
    private static function prochainId(array $vues): int
    {
        $max = 0;
        foreach ($vues as $v) {
            $max = max($max, (int) (((array) $v)['id'] ?? 0));
        }

        return $max + 1;
    }
}

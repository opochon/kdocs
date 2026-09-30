<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Config;
use RuntimeException;

/**
 * Champs personnalisés typés (V2-04) : texte, nombre, montant CHF, date, liste, oui/non — rattachés
 * à un type de la nomenclature. La DÉFINITION vit dans le fonds (`.ged/nomenclature.json`, un seul
 * document de référence avec les types) ; la VALEUR vit dans le `.meta.json` du document
 * (`champs.<cle>`, le dossier fait foi) et est portée par la colonne `meta` de l'index, donc par
 * toute reconstruction (F12 rend le même hash, champs posés).
 */
final class Champs
{
    /** Natures proposées ; l'étiquette affichée vit dans les gabarits. */
    public const NATURES = ['texte', 'nombre', 'montant', 'date', 'liste', 'booleen'];

    private const PLAFOND_TEXTE = 500;

    private readonly Nomenclature $nomenclature;
    private readonly DepotFichiers $depot;

    public function __construct(Config $config)
    {
        $this->nomenclature = new Nomenclature($config);
        $this->depot = new DepotFichiers($config);
    }

    /**
     * Crée un champ sur un type. La clé (slug du nom) adresse les valeurs dans le `.meta.json` :
     * elle est fixée ici et ne change plus, même si le nom affiché est renommé ensuite.
     *
     * @param list<string> $options choix de la nature liste
     * @return array{id:int, cle:string, nom:string, nature:string, options:list<string>}
     */
    public function ajouter(int $typeId, string $nom, string $nature, array $options = []): array
    {
        $nom = trim($nom);
        if ($nom === '' || mb_strlen($nom) > 100) {
            throw new RuntimeException('champ : nom vide ou trop long (100 caractères au plus)');
        }
        if (!in_array($nature, self::NATURES, true)) {
            throw new RuntimeException("champ : nature inconnue « $nature » (attendues : " . implode(', ', self::NATURES) . ')');
        }
        $options = self::nettoyerOptions($options);
        if ($nature === 'liste' && $options === []) {
            throw new RuntimeException('champ : une liste a besoin d’au moins une option');
        }
        $cle = Fichiers::slug($nom);
        if ($cle === '') {
            throw new RuntimeException('champ : ce nom ne donne aucune clé utilisable');
        }
        foreach ($this->defs() as $d) {
            if ($d['type_id'] === $typeId && $d['cle'] === $cle) {
                throw new RuntimeException("champ : « $nom » existe déjà pour ce type (clé $cle)");
            }
        }

        $id = 0;
        $this->nomenclature->transformer(static function (array $etat) use ($typeId, $cle, $nom, $nature, $options, &$id): array {
            $id = self::prochainId($etat['champs']);
            $etat['champs'][] = ['id' => $id, 'type_id' => $typeId, 'cle' => $cle, 'nom' => $nom,
                'nature' => $nature, 'options' => $options];

            return $etat;
        });
        $def = $this->def($id);
        if ($def === null) {
            throw new RuntimeException('champ : création non relue');
        }

        return $def;
    }

    /**
     * Modifie un champ : nom affiché et options de liste seulement. La nature ne se change pas après
     * création — des valeurs typées vivent déjà dans le dossier — et la clé ne bouge jamais.
     *
     * @param list<string>|null $options
     */
    public function modifier(int $id, ?string $nom = null, ?array $options = null): void
    {
        $def = $this->def($id);
        if ($def === null) {
            throw new RuntimeException("champ : aucun champ nº $id");
        }
        $nom = trim($nom ?? $def['nom']);
        if ($nom === '' || mb_strlen($nom) > 100) {
            throw new RuntimeException('champ : nom vide ou trop long (100 caractères au plus)');
        }
        $options = self::nettoyerOptions($options ?? $def['options']);
        if ($def['nature'] === 'liste' && $options === []) {
            throw new RuntimeException('champ : une liste a besoin d’au moins une option');
        }
        $this->nomenclature->transformer(static function (array $etat) use ($id, $nom, $options): array {
            foreach ($etat['champs'] as &$c) {
                if ((int) $c['id'] === $id) {
                    $c['nom'] = $nom;
                    $c['options'] = $options;
                }
            }

            return $etat;
        });
    }

    /**
     * Définitions des champs d'un type (par son nom, tel qu'il vit dans le `.meta.json` des documents).
     *
     * @return list<array{id:int, type:string, type_id:int, cle:string, nom:string, nature:string, options:list<string>}>
     */
    public function pourType(string $type): array
    {
        return array_values(array_filter($this->defs(), static fn (array $d): bool => $d['type'] === $type));
    }

    /** Toutes les définitions portant cette clé, tous types confondus (une vue filtre par clé). */
    public function parCle(string $cle): array
    {
        return array_values(array_filter($this->defs(), static fn (array $d): bool => $d['cle'] === $cle));
    }

    /** @return list<array{id:int, cle:string, nom:string, nature:string, options:list<string>}> */
    public function tous(): array
    {
        return array_map(static fn (array $d): array => [
            'id' => $d['id'], 'cle' => $d['cle'], 'nom' => $d['nom'], 'nature' => $d['nature'], 'options' => $d['options'],
        ], $this->defs());
    }

    /**
     * Pose (ou efface, valeur vide) la valeur d'un champ sur un document désigné par son chemin dans
     * le fonds : écrit le `.meta.json` à côté du fichier — le dossier fait foi — puis reporte la
     * nouvelle métadonnée dans la ligne d'index, si elle existe. Ne déplace jamais le fichier.
     *
     * @return array<string, mixed> le meta final du document
     */
    public function poserValeur(string $cheminRelatif, string $cle, string $valeurBrute): array
    {
        $absolu = $this->depot->cheminAbsolu($cheminRelatif);
        if (!is_file($absolu)) {
            throw new RuntimeException("champ : document introuvable dans le fonds ($cheminRelatif)");
        }
        $meta = is_file("$absolu.meta.json")
            ? (json_decode((string) file_get_contents("$absolu.meta.json"), true) ?: [])
            : Fichiers::metaMinimale(basename($cheminRelatif));
        $type = isset($meta['type']) && is_string($meta['type']) ? $meta['type'] : '';
        if ($type === '') {
            throw new RuntimeException("champ : le document $cheminRelatif n’a pas de type, aucun champ ne peut s’y poser");
        }

        $def = null;
        foreach ($this->pourType($type) as $d) {
            if ($d['cle'] === $cle) {
                $def = $d;
            }
        }
        if ($def === null) {
            throw new RuntimeException("champ : « $cle » n’est pas un champ du type $type");
        }

        $champs = isset($meta['champs']) && is_array($meta['champs']) ? $meta['champs'] : [];
        if (trim($valeurBrute) === '') {
            unset($champs[$cle]);
        } else {
            $champs[$cle] = $this->valider($def['nature'], $def['options'], $valeurBrute);
        }
        if ($champs === []) {
            unset($meta['champs']);
        } else {
            $meta['champs'] = $champs;
        }

        file_put_contents("$absolu.meta.json", Fichiers::json($meta, true));

        // L'index redit le dossier : la colonne meta (copie reconstructible du .meta.json) suit, tout de suite.
        $this->depot->rapporterMeta($cheminRelatif, $meta);

        return $meta;
    }

    /**
     * Valide une saisie selon la nature du champ et rend la valeur typée telle qu'elle vit dans le
     * `.meta.json` (chaîne, nombre, booléen). Toute entrée récalcitrante est une erreur nommée.
     */
    public function valider(string $nature, array $options, string $brute): mixed
    {
        $brute = trim($brute);
        switch ($nature) {
            case 'texte':
                if (mb_strlen($brute) > self::PLAFOND_TEXTE) {
                    throw new RuntimeException('champ texte : ' . self::PLAFOND_TEXTE . ' caractères au plus');
                }

                return $brute;
            case 'nombre':
            case 'montant':
                if (!is_numeric($brute)) {
                    throw new RuntimeException("champ $nature : « $brute » n’est pas un nombre");
                }

                return $nature === 'montant' ? round((float) $brute, 2) : (float) $brute;
            case 'date':
                if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $brute, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    throw new RuntimeException("champ date : « $brute » n’est pas une date AAAA-MM-JJ valide");
                }

                return $brute;
            case 'liste':
                if (!in_array($brute, $options, true)) {
                    throw new RuntimeException("champ liste : « $brute » n’est pas dans les options (" . implode(', ', $options) . ')');
                }

                return $brute;
            case 'booleen':
                if (!in_array(mb_strtolower($brute), ['oui', 'non'], true)) {
                    throw new RuntimeException("champ oui/non : « $brute » : attendu oui ou non");
                }

                return mb_strtolower($brute) === 'oui';
        }

        throw new RuntimeException("champ : nature inconnue « $nature »");
    }

    /** Opérateurs de comparaison qu'une vue peut appliquer à chaque nature. */
    public static function operateurs(): array
    {
        return [
            'date' => [['v' => 'avant_jours', 'l' => 'dans moins de … jours'], ['v' => 'avant', 'l' => 'avant le'],
                ['v' => 'apres', 'l' => 'après le']],
            'texte' => [['v' => 'contient', 'l' => 'contient'], ['v' => 'egal', 'l' => 'est exactement']],
            'liste' => [['v' => 'egal', 'l' => 'est']],
            'nombre' => [['v' => 'min', 'l' => 'au moins'], ['v' => 'max', 'l' => 'au plus'], ['v' => 'egal', 'l' => 'égal à']],
            'montant' => [['v' => 'min', 'l' => 'au moins (CHF)'], ['v' => 'max', 'l' => 'au plus (CHF)'], ['v' => 'egal', 'l' => 'égal à (CHF)']],
            'booleen' => [['v' => 'est', 'l' => 'est']],
        ];
    }

    /**
     * Toutes les définitions, jointes au nom de leur type. La nomenclature entière tient en une
     * lecture (mise en cache par le Registre) : la jointure se fait en mémoire.
     *
     * @return list<array{id:int, type:string, type_id:int, cle:string, nom:string, nature:string, options:list<string>}>
     */
    private function defs(): array
    {
        $contenu = $this->nomenclature->contenu();
        $noms = [];
        foreach ($contenu['types'] as $t) {
            $noms[$t['id']] = $t['nom'];
        }

        $defs = [];
        foreach ($contenu['champs'] as $c) {
            if (!isset($noms[$c['type_id']])) {
                continue;   // champ orphelin d'un type disparu : invisible, jamais une erreur à l'écran
            }
            $defs[] = $c + ['type' => $noms[$c['type_id']]];
        }

        return $defs;
    }

    /** @return array{id:int, cle:string, nom:string, nature:string, options:list<string>}|null */
    private function def(int $id): ?array
    {
        foreach ($this->tous() as $d) {
            if ($d['id'] === $id) {
                return $d;
            }
        }

        return null;
    }

    /** @param list<string> $options @return list<string> */
    private static function nettoyerOptions(array $options): array
    {
        $nettes = [];
        foreach ($options as $o) {
            $o = trim((string) $o);
            if ($o !== '' && !in_array($o, $nettes, true)) {
                $nettes[] = $o;
            }
        }

        return $nettes;
    }

    /** Le prochain identifiant libre d'une liste : un de plus que le plus grand posé. */
    private static function prochainId(array $entrees): int
    {
        $max = 0;
        foreach ($entrees as $e) {
            $max = max($max, (int) ($e['id'] ?? 0));
        }

        return $max + 1;
    }
}

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
 *
 * Famille M-Files nº 1 (lot ged-mfiles-classes-1) : une propriété porte `obligatoire` (la carte de
 * la classe le redit et sa validation le contrôle) et, pour la nature `liste`, peut RÉFÉRENCER une
 * liste de valeurs nommée (`liste` = clé, cf. {@see Listes}) au lieu de recopier ses options.
 */
final class Champs
{
    /** Natures proposées ; l'étiquette affichée vit dans les gabarits. */
    public const NATURES = ['texte', 'nombre', 'montant', 'date', 'liste', 'booleen', 'url', 'lien'];

    /** Schémas admis pour une valeur `url` : liens web et liens composables (téléphone, courriel). */
    public const SCHEMAS_URL = ['http', 'https', 'ftp', 'ftps', 'tel', 'mailto'];

    private const PLAFOND_TEXTE = 500;

    private readonly Nomenclature $nomenclature;
    private readonly Listes $listes;
    private readonly DepotFichiers $depot;

    public function __construct(Config $config)
    {
        $this->nomenclature = new Nomenclature($config);
        $this->listes = new Listes($config);
        $this->depot = new DepotFichiers($config);
    }

    /**
     * Crée un champ sur un type. La clé (slug du nom) adresse les valeurs dans le `.meta.json` :
     * elle est fixée ici et ne change plus, même si le nom affiché est renommé ensuite.
     *
     * @param list<string> $options choix de la nature liste quand elle est écrite en ligne
     * @param bool $obligatoire propriété obligatoire de la classe (lue par la carte et sa validation)
     * @param ?string $liste clé d'une liste nommée du fonds (nature liste) : les valeurs viennent d'elle
     * @return array{id:int, cle:string, nom:string, nature:string, options:list<string>, obligatoire:bool, liste:?string}
     */
    public function ajouter(int $typeId, string $nom, string $nature, array $options = [], bool $obligatoire = false, ?string $liste = null): array
    {
        $nom = trim($nom);
        if ($nom === '' || mb_strlen($nom) > 100) {
            throw new RuntimeException('champ : nom vide ou trop long (100 caractères au plus)');
        }
        if (!in_array($nature, self::NATURES, true)) {
            throw new RuntimeException("champ : nature inconnue « $nature » (attendues : " . implode(', ', self::NATURES) . ')');
        }
        $options = self::nettoyerOptions($options);
        $liste = self::nettoyerListe($liste);
        $this->exigerCoherenceListe($nature, $options, $liste);
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
        $this->nomenclature->transformer(static function (array $etat) use ($typeId, $cle, $nom, $nature, $options, $obligatoire, $liste, &$id): array {
            $id = self::prochainId($etat['champs']);
            $etat['champs'][] = ['id' => $id, 'type_id' => $typeId, 'cle' => $cle, 'nom' => $nom,
                'nature' => $nature, 'options' => $options, 'obligatoire' => $obligatoire, 'liste' => $liste];

            return $etat;
        });
        $def = $this->def($id);
        if ($def === null) {
            throw new RuntimeException('champ : création non relue');
        }

        return $def;
    }

    /**
     * Modifie un champ : nom affiché, options de liste, obligation et liste nommée référencée seulement.
     * La nature ne se change pas après création — des valeurs typées vivent déjà dans le dossier — et la
     * clé ne bouge jamais.
     *
     * @param list<string>|null $options
     */
    public function modifier(int $id, ?string $nom = null, ?array $options = null, ?bool $obligatoire = null, ?string $liste = null): void
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
        $liste = self::nettoyerListe($liste ?? $def['liste']);
        $this->exigerCoherenceListe($def['nature'], $options, $liste);
        $obligation = $obligatoire ?? $def['obligatoire'];
        $this->nomenclature->transformer(static function (array $etat) use ($id, $nom, $options, $obligation, $liste): array {
            foreach ($etat['champs'] as &$c) {
                if ((int) $c['id'] === $id) {
                    $c['nom'] = $nom;
                    $c['options'] = $options;
                    $c['obligatoire'] = $obligation;
                    $c['liste'] = $liste;
                }
            }

            return $etat;
        });
    }

    /**
     * Définitions des champs d'un type (par son nom, tel qu'il vit dans le `.meta.json` des documents).
     * Les options d'une propriété `liste` qui référence une liste nommée sont RÉSOLUES depuis la liste :
     * la carte suit le vocabulaire du fonds, sans copie en ligne.
     *
     * @return list<array{id:int, type:string, type_id:int, cle:string, nom:string, nature:string, options:list<string>, actif:bool, obligatoire:bool, liste:?string}>
     */
    public function pourType(string $type): array
    {
        $defs = [];
        foreach ($this->defs() as $d) {
            if ($d['type'] === $type) {
                $defs[] = $this->resoudre($d);
            }
        }

        return $defs;
    }

    /**
     * Une définition de champ par son identifiant, ou null.
     *
     * @return array{id:int, type:string, type_id:int, cle:string, nom:string, nature:string, options:list<string>, actif:bool, obligatoire:bool, liste:?string}|null
     */
    public function parId(int $id): ?array
    {
        foreach ($this->defs() as $d) {
            if ($d['id'] === $id) {
                return $this->resoudre($d);
            }
        }

        return null;
    }

    /** Toutes les définitions portant cette clé, tous types confondus (une vue filtre par clé).
     *
     * @return list<array{id:int, type:string, type_id:int, cle:string, nom:string, nature:string, options:list<string>, actif:bool, obligatoire:bool, liste:?string}>
     */
    public function parCle(string $cle): array
    {
        $defs = [];
        foreach ($this->defs() as $d) {
            if ($d['cle'] === $cle) {
                $defs[] = $this->resoudre($d);
            }
        }

        return $defs;
    }

    /** @return list<array{id:int, cle:string, nom:string, nature:string, options:list<string>, obligatoire:bool, liste:?string}> */
    public function tous(): array
    {
        $actifs = [];
        foreach ($this->defs() as $d) {
            if ($d['actif'] === true) {
                $actifs[] = ['id' => $d['id'], 'cle' => $d['cle'], 'nom' => $d['nom'], 'nature' => $d['nature'],
                    'options' => $this->optionsResolues($d), 'obligatoire' => $d['obligatoire'], 'liste' => $d['liste']];
            }
        }

        return $actifs;
    }

    /**
     * Toutes les définitions, actives ET en veille : sert aux lectures d'administration (un champ
     * retiré reste lisible et réactivable), jamais à la proposition d'un formulaire.
     *
     * @return list<array{id:int, cle:string, nom:string, nature:string, options:list<string>, actif:bool, obligatoire:bool, liste:?string}>
     */
    public function tousInclusifs(): array
    {
        return array_map(fn (array $d): array => [
            'id' => $d['id'], 'cle' => $d['cle'], 'nom' => $d['nom'], 'nature' => $d['nature'],
            'options' => $this->optionsResolues($d), 'actif' => $d['actif'],
            'obligatoire' => $d['obligatoire'], 'liste' => $d['liste'],
        ], $this->defs());
    }

    /**
     * Met un champ en veille (`actif = false`, modèle Paperless : un champ retiré n'est plus proposé,
     * ses valeurs restent lisibles dans les `.meta.json`). Idempotent ; aucune donnée n'est supprimée.
     */
    public function definirActif(int $id, bool $actif): void
    {
        if ($this->def($id) === null) {
            throw new RuntimeException("champ : aucun champ nº $id");
        }
        $this->nomenclature->transformer(static function (array $etat) use ($id, $actif): array {
            foreach ($etat['champs'] as &$c) {
                if ((int) $c['id'] === $id) {
                    $c['actif'] = $actif;
                }
            }

            return $etat;
        });
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
        return $this->poserValeurTypée($cheminRelatif, $cle, $valeurBrute);
    }

    /**
     * Variante typée de `poserValeur` : booléen, nombre, montant, liste d'identifiants de liens ou
     * chaîne. Une valeur nulle ou vide efface le champ (le fichier et son historique restent).
     *
     * @return array<string, mixed> le meta final du document
     */
    public function poserValeurTypée(string $cheminRelatif, string $cle, mixed $valeur): array
    {
        $absolu = $this->depot->cheminAbsolu($cheminRelatif);
        if (!is_file($absolu)) {
            throw new RuntimeException("champ : document introuvable dans le fonds ($cheminRelatif)");
        }
        // Sous verrou du dossier, relu frais depuis le disque, écrit atomiquement (Fichiers::modifierMeta).
        $meta = Fichiers::modifierMeta($absolu, function (array $meta) use ($cheminRelatif, $cle, $valeur): array {
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
            if ($valeur === null || (is_string($valeur) && trim($valeur) === '')) {
                unset($champs[$cle]);
            } else {
                $champs[$cle] = $this->validerDefinition($def, $valeur);
            }
            if ($champs === []) {
                unset($meta['champs']);
            } else {
                $meta['champs'] = $champs;
            }

            return $meta;
        });

        // L'index redit le dossier : la colonne meta (copie reconstructible du .meta.json) suit, tout de suite.
        $this->depot->rapporterMeta($cheminRelatif, $meta);

        return $meta;
    }

    /**
     * Valide une saisie selon la nature du champ et rend la valeur typée telle qu'elle vit dans le
     * `.meta.json` (chaîne, nombre, booléen). Toute entrée récalcitrante est une erreur nommée.
     *
     * @param list<string> $options
     */
    public static function valider(string $nature, array $options, string $brute): mixed
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
            case 'url':
                return self::validerUrl($brute);
            case 'lien':
                return self::validerLien($brute);
        }

        throw new RuntimeException("champ : nature inconnue « $nature »");
    }

    /**
     * Valide une valeur d'instance quel que soit son type PHP (chaîne de formulaire, entier, liste d'ids).
     * Le rejeu des vecteurs Paperless passe par ici : une valeur refusée lève une erreur nommée.
     *
     * @param list<string> $options
     */
    public static function validerValeur(string $nature, array $options, mixed $valeur): mixed
    {
        if (is_array($valeur)) {
            if ($nature !== 'lien') {
                throw new RuntimeException("champ $nature : une liste n’est admise que pour un lien de documents");
            }

            return self::validerLienListe($valeur);
        }
        if (is_bool($valeur)) {
            return $valeur;
        }
        if (is_int($valeur) || is_float($valeur)) {
            if ($nature === 'lien') {
                throw new RuntimeException('champ lien : attendu une liste d’identifiants de documents, pas un nombre seul');
            }
            if ($nature === 'montant') {
                return self::validerMontant((string) $valeur);
            }
            if ($nature === 'nombre') {
                return (float) $valeur;
            }
            $valeur = (string) $valeur;
        }

        return self::valider($nature, $options, (string) $valeur);
    }

    /** Un lien non vide est un schéma admis suivi d'un corps non vide (comme Paperless : tel: est accepté). */
    private static function validerUrl(string $brute): string
    {
        if (preg_match('#^([a-z][a-z0-9+.\-]*):(\S.*)$#i', $brute, $m) !== 1
            || !in_array(mb_strtolower($m[1]), self::SCHEMAS_URL, true)) {
            throw new RuntimeException("champ url : « $brute » n’est pas un lien valide (" . implode(', ', self::SCHEMAS_URL) . ')');
        }

        return $brute;
    }

    private static function validerMontant(string $brute): float
    {
        if (!is_numeric($brute)) {
            throw new RuntimeException("champ montant : « $brute » n’est pas un nombre");
        }
        $montant = (float) $brute;
        if (round($montant, 2) !== $montant) {
            throw new RuntimeException("champ montant : « $brute » porte plus de deux décimales");
        }

        return round($montant, 2);
    }

    /**
     * Un lien posé par formulaire : « 12,15 » ou « [12,15] », jamais vide.
     *
     * @return list<int>
     */
    private static function validerLien(string $brute): array
    {
        $brute = trim($brute, " \t\n\r[]");
        if ($brute === '') {
            throw new RuntimeException('champ lien : aucun document lié');
        }
        $ids = array_values(array_filter(array_map('trim', explode(',', $brute)), static fn (string $i): bool => $i !== ''));

        return self::validerLienListe($ids);
    }

    /**
     * @param list<mixed> $valeur
     * @return list<int>
     */
    private static function validerLienListe(array $valeur): array
    {
        if ($valeur === []) {
            throw new RuntimeException('champ lien : aucun document lié');
        }
        $ids = [];
        foreach ($valeur as $brut) {
            if (is_int($brut) && $brut > 0) {
                $ids[] = $brut;
                continue;
            }
            if (is_string($brut) && preg_match('#^\d+$#', trim($brut)) === 1 && (int) $brut > 0) {
                $ids[] = (int) $brut;
                continue;
            }
            throw new RuntimeException('champ lien : chaque identifiant doit être un entier positif');
        }

        return array_values(array_unique($ids));
    }

    /**
     * Opérateurs de comparaison qu'une vue peut appliquer à chaque nature.
     *
     * @return array<string, list<array{v:string, l:string}>>
     */
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
     * @return list<array{id:int, type:string, type_id:int, cle:string, nom:string, nature:string, options:list<string>, actif:bool, obligatoire:bool, liste:?string}>
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

    /** @return array{id:int, cle:string, nom:string, nature:string, options:list<string>, obligatoire:bool, liste:?string}|null */
    private function def(int $id): ?array
    {
        foreach ($this->defs() as $d) {
            if ($d['id'] === $id) {
                return ['id' => $d['id'], 'cle' => $d['cle'], 'nom' => $d['nom'],
                    'nature' => $d['nature'], 'options' => $this->optionsResolues($d),
                    'obligatoire' => $d['obligatoire'], 'liste' => $d['liste']];
            }
        }

        return null;
    }

    /**
     * Une définition jointe à ses options résolues : une liste nommée remplace les options en ligne.
     *
     * @param array<string,mixed> $d
     * @return array<string,mixed>
     */
    private function resoudre(array $d): array
    {
        $d['options'] = $this->optionsResolues($d);

        return $d;
    }

    /**
     * @param array<string,mixed> $d
     * @return list<string>
     */
    private function optionsResolues(array $d): array
    {
        $liste = $d['liste'] ?? null;
        if (is_string($liste) && $liste !== '') {
            return $this->listes->valeursDe($liste);
        }

        return array_values(array_map('strval', (array) ($d['options'] ?? [])));
    }

    /**
     * Valide une valeur pour une définition de champ : une liste nommée exige une valeur ACTIVE de
     * cette liste, les autres natures passent par la validation commune (type PHP quelconque).
     *
     * @param array<string,mixed> $def
     */
    public function validerDefinition(array $def, mixed $valeur): mixed
    {
        $nature = (string) ($def['nature'] ?? '');
        $liste = $def['liste'] ?? null;
        if ($nature === 'liste' && is_string($liste) && $liste !== '') {
            $texte = is_string($valeur) ? trim($valeur) : (string) $valeur;
            if (!in_array($texte, $this->listes->valeursDe($liste), true)) {
                throw new RuntimeException("champ liste : « $texte » n’est pas une valeur active de la liste nommée « $liste »");
            }

            return $texte;
        }

        return self::validerValeur($nature, array_values(array_map('strval', (array) ($def['options'] ?? []))), $valeur);
    }

    private static function nettoyerListe(?string $liste): ?string
    {
        $liste = trim((string) $liste);

        return $liste === '' ? null : $liste;
    }

    /**
     * Une propriété ne peut référencer qu'une liste EXISTANTE, et seulement si elle est de nature
     * liste ; les options en ligne et une liste nommée ne se cumulent pas.
     *
     * @param list<string> $options
     */
    private function exigerCoherenceListe(string $nature, array $options, ?string $liste): void
    {
        if ($liste !== null) {
            if ($nature !== 'liste') {
                throw new RuntimeException("champ : la référence à une liste nommée (« $liste ») n’a de sens que pour la nature liste");
            }
            if ($options !== []) {
                throw new RuntimeException('champ : une liste nommée remplace les options en ligne (laisser les options vides)');
            }
            if ($this->listes->parCle($liste) === null) {
                throw new RuntimeException("champ : liste nommée « $liste » introuvable dans le fonds");
            }

            return;
        }
        if ($nature === 'liste' && $options === []) {
            throw new RuntimeException('champ : une liste a besoin d’au moins une option');
        }
    }

    /**
     * @param list<string> $options
     * @return list<string>
     */
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

    /**
     * Le prochain identifiant libre d'une liste : un de plus que le plus grand posé.
     *
     * @param list<array<string, mixed>> $entrees
     */
    private static function prochainId(array $entrees): int
    {
        $max = 0;
        foreach ($entrees as $e) {
            $max = max($max, (int) ($e['id'] ?? 0));
        }

        return $max + 1;
    }
}

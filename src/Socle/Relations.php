<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Base;
use Ged\Config;
use PDOException;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Relations typées entre objets (famille M-Files nº 2, lot ged-mfiles-relations-1).
 *
 * Les DÉFINITIONS de types de relation vivent dans le fonds (`.ged/relations.json`, via {@see Registre})
 * — configuration portable, versionnable, reconstruisible, jamais en base (le plafond de tables tient) et
 * jamais dans un coffre propriétaire. Les VALEURS vivent par document dans le `.meta.json` à côté du
 * fichier (le dossier fait foi) : un lien est de la métadonnée, lisible sans l'application.
 *
 * Les types publiés par la documentation M-Files sont repris tels quels ({@see self::NATURES}) :
 * Référence, Dépendance, Affectation, Sous-objet. Un lien est DIRECTIONNEL mais navigable dans les
 * deux sens : la fiche d'un document montre ses liens sortants ET les liens dont elle est la cible.
 *
 * Intégrité des références croisées : la cible d'un lien est adressée par son CHEMIN et figée avec son
 * empreinte. Une cible déplacée est retrouvée par son empreinte (nouveau chemin) ; une cible supprimée
 * (corbeille ou disparue) reste TRACABLE (dernier titre et dernière empreinte connus) — jamais un lien
 * cassé en silence. Retirer un lien ne l'efface pas : il est daté et attribué, comme une valeur de liste.
 */
final class Relations
{
    private const FICHIER = 'relations';

    /** Natures admises, héritées de la documentation publique M-Files (types de relation). */
    public const NATURES = ['reference', 'dependance', 'affectation', 'sous-objet'];

    /** Graine livrée : les quatre types de relation publiés par M-Files. */
    public const TYPES_DEFAUT = [
        ['nom' => 'Référence', 'nature' => 'reference'],
        ['nom' => 'Dépendance', 'nature' => 'dependance'],
        ['nom' => 'Affectation', 'nature' => 'affectation'],
        ['nom' => 'Sous-objet', 'nature' => 'sous-objet'],
    ];

    /** Étiquettes lisibles d'un état de cible (aucun état muet à l'écran). */
    public const LIBELLES_ETAT = [
        'vivant' => 'Objet lié',
        'deplace' => 'Objet déplacé',
        'corbeille' => 'Objet à la corbeille',
        'absent' => 'Objet introuvable',
    ];

    private readonly Registre $registre;
    private readonly DepotFichiers $depot;

    /** Cache mémoire des `.meta.json` d'un fonds, vidé à chaque écriture. */
    /** @var array<string, array<string, mixed>>|null */
    private ?array $metas = null;

    public function __construct(private readonly Config $config)
    {
        $this->registre = new Registre($config);
        $this->depot = new DepotFichiers($config);
    }

    /**
     * Pose la graine des types si le fonds n'en a pas : idempotent, rejouable. La transformation relit
     * l'état SOUS VERROU (deux amorçages croisés ne posent la graine qu'une fois).
     */
    public function assurer(): void
    {
        if (($this->registre->lire(self::FICHIER)['types'] ?? []) !== []) {
            return;
        }
        $this->registre->modifier(self::FICHIER, static function (array $etat): array {
            if (($etat['types'] ?? []) !== []) {
                return $etat;   // un concurrent l'a posée entre-temps : elle reste telle quelle
            }
            $types = [];
            foreach (self::TYPES_DEFAUT as $i => $def) {
                $types[] = [
                    'id' => $i + 1,
                    'nom' => $def['nom'],
                    'cle' => Fichiers::slug($def['nom']),
                    'nature' => $def['nature'],
                    'classe_source' => null,
                    'classe_cible' => null,
                    'multiple' => true,
                    'actif' => true,
                ];
            }

            return ['version' => 1, 'types' => $types];
        });
    }

    /**
     * Les définitions de types de relation. Si la graine n'est pas encore posée, la graine est rendue
     * EN MÉMOIRE sans écrire : une lecture ne pose jamais un fichier (GET n'écrit jamais).
     *
     * @return list<array{id:int, nom:string, cle:string, nature:string, classe_source:?string, classe_cible:?string, multiple:bool, actif:bool}>
     */
    public function types(bool $actifsSeuls = true): array
    {
        $types = [];
        foreach ($this->lireTypes() as $type) {
            if ($actifsSeuls && $type['actif'] === false) {
                continue;
            }
            $types[] = $type;
        }

        return $types;
    }

    /**
     * Une définition de type par sa clé (slug), son nom ou son identifiant, ou null.
     *
     * @return array{id:int, nom:string, cle:string, nature:string, classe_source:?string, classe_cible:?string, multiple:bool, actif:bool}|null
     */
    public function typeParCle(string $cle): ?array
    {
        $slug = Fichiers::slug($cle);
        foreach ($this->lireTypes() as $type) {
            if ($type['cle'] === $cle || $type['cle'] === $slug || Fichiers::slug($type['nom']) === $slug
                || (ctype_digit($cle) && $type['id'] === (int) $cle)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Ajoute un type de relation. Refuse un nom vide, trop long, homonyme ou une nature inconnue.
     */
    public function ajouterType(string $nom, string $nature, ?string $classeSource = null, ?string $classeCible = null, bool $multiple = true): int
    {
        $nom = self::validerNom($nom);
        $nature = self::validerNature($nature);
        foreach ($this->lireTypes() as $type) {
            if (mb_strtolower($type['nom']) === mb_strtolower($nom)) {
                throw new RuntimeException("relation : le type « $nom » existe déjà dans le fonds");
            }
        }

        $id = 0;
        $this->registre->modifier(self::FICHIER, static function (array $etat) use ($nom, $nature, $classeSource, $classeCible, $multiple, &$id): array {
            $types = array_values(array_map(static fn (mixed $t): array => (array) $t, (array) ($etat['types'] ?? [])));
            $id = self::prochainId($types);
            $types[] = [
                'id' => $id, 'nom' => $nom, 'cle' => Fichiers::slug($nom), 'nature' => $nature,
                'classe_source' => $classeSource, 'classe_cible' => $classeCible,
                'multiple' => $multiple, 'actif' => true,
            ];
            $etat['version'] = 1;
            $etat['types'] = $types;

            return $etat;
        });

        return $id;
    }

    /** Renomme le libellé d'un type : la clé ne bouge jamais, les liens restent adressés. */
    public function renommerType(int $id, string $nom): void
    {
        $nom = self::validerNom($nom);
        $this->changerType($id, static function (array $type) use ($nom): array {
            $type['nom'] = $nom;

            return $type;
        });
    }

    /** Met un type en veille (ou le réactive) : il n'est plus proposé, les liens existants restent lisibles. */
    public function definirActifType(int $id, bool $actif): void
    {
        $this->changerType($id, static function (array $type) use ($actif): array {
            $type['actif'] = $actif;

            return $type;
        });
    }

    /**
     * Lie deux documents du fonds par un type de relation. La cible est figée par son chemin ET son
     * empreinte. Refuse un auto-lien, un type inconnu ou en veille, une cible absente, un doublon et,
     * quand le type ne le permet pas, un second lien du même type depuis la même source.
     *
     * @return int l'identifiant du lien posé sur le document source
     */
    public function lier(string $source, string $cible, string $cleType, string $auteur = 'systeme'): int
    {
        $this->assurer();
        $source = self::normaliserChemin($source);
        $cible = self::normaliserChemin($cible);
        if ($source === '' || $cible === '' || self::memeChemin($source, $cible)) {
            throw new RuntimeException('relation : deux documents distincts sont requis');
        }
        $type = $this->typeParCle($cleType);
        if ($type === null) {
            throw new RuntimeException("relation : type inconnu « $cleType »");
        }
        if ($type['actif'] === false) {
            throw new RuntimeException("relation : le type « {$type['nom']} » est en veille");
        }
        $absoluSource = $this->depot->cheminAbsolu($source);
        $absoluCible = $this->depot->cheminAbsolu($cible);
        if (!is_file($absoluSource)) {
            throw new RuntimeException("relation : document source introuvable dans le fonds ($source)");
        }
        if (!is_file($absoluCible)) {
            throw new RuntimeException("relation : document cible introuvable dans le fonds ($cible)");
        }
        $this->exigerClasse($type['classe_source'], $this->classeDocument($source), 'source', (string) $type['nom']);
        $this->exigerClasse($type['classe_cible'], $this->classeDocument($cible), 'cible', (string) $type['nom']);

        $meta = $this->lireMeta($source);
        $relations = self::entrees($meta);
        foreach ($relations as $relation) {
            if ($relation['actif'] === true && $relation['type'] === $type['cle'] && self::memeChemin((string) $relation['cible'], $cible)) {
                throw new RuntimeException("relation : le lien « {$type['nom']} » vers ce document existe déjà");
            }
            if ($type['multiple'] === false && $relation['actif'] === true && $relation['type'] === $type['cle']) {
                throw new RuntimeException("relation : le type « {$type['nom']} » n’autorise qu’un seul lien");
            }
        }

        $auteur = trim($auteur) !== '' ? trim($auteur) : 'systeme';
        $id = self::prochainId($relations);
        $relations[] = [
            'id' => $id,
            'type' => $type['cle'],
            'cible' => $cible,
            'titre' => $this->titreDocument($cible),
            'empreinte' => Fichiers::empreinte($absoluCible),
            'cree_le' => date('c'),
            'cree_par' => $auteur,
            'actif' => true,
        ];
        $this->ecrireRelations($source, $meta, $relations);

        return $id;
    }

    /**
     * Retire un lien SANS l'effacer : il est daté et attribué, reste lisible dans le dossier (la trace
     * survit à tout). Le refus est nommé si le lien n'existe pas ou n'est plus actif.
     */
    public function retirer(string $proprietaire, int $relationId, string $auteur = 'systeme'): void
    {
        $proprietaire = self::normaliserChemin($proprietaire);
        $meta = $this->lireMeta($proprietaire);
        $relations = self::entrees($meta);
        $auteur = trim($auteur) !== '' ? trim($auteur) : 'systeme';
        $trouve = false;
        foreach ($relations as &$relation) {
            if ($relation['id'] === $relationId && $relation['actif'] === true) {
                $relation['actif'] = false;
                $relation['retire_le'] = date('c');
                $relation['retire_par'] = $auteur;
                $trouve = true;
            }
        }
        unset($relation);
        if (!$trouve) {
            throw new RuntimeException("relation : aucun lien actif nº $relationId sur ce document");
        }
        $this->ecrireRelations($proprietaire, $meta, $relations);
    }

    /**
     * Détache un sous-objet (équivalent M-Files « demoting objects ») : le lien de composition est
     * retiré, l'enfant devient indépendant. Le geste est tracé. Rend le nombre de liens détachés.
     */
    public function detacher(string $source, string $cible, string $auteur = 'systeme'): int
    {
        $source = self::normaliserChemin($source);
        $cible = self::normaliserChemin($cible);
        $meta = $this->lireMeta($source);
        $relations = self::entrees($meta);
        $auteur = trim($auteur) !== '' ? trim($auteur) : 'systeme';
        $detaches = 0;
        foreach ($relations as &$relation) {
            if ($relation['actif'] === true && $this->estComposition($relation['type']) && self::memeChemin((string) $relation['cible'], $cible)) {
                $relation['actif'] = false;
                $relation['retire_le'] = date('c');
                $relation['retire_par'] = $auteur;
                ++$detaches;
            }
        }
        unset($relation);
        if ($detaches === 0) {
            throw new RuntimeException("relation : ce document n’est pas un sous-objet de la source ($cible)");
        }
        $this->ecrireRelations($source, $meta, $relations);

        return $detaches;
    }

    /**
     * Les relations ACTIVES d'un document, sortantes ET entrantes, chacune résolue (état de la cible,
     * dernier titre et empreinte connus). Une cible déplacée est réécrite vers sa nouvelle place ; une
     * cible supprimée reste listée avec son état. Aucun lien n'est jamais masqué en silence.
     *
     * @return list<array<string, mixed>>
     */
    public function pourDocument(string $chemin): array
    {
        $chemin = self::normaliserChemin($chemin);
        $metas = $this->toutesLesMetas();
        $actives = [];

        foreach (self::entrees($metas[$chemin] ?? []) as $relation) {
            if ($relation['actif'] !== true) {
                continue;
            }
            $resolution = $this->resoudre($relation);
            $actives[] = $this->presenter($relation, 'sortante', $chemin, $resolution);
        }

        foreach ($metas as $proprietaire => $meta) {
            if (self::memeChemin($proprietaire, $chemin)) {
                continue;
            }
            foreach (self::entrees($meta) as $relation) {
                if ($relation['actif'] !== true) {
                    continue;
                }
                $resolution = $this->resoudre($relation);
                if (!self::memeChemin((string) $resolution['chemin'], $chemin)) {
                    continue;
                }
                $actives[] = $this->presenter($relation, 'entrante', $proprietaire, [
                    'etat' => 'vivant',
                    'chemin' => $proprietaire,
                    'titre' => $this->titreDocument($proprietaire),
                    'empreinte' => (string) $relation['empreinte'],
                ]);
            }
        }

        usort($actives, static function (array $a, array $b): int {
            return [$a['sens'], $a['type'], $a['cible']] <=> [$b['sens'], $b['type'], $b['cible']];
        });

        return $actives;
    }

    /** Nombre de relations actives d'un document (les deux sens). */
    public function compter(string $chemin): int
    {
        return count($this->pourDocument($chemin));
    }

    /**
     * Les SOUS-OBJETS d'un document : les cibles de ses liens de composition actifs (M-Files
     * `/subobjects`). Rend une entrée par enfant, résolue.
     *
     * @return list<array<string, mixed>>
     */
    public function enfants(string $chemin): array
    {
        $chemin = self::normaliserChemin($chemin);
        $meta = $this->toutesLesMetas()[$chemin] ?? [];
        $enfants = [];
        foreach (self::entrees($meta) as $relation) {
            if ($relation['actif'] !== true || !$this->estComposition($relation['type'])) {
                continue;
            }
            $resolution = $this->resoudre($relation);
            $enfants[] = [
                'chemin' => (string) $resolution['chemin'],
                'titre' => (string) $resolution['titre'],
                'etat' => (string) $resolution['etat'],
                'relation_id' => $relation['id'],
            ];
        }

        return $enfants;
    }

    /** Nombre de sous-objets d'un document (M-Files `/subobjects/count`). */
    public function compterEnfants(string $chemin): int
    {
        return count($this->enfants($chemin));
    }

    /**
     * Les PARENTS d'un document : les objets dont un lien de composition actif le désigne (l'inverse
     * de `enfants`). Rend une entrée par parent, résolue.
     *
     * @return list<array<string, mixed>>
     */
    public function parents(string $chemin): array
    {
        $chemin = self::normaliserChemin($chemin);
        $parents = [];
        foreach ($this->toutesLesMetas() as $proprietaire => $meta) {
            if (self::memeChemin($proprietaire, $chemin)) {
                continue;
            }
            foreach (self::entrees($meta) as $relation) {
                if ($relation['actif'] !== true || !$this->estComposition($relation['type'])) {
                    continue;
                }
                $resolution = $this->resoudre($relation);
                if (!self::memeChemin((string) $resolution['chemin'], $chemin)) {
                    continue;
                }
                $parents[] = [
                    'chemin' => $proprietaire,
                    'titre' => $this->titreDocument($proprietaire),
                    'etat' => 'vivant',
                    'relation_id' => $relation['id'],
                ];
            }
        }

        return $parents;
    }

    /**
     * La hiérarchie d'un document : le contenant se lit du CHEMIN (le dossier fait foi : le dossier
     * physique contient ses pièces), la composition explicite (`sous-objet`) le COMPLÈTE sans le
     * recopier. Aucun dossier n'est inventé à partir d'une relation.
     *
     * @return array{chemin:string, dossier:string, pieces:list<array{chemin:string, titre:string}>, enfants:list<array<string,mixed>>, parents:list<array<string,mixed>>}
     */
    public function hierarchie(string $chemin): array
    {
        $chemin = self::normaliserChemin($chemin);
        $dossier = dirname($chemin) === '.' ? '' : str_replace('\\', '/', dirname($chemin));
        $absolu = $this->depot->cheminAbsolu($chemin);
        $racine = $this->depot->racine();
        $pieces = [];
        $elements = @scandir(dirname($absolu));
        foreach (is_array($elements) ? $elements : [] as $nom) {
            if (str_starts_with($nom, '.') || str_ends_with($nom, '.meta.json') || Fichiers::estInterne($nom)) {
                continue;
            }
            if (!is_file(dirname($absolu) . '/' . $nom)) {
                continue;
            }
            $relatif = str_replace('\\', '/', substr(dirname($absolu) . '/' . $nom, strlen($racine) + 1));
            if (self::memeChemin($relatif, $chemin)) {
                continue;
            }
            $pieces[] = ['chemin' => $relatif, 'titre' => $this->titreDocument($relatif)];
        }
        usort($pieces, static fn (array $a, array $b): int => $a['chemin'] <=> $b['chemin']);

        return ['chemin' => $chemin, 'dossier' => $dossier, 'pieces' => $pieces,
            'enfants' => $this->enfants($chemin), 'parents' => $this->parents($chemin)];
    }

    /**
     * Résout la cible d'un lien : vivante (fichier à sa place), déplacée (retrouvée par son empreinte),
     * à la corbeille ou introuvable. Dans tous les cas le dernier titre et la dernière empreinte connus
     * sont rendus : une référence vers un objet supprimé reste tracable, jamais un lien cassé muet.
     *
     * @param array<string, mixed> $relation
     * @return array{etat:string, chemin:string, titre:string, empreinte:string}
     */
    public function resoudre(array $relation): array
    {
        $chemin = self::normaliserChemin((string) ($relation['cible'] ?? ''));
        $titre = (string) ($relation['titre'] ?? '');
        $empreinte = (string) ($relation['empreinte'] ?? '');
        if ($chemin !== '' && is_file($this->depot->cheminAbsolu($chemin))) {
            return ['etat' => 'vivant', 'chemin' => $chemin, 'titre' => $this->titreDocument($chemin), 'empreinte' => $empreinte];
        }
        if ($empreinte !== '') {
            $ligne = $this->lignePar('empreinte', $empreinte);
            if ($ligne !== null) {
                return $this->etatDepuisLigne($ligne, $chemin, $titre, $empreinte);
            }
        }
        $ligne = $chemin === '' ? null : $this->lignePar('chemin_relatif', $chemin);
        if ($ligne !== null) {
            return $this->etatDepuisLigne($ligne, $chemin, $titre, $empreinte);
        }

        return ['etat' => 'absent', 'chemin' => $chemin, 'titre' => $titre, 'empreinte' => $empreinte];
    }

    /**
     * Le journal des relations d'un document : chaque lien ajouté ou retiré, daté, trié du plus récent
     * au plus ancien — la traçabilité demandée par la mission.
     *
     * @return list<array{quand:string, quoi:string, relation_id:int}>
     */
    public function journal(string $chemin): array
    {
        $chemin = self::normaliserChemin($chemin);
        $metas = $this->toutesLesMetas();
        $evenements = [];
        foreach ($metas as $proprietaire => $meta) {
            foreach (self::entrees($meta) as $relation) {
                $cible = $this->resoudre($relation);
                $concerne = self::memeChemin($proprietaire, $chemin) || self::memeChemin((string) $cible['chemin'], $chemin);
                if (!$concerne) {
                    continue;
                }
                $type = $this->typeParCle((string) $relation['type']);
                $nomType = $type['nom'] ?? (string) $relation['type'];
                $geste = $relation['actif'] === true ? 'ajoutée' : 'retirée';
                $quand = $relation['actif'] === true ? (string) $relation['cree_le'] : (string) $relation['retire_le'];
                $evenements[] = ['quand' => $quand,
                    'quoi' => "Relation $geste : $nomType — {$this->titreDocument($proprietaire)} → « {$cible['titre']} »",
                    'relation_id' => $relation['id']];
            }
        }
        usort($evenements, static fn (array $a, array $b): int => strcmp($b['quand'], $a['quand']));

        return $evenements;
    }

    /**
     * La définition portable des types de relation (export vers un autre fonds, un autre poste).
     *
     * @return array{version:int, types:list<array<string, mixed>>}
     */
    public function exporter(): array
    {
        return ['version' => 1, 'types' => $this->types(false)];
    }

    /**
     * Importe une définition de types : remplace le fichier du fonds par la définition fournie, validée.
     * Les VALEURS des documents ne sont pas touchées (elles vivent dans les `.meta.json`).
     *
     * @param array<string, mixed> $definition
     */
    public function importer(array $definition): void
    {
        $types = [];
        foreach ((array) ($definition['types'] ?? []) as $brut) {
            $type = (array) $brut;
            $nature = self::validerNature((string) ($type['nature'] ?? ''));
            $nom = self::validerNom((string) ($type['nom'] ?? ''));
            $types[] = [
                'id' => (int) ($type['id'] ?? 0),
                'nom' => $nom,
                'cle' => Fichiers::slug($nom),
                'nature' => $nature,
                'classe_source' => isset($type['classe_source']) && is_string($type['classe_source']) ? $type['classe_source'] : null,
                'classe_cible' => isset($type['classe_cible']) && is_string($type['classe_cible']) ? $type['classe_cible'] : null,
                'multiple' => (bool) ($type['multiple'] ?? true),
                'actif' => (bool) ($type['actif'] ?? true),
            ];
        }
        if ($types === []) {
            throw new RuntimeException('relation : définition sans aucun type');
        }
        $this->registre->modifier(self::FICHIER, static fn (array $etat): array => ['version' => 1, 'types' => $types]);
    }

    // ---------------------------------------------------------------------------------------------
    // Interne
    // ---------------------------------------------------------------------------------------------

    /**
     * @return list<array{id:int, nom:string, cle:string, nature:string, classe_source:?string, classe_cible:?string, multiple:bool, actif:bool}>
     */
    private function lireTypes(): array
    {
        $brut = $this->registre->lire(self::FICHIER);
        $types = [];
        foreach ((array) ($brut['types'] ?? []) as $type) {
            $types[] = self::normaliserType((array) $type);
        }
        if ($types !== []) {
            return $types;
        }
        $defauts = [];
        foreach (self::TYPES_DEFAUT as $i => $def) {
            $defauts[] = self::normaliserType(['id' => $i + 1, 'nom' => $def['nom'], 'cle' => Fichiers::slug($def['nom']),
                'nature' => $def['nature'], 'classe_source' => null, 'classe_cible' => null, 'multiple' => true, 'actif' => true]);
        }

        return $defauts;
    }

    /**
     * @param array<string, mixed> $type
     * @return array{id:int, nom:string, cle:string, nature:string, classe_source:?string, classe_cible:?string, multiple:bool, actif:bool}
     */
    private static function normaliserType(array $type): array
    {
        $nom = (string) ($type['nom'] ?? '');
        $cle = (string) ($type['cle'] ?? '');
        if ($cle === '') {
            $cle = Fichiers::slug($nom);
        }

        return [
            'id' => (int) ($type['id'] ?? 0),
            'nom' => $nom,
            'cle' => $cle,
            'nature' => (string) ($type['nature'] ?? ''),
            'classe_source' => isset($type['classe_source']) && is_string($type['classe_source']) ? $type['classe_source'] : null,
            'classe_cible' => isset($type['classe_cible']) && is_string($type['classe_cible']) ? $type['classe_cible'] : null,
            'multiple' => (bool) ($type['multiple'] ?? true),
            'actif' => (bool) ($type['actif'] ?? true),
        ];
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $transformation */
    private function changerType(int $id, callable $transformation): void
    {
        $trouve = false;
        $this->registre->modifier(self::FICHIER, static function (array $etat) use ($id, $transformation, &$trouve): array {
            $types = [];
            foreach ((array) ($etat['types'] ?? []) as $brut) {
                $type = (array) $brut;
                if ((int) ($type['id'] ?? 0) === $id) {
                    $trouve = true;
                    $type = $transformation($type);
                }
                $types[] = $type;
            }
            $etat['version'] = 1;
            $etat['types'] = $types;

            return $etat;
        });
        if (!$trouve) {
            throw new RuntimeException("relation : aucun type de relation nº $id");
        }
    }

    /**
     * @param array<string, mixed> $meta
     * @return list<array<string, mixed>>
     */
    private static function entrees(array $meta): array
    {
        $relations = [];
        foreach ((array) ($meta['relations'] ?? []) as $brut) {
            $relation = (array) $brut;
            $relations[] = [
                'id' => (int) ($relation['id'] ?? 0),
                'type' => (string) ($relation['type'] ?? ''),
                'cible' => (string) ($relation['cible'] ?? ''),
                'titre' => (string) ($relation['titre'] ?? ''),
                'empreinte' => (string) ($relation['empreinte'] ?? ''),
                'cree_le' => (string) ($relation['cree_le'] ?? ''),
                'cree_par' => (string) ($relation['cree_par'] ?? ''),
                'actif' => (bool) ($relation['actif'] ?? true),
                'retire_le' => isset($relation['retire_le']) ? (string) $relation['retire_le'] : '',
                'retire_par' => isset($relation['retire_par']) ? (string) $relation['retire_par'] : '',
            ];
        }

        return $relations;
    }

    /**
     * @param array<string, mixed> $relation
     * @param array{etat:string, chemin:string, titre:string, empreinte:string} $resolution
     * @return array<string, mixed>
     */
    private function presenter(array $relation, string $sens, string $proprietaire, array $resolution): array
    {
        $type = $this->typeParCle((string) $relation['type']);

        return [
            'id' => (int) $relation['id'],
            'type' => (string) $relation['type'],
            'type_nom' => $type['nom'] ?? (string) $relation['type'],
            'nature' => $type['nature'] ?? '',
            'sens' => $sens,
            'proprietaire' => $proprietaire,
            'cible' => (string) $resolution['chemin'],
            'cible_titre' => (string) $resolution['titre'],
            'etat' => (string) $resolution['etat'],
            'etat_libelle' => self::LIBELLES_ETAT[(string) $resolution['etat']] ?? (string) $resolution['etat'],
            'empreinte' => (string) $relation['empreinte'],
            'cree_le' => (string) $relation['cree_le'],
            'cree_par' => (string) $relation['cree_par'],
        ];
    }

    /**
     * @param array<string, mixed> $meta
     * @param list<array<string, mixed>> $relations
     */
    private function ecrireRelations(string $chemin, array $meta, array $relations): void
    {
        $utiles = [];
        foreach ($relations as $relation) {
            $entree = ['id' => (int) $relation['id'], 'type' => (string) $relation['type'],
                'cible' => (string) $relation['cible'], 'titre' => (string) $relation['titre'],
                'empreinte' => (string) $relation['empreinte'], 'cree_le' => (string) $relation['cree_le'],
                'cree_par' => (string) $relation['cree_par'], 'actif' => $relation['actif'] === true];
            if ($entree['actif'] === false) {
                $entree['retire_le'] = (string) ($relation['retire_le'] ?? date('c'));
                $entree['retire_par'] = (string) ($relation['retire_par'] ?? 'systeme');
            }
            $utiles[] = $entree;
        }
        if ($utiles === []) {
            unset($meta['relations']);
        } else {
            $meta['relations'] = $utiles;
        }

        $fichier = $this->depot->cheminAbsolu($chemin) . '.meta.json';
        $temporaire = $fichier . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($temporaire, Fichiers::json($meta, true)) === false) {
            throw new RuntimeException("relation : écriture des métadonnées impossible ($chemin)");
        }
        if (!rename($temporaire, $fichier)) {
            @unlink($temporaire);
            throw new RuntimeException("relation : publication des métadonnées impossible ($chemin)");
        }
        $this->metas = null;
        $this->depot->rapporterMeta($chemin, $meta);
    }

    /** @return array<string, mixed> */
    private function lireMeta(string $chemin): array
    {
        $absolu = $this->depot->cheminAbsolu($chemin);
        if (!is_file("$absolu.meta.json")) {
            return Fichiers::metaMinimale(basename($chemin));
        }
        $meta = json_decode((string) file_get_contents("$absolu.meta.json"), true);

        return is_array($meta) ? $meta : Fichiers::metaMinimale(basename($chemin));
    }

    private function titreDocument(string $chemin): string
    {
        $meta = $this->lireMeta($chemin);

        return isset($meta['titre']) && is_string($meta['titre']) && trim($meta['titre']) !== ''
            ? trim($meta['titre']) : basename($chemin);
    }

    private function classeDocument(string $chemin): string
    {
        $meta = $this->lireMeta($chemin);

        return isset($meta['type']) && is_string($meta['type']) ? $meta['type'] : '';
    }

    /**
     * Tous les `.meta.json` du fonds, indexés par chemin relatif (le dossier fait foi). Les dossiers
     * internes (préfixe point : `.ged`, `.versions`, `.corbeille`) sont ignorés.
     *
     * @return array<string, array<string, mixed>>
     */
    private function toutesLesMetas(): array
    {
        if ($this->metas !== null) {
            return $this->metas;
        }
        $racine = $this->depot->racine();
        $metas = [];
        if (!is_dir($racine)) {
            return $this->metas = [];
        }
        $iterateur = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME),
            static function (string $chemin): bool {
                $nom = basename($chemin);
                if (is_dir($chemin)) {
                    return !str_starts_with($nom, '.');
                }

                return str_ends_with($nom, '.meta.json');
            }
        ));
        foreach ($iterateur as $fichier) {
            if (!is_string($fichier) || !is_file($fichier)) {
                continue;
            }
            $relatif = str_replace('\\', '/', substr($fichier, strlen($racine) + 1, -strlen('.meta.json')));
            $meta = json_decode((string) file_get_contents($fichier), true);
            $metas[$relatif] = is_array($meta) ? $meta : [];
        }

        return $this->metas = $metas;
    }

    /**
     * @return array{chemin_relatif:string, titre:string, empreinte:string, supprime_le:?string, absent_depuis:?string}|null
     */
    private function lignePar(string $colonne, string $valeur): ?array
    {
        if (!in_array($colonne, ['empreinte', 'chemin_relatif'], true)) {
            return null;
        }
        try {
            $requete = Base::pdo($this->config)->prepare(
                "SELECT chemin_relatif, titre, empreinte, supprime_le, absent_depuis FROM documents WHERE $colonne = ? ORDER BY id LIMIT 1"
            );
            $requete->execute([$valeur]);
            $ligne = $requete->fetch(\PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Base muette : le dossier fait foi, la résolution s'arrête proprement (cause TRACÉE).
            error_log('[GEDv2 relations] index illisible : ' . $e->getMessage());

            return null;
        }
        if (!is_array($ligne)) {
            return null;
        }

        return [
            'chemin_relatif' => (string) $ligne['chemin_relatif'],
            'titre' => (string) $ligne['titre'],
            'empreinte' => (string) $ligne['empreinte'],
            'supprime_le' => isset($ligne['supprime_le']) ? (string) $ligne['supprime_le'] : null,
            'absent_depuis' => isset($ligne['absent_depuis']) ? (string) $ligne['absent_depuis'] : null,
        ];
    }

    /**
     * @param array{chemin_relatif:string, titre:string, empreinte:string, supprime_le:?string, absent_depuis:?string} $ligne
     * @return array{etat:string, chemin:string, titre:string, empreinte:string}
     */
    private function etatDepuisLigne(array $ligne, string $chemin, string $titre, string $empreinte): array
    {
        $titreLisible = $ligne['titre'] !== '' ? $ligne['titre'] : ($titre !== '' ? $titre : basename($chemin));
        $empreinteLisible = $empreinte !== '' ? $empreinte : $ligne['empreinte'];
        if ($ligne['supprime_le'] !== null) {
            return ['etat' => 'corbeille', 'chemin' => $ligne['chemin_relatif'], 'titre' => $titreLisible, 'empreinte' => $empreinteLisible];
        }
        if ($ligne['absent_depuis'] !== null) {
            return ['etat' => 'absent', 'chemin' => $ligne['chemin_relatif'], 'titre' => $titreLisible, 'empreinte' => $empreinteLisible];
        }
        if (is_file($this->depot->cheminAbsolu($ligne['chemin_relatif']))) {
            return ['etat' => 'deplace', 'chemin' => $ligne['chemin_relatif'], 'titre' => $titreLisible, 'empreinte' => $empreinteLisible];
        }

        return ['etat' => 'absent', 'chemin' => $ligne['chemin_relatif'], 'titre' => $titreLisible, 'empreinte' => $empreinteLisible];
    }

    private function estComposition(string $cleType): bool
    {
        $type = $this->typeParCle($cleType);

        return ($type['nature'] ?? $cleType) === 'sous-objet';
    }

    private function exigerClasse(?string $classeExigee, string $classeDocument, string $bout, string $nomType): void
    {
        if ($classeExigee === null || $classeExigee === '' || $classeDocument === $classeExigee) {
            return;
        }
        throw new RuntimeException("relation : le lien « $nomType » exige la classe « $classeExigee » du côté $bout");
    }

    /** Comparaison de chemins portable : séparateurs normalisés, casse ignorée (Windows comme Linux). */
    private static function memeChemin(string $a, string $b): bool
    {
        return strtolower(str_replace('\\', '/', $a)) === strtolower(str_replace('\\', '/', $b));
    }

    private static function normaliserChemin(string $chemin): string
    {
        return trim(str_replace('\\', '/', trim($chemin)), '/');
    }

    private static function validerNom(string $nom): string
    {
        $nom = trim($nom);
        if ($nom === '' || mb_strlen($nom) > 100) {
            throw new RuntimeException('relation : nom de type vide ou trop long (100 caractères au plus)');
        }

        return $nom;
    }

    private static function validerNature(string $nature): string
    {
        if (!in_array($nature, self::NATURES, true)) {
            throw new RuntimeException("relation : nature inconnue « $nature » (attendues : " . implode(', ', self::NATURES) . ')');
        }

        return $nature;
    }

    /** @param list<array<string, mixed>> $entrees */
    private static function prochainId(array $entrees): int
    {
        $max = 0;
        foreach ($entrees as $entree) {
            $max = max($max, (int) ($entree['id'] ?? 0));
        }

        return $max + 1;
    }
}

<?php

declare(strict_types=1);

namespace Ged\ClassesMetadata;

use Ged\Base;
use Ged\Config;
use Ged\Socle\Champs;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Fichiers;
use Ged\Socle\Listes;
use Ged\Socle\Nomenclature;
use RuntimeException;

/**
 * Carte de métadonnées dynamique (famille M-Files nº 1, lot ged-mfiles-classes-1).
 *
 * Un document porte une CLASSE (le type de la nomenclature) qui détermine les propriétés
 * affichées/saisies : choisir une classe adapte la carte. La DÉFINITION vit dans le fonds
 * (`.ged/nomenclature.json` et `.ged/listes.json` : configuration portable et versionnable) ; les
 * VALEURS vivent dans le `.meta.json` à côté du fichier (le dossier fait foi).
 *
 * Ce service est l'orchestrateur : il résout les alias d'une structure (classe, propriété, liste),
 * compose la carte d'une classe ou d'un document, valide une saisie (type, obligatoire, liste
 * nommée) et écrit des propriétés par lot — sans jamais déplacer un fichier ni en supprimer un.
 */
final class Carte
{
    private readonly Config $config;
    private readonly Nomenclature $nomenclature;
    private readonly Champs $champs;
    private readonly Listes $listes;
    private readonly DepotFichiers $depot;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->nomenclature = new Nomenclature($config);
        $this->champs = new Champs($config);
        $this->listes = new Listes($config);
        $this->depot = new DepotFichiers($config);
    }

    /**
     * La carte d'une CLASSE : ses propriétés attendues, listes nommées résolues.
     *
     * @return array{classe:array{id:int,nom:string,cle:string}, proprietes:list<array<string,mixed>>}
     */
    public function pourClasse(string $classe): array
    {
        $type = null;
        foreach ($this->nomenclature->lister(false) as $t) {
            if ($t['nom'] === $classe) {
                $type = $t;
                break;
            }
        }

        return [
            'classe' => $type === null
                ? ['id' => 0, 'nom' => $classe, 'cle' => Fichiers::slug($classe)]
                : ['id' => (int) $type['id'], 'nom' => (string) $type['nom'], 'cle' => Fichiers::slug((string) $type['nom'])],
            'proprietes' => $this->champs->pourType($classe),
        ];
    }

    /**
     * La carte d'un DOCUMENT : sa classe (le type du `.meta.json`) et ses propriétés avec leur valeur.
     *
     * @return array{chemin:string, classe:string, proprietes:list<array<string,mixed>>, valeurs:array<string,mixed>}
     */
    public function pourDocument(string $cheminRelatif): array
    {
        $meta = $this->lireMeta($cheminRelatif);
        $classe = isset($meta['type']) && is_string($meta['type']) ? $meta['type'] : '';
        $valeurs = isset($meta['champs']) && is_array($meta['champs']) ? $meta['champs'] : [];

        $proprietes = [];
        foreach ($this->champs->pourType($classe) as $def) {
            $proprietes[] = $def + ['valeur' => $valeurs[$def['cle']] ?? null];
        }

        return ['chemin' => $cheminRelatif, 'classe' => $classe, 'proprietes' => $proprietes, 'valeurs' => $valeurs];
    }

    /**
     * La carte de plusieurs documents, indexée par chemin.
     *
     * @param list<string> $chemins
     * @return array<string, array<string,mixed>>
     */
    public function pourDocuments(array $chemins): array
    {
        $cartes = [];
        foreach ($chemins as $chemin) {
            $cartes[$chemin] = $this->pourDocument($chemin);
        }

        return $cartes;
    }

    /** Une valeur de propriété lue dans le `.meta.json` du document, ou null. */
    public function valeur(string $cheminRelatif, string $cle): mixed
    {
        $meta = $this->lireMeta($cheminRelatif);

        return isset($meta['champs']) && is_array($meta['champs']) ? ($meta['champs'][$cle] ?? null) : null;
    }

    /**
     * Pose une propriété typée sur un document (le dossier fait foi), puis rend la carte à jour.
     *
     * @return array<string,mixed>
     */
    public function poser(string $cheminRelatif, string $cle, mixed $valeur): array
    {
        $this->champs->poserValeurTypée($cheminRelatif, $cle, $valeur);

        return $this->pourDocument($cheminRelatif);
    }

    /**
     * Pose plusieurs propriétés sur un document. La validité est vérifiée AVANT toute écriture : une
     * saisie refusée ne laisse pas une carte à moitié écrite (les propriétés obligatoires ne sont pas
     * exigées ici, un lot de propriétés est une mise à jour partielle).
     *
     * @param array<string,mixed> $valeurs
     * @return array<string,mixed>
     */
    public function poserPlusieurs(string $cheminRelatif, array $valeurs): array
    {
        $meta = $this->lireMeta($cheminRelatif);
        $classe = isset($meta['type']) && is_string($meta['type']) ? $meta['type'] : '';
        if ($classe === '') {
            throw new RuntimeException("carte : le document $cheminRelatif n’a pas de classe");
        }
        $nettoyees = $this->valider($classe, $valeurs, false);
        foreach ($nettoyees as $cle => $valeur) {
            $this->champs->poserValeurTypée($cheminRelatif, $cle, $valeur);
        }

        return $this->pourDocument($cheminRelatif);
    }

    /**
     * Pose les mêmes propriétés sur plusieurs documents ; rend une carte à jour par document.
     *
     * @param list<string> $chemins
     * @param array<string,mixed> $valeurs
     * @return list<array<string,mixed>>
     */
    public function poserSurPlusieurs(array $chemins, array $valeurs): array
    {
        $cartes = [];
        foreach ($chemins as $chemin) {
            $cartes[] = $this->poserPlusieurs($chemin, $valeurs);
        }

        return $cartes;
    }

    /**
     * Efface une propriété (valeur vide) : le champ quitte le `.meta.json`, le fichier et son
     * historique restent intacts (aucune donnée utilisateur n'est supprimée).
     *
     * @return array<string,mixed>
     */
    public function effacer(string $cheminRelatif, string $cle): array
    {
        $this->champs->poserValeurTypée($cheminRelatif, $cle, null);

        return $this->pourDocument($cheminRelatif);
    }

    /**
     * Valide une saisie pour une classe : chaque propriété déclarée passe le contrôle de type, les
     * propriétés obligatoires doivent être renseignées (sauf mise à jour partielle), et une propriété
     * de nature liste n'accepte qu'une valeur ACTIVE de sa liste nommée.
     *
     * @param array<string,mixed> $valeurs
     * @return array<string,mixed> les valeurs typées prêtes à écrire
     */
    public function valider(string $classe, array $valeurs, bool $exigerObligatoires = true): array
    {
        $nettoyees = [];
        foreach ($this->champs->pourType($classe) as $def) {
            $cle = (string) $def['cle'];
            $brute = $valeurs[$cle] ?? null;
            $vide = $brute === null || (is_string($brute) && trim($brute) === '');
            if ($vide) {
                if ($exigerObligatoires && $def['obligatoire'] === true) {
                    throw new RuntimeException("carte : la propriété « {$def['nom']} » est obligatoire pour la classe $classe");
                }
                continue;
            }
            $nettoyees[$cle] = $this->champs->validerDefinition($def, $brute);
        }

        return $nettoyees;
    }

    /**
     * Les valeurs proposées par une propriété : celles de sa liste nommée, ou ses options en ligne.
     *
     * @return array{id:int, cle:string, nom:string, liste:?string, valeurs:list<string>}
     */
    public function valeursDePropriete(int $champId): array
    {
        $def = $this->champs->parId($champId);
        if ($def === null) {
            throw new RuntimeException("carte : aucune propriété nº $champId");
        }

        return ['id' => $def['id'], 'cle' => $def['cle'], 'nom' => $def['nom'],
            'liste' => $def['liste'], 'valeurs' => $def['options']];
    }

    /**
     * La classe portant cet alias (clé, slug du nom ou identifiant), ou null.
     *
     * @return array{id:int, nom:string, cle:string}|null
     */
    public function classeParAlias(string $alias): ?array
    {
        $slug = Fichiers::slug($alias);
        foreach ($this->nomenclature->lister(false) as $t) {
            if (Fichiers::slug((string) $t['nom']) === $slug || (ctype_digit($alias) && (int) $alias === (int) $t['id'])) {
                return ['id' => (int) $t['id'], 'nom' => (string) $t['nom'], 'cle' => Fichiers::slug((string) $t['nom'])];
            }
        }

        return null;
    }

    /**
     * Résout un lot d'alias de classe : alias => identifiant (les alias inconnus sont absents).
     *
     * @param list<string> $alias
     * @return array<string,int>
     */
    public function classesParAlias(array $alias): array
    {
        $resolus = [];
        foreach ($alias as $nom) {
            $classe = $this->classeParAlias($nom);
            if ($classe !== null) {
                $resolus[$nom] = $classe['id'];
            }
        }

        return $resolus;
    }

    /**
     * Résout un alias d'ÉLÉMENT DE STRUCTURE, comme la documentation publique M-Files le publie :
     * une classe, une propriété ou une liste nommée — jamais deux à la fois.
     *
     * @return array{type:string, id:int, nom:string, cle:string}|null
     */
    public function resoudreAlias(string $alias): ?array
    {
        $classe = $this->classeParAlias($alias);
        if ($classe !== null) {
            return ['type' => 'classe', 'id' => $classe['id'], 'nom' => $classe['nom'], 'cle' => $classe['cle']];
        }
        $liste = $this->listes->parCle(Fichiers::slug($alias));
        if ($liste !== null) {
            return ['type' => 'liste', 'id' => $liste['id'], 'nom' => $liste['nom'], 'cle' => $liste['cle']];
        }
        foreach ($this->champs->parCle(Fichiers::slug($alias)) as $def) {
            return ['type' => 'propriete', 'id' => $def['id'], 'nom' => $def['nom'], 'cle' => $def['cle']];
        }

        return null;
    }

    /**
     * Les documents VIVANTS d'une classe (le fonds, pas la base : un fichier disparu est ignoré).
     *
     * @return list<array{id:int, chemin:string, titre:string, type:string}>
     */
    public function documentsDeClasse(string $classe): array
    {
        $requete = Base::pdo($this->config)->prepare(
            'SELECT id, chemin_relatif, titre, type FROM documents'
            . ' WHERE type = ? AND supprime_le IS NULL AND absent_depuis IS NULL ORDER BY id',
        );
        $requete->execute([$classe]);
        $documents = [];
        foreach ($requete->fetchAll(\PDO::FETCH_ASSOC) as $ligne) {
            $documents[] = ['id' => (int) $ligne['id'], 'chemin' => (string) $ligne['chemin_relatif'],
                'titre' => (string) $ligne['titre'], 'type' => (string) $ligne['type']];
        }

        return $documents;
    }

    /**
     * La structure de métadonnées du fonds : classes, propriétés et listes nommées, avec leurs ids.
     *
     * @return array{classes:list<array{id:int,nom:string,cle:string}>, proprietes:list<array{id:int,nom:string,cle:string,classe:string}>, listes:list<array{id:int,nom:string,cle:string}>}
     */
    public function structure(): array
    {
        $classes = [];
        foreach ($this->nomenclature->lister(false) as $t) {
            $classes[] = ['id' => (int) $t['id'], 'nom' => (string) $t['nom'], 'cle' => Fichiers::slug((string) $t['nom'])];
        }
        $proprietes = [];
        foreach ($this->champs->tousInclusifs() as $def) {
            $proprietes[] = ['id' => $def['id'], 'nom' => $def['nom'], 'cle' => $def['cle'], 'classe' => ''];
        }
        $listes = [];
        foreach ($this->listes->lister(false) as $liste) {
            $listes[] = ['id' => $liste['id'], 'nom' => $liste['nom'], 'cle' => $liste['cle']];
        }

        return ['classes' => $classes, 'proprietes' => $proprietes, 'listes' => $listes];
    }

    /** @return array<string,mixed> */
    private function lireMeta(string $cheminRelatif): array
    {
        $absolu = $this->depot->cheminAbsolu($cheminRelatif);
        if (!is_file($absolu)) {
            throw new RuntimeException("carte : document introuvable dans le fonds ($cheminRelatif)");
        }
        if (!is_file("$absolu.meta.json")) {
            return Fichiers::metaMinimale(basename($cheminRelatif));
        }
        $meta = json_decode((string) file_get_contents("$absolu.meta.json"), true);

        return is_array($meta) ? $meta : [];
    }
}

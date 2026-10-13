<?php

declare(strict_types=1);

namespace Ged\ClassesMetadata;

use Ged\Config;
use Ged\Socle\Champs;
use Ged\Socle\Fichiers;
use Ged\Socle\Listes;
use Ged\Socle\Nomenclature;
use RuntimeException;

/**
 * Définition portable et réversible des classes, propriétés et listes nommées (famille M-Files nº 1,
 * lot ged-mfiles-classes-1). L'export est un JSON versionné, lisible à la main, sans chemin interne
 * ni identifiant de base : c'est de la CONFIGURATION, pas un coffre propriétaire. L'import est
 * idempotent : il ajoute ce qui manque et ne supprime jamais rien.
 */
final class Definition
{
    /** Version du format de définition : elle monte si la forme change. */
    public const VERSION = 1;

    private readonly Nomenclature $nomenclature;
    private readonly Champs $champs;
    private readonly Listes $listes;

    public function __construct(Config $config)
    {
        $this->nomenclature = new Nomenclature($config);
        $this->champs = new Champs($config);
        $this->listes = new Listes($config);
    }

    /**
     * Exporte la structure : classes (avec leurs propriétés) et listes nommées (avec leurs valeurs).
     * Déterministe : mêmes entrées, même document — l'aller-retour est comparé à l'identique.
     *
     * @return array{version:int, classes:list<array<string,mixed>>, listes:list<array<string,mixed>>}
     */
    public function exporter(): array
    {
        $classes = [];
        foreach ($this->nomenclature->lister(false) as $type) {
            $proprietes = [];
            foreach ($this->champs->pourType((string) $type['nom']) as $def) {
                $liste = $def['liste'];
                $proprietes[] = [
                    'cle' => $def['cle'],
                    'nom' => $def['nom'],
                    'nature' => $def['nature'],
                    'obligatoire' => $def['obligatoire'],
                    'liste' => $liste,
                    'options' => $liste === null ? $def['options'] : [],
                    'actif' => $def['actif'],
                ];
            }
            $classes[] = ['nom' => $type['nom'], 'actif' => $type['actif'], 'proprietes' => $proprietes];
        }

        $listes = [];
        foreach ($this->listes->lister(false) as $liste) {
            $valeurs = [];
            $complete = $this->listes->trouver($liste['id']);
            foreach ($complete['valeurs'] ?? [] as $valeur) {
                $valeurs[] = ['libelle' => $valeur['libelle'], 'actif' => $valeur['actif']];
            }
            $listes[] = ['nom' => $liste['nom'], 'cle' => $liste['cle'], 'actif' => $liste['actif'], 'valeurs' => $valeurs];
        }

        return ['version' => self::VERSION, 'classes' => $classes, 'listes' => $listes];
    }

    /** Écrit la définition en JSON canonique (indenté) à l'emplacement demandé. */
    public function exporterVersFichier(string $cheminAbsolu): void
    {
        $dossier = dirname($cheminAbsolu);
        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new RuntimeException("définition : impossible de créer $dossier");
        }
        if (file_put_contents($cheminAbsolu, Fichiers::json($this->exporter(), true) . "\n") === false) {
            throw new RuntimeException("définition : écriture impossible vers $cheminAbsolu");
        }
    }

    /**
     * Importe une définition dans le fonds : listes nommées d'abord (les propriétés les référencent),
     * puis classes et propriétés. Idempotent, jamais destructif.
     *
     * @param array<string,mixed> $definition
     * @return array{classes:int, proprietes:int, listes:int, valeurs:int}
     */
    public function importer(array $definition): array
    {
        $classesCreees = 0;
        $proprietesCreees = 0;
        $listesCreees = 0;
        $valeursCreees = 0;

        foreach ((array) ($definition['listes'] ?? []) as $liste) {
            $liste = (array) $liste;
            $nom = trim((string) ($liste['nom'] ?? ''));
            if ($nom === '') {
                continue;
            }
            $existante = $this->listes->parCle(Fichiers::slug($nom));
            $id = $existante['id'] ?? null;
            if ($id === null) {
                $id = $this->listes->ajouter($nom);
                ++$listesCreees;
            }
            foreach ((array) ($liste['valeurs'] ?? []) as $valeur) {
                $valeur = (array) $valeur;
                $libelle = trim((string) ($valeur['libelle'] ?? ''));
                if ($libelle === '') {
                    continue;
                }
                $presente = $this->listes->valeurParLibelle($id, $libelle);
                if ($presente === null) {
                    $valeurId = $this->listes->ajouterValeur($id, $libelle);
                    ++$valeursCreees;
                    $presente = ['id' => $valeurId, 'actif' => true];
                }
                if (($valeur['actif'] ?? true) === false && ($presente['actif'] ?? true) === true) {
                    $this->listes->retirerValeur($id, (int) $presente['id'], 'import');
                }
            }
            if (($liste['actif'] ?? true) === false) {
                $this->listes->definirActif($id, false);
            }
        }

        foreach ((array) ($definition['classes'] ?? []) as $classe) {
            $classe = (array) $classe;
            $nom = trim((string) ($classe['nom'] ?? ''));
            if ($nom === '') {
                continue;
            }
            $typeId = $this->nomenclature->idDuType($nom);
            if ($typeId === null) {
                $typeId = $this->nomenclature->ajouter($nom);
                ++$classesCreees;
            }
            foreach ((array) ($classe['proprietes'] ?? []) as $propriete) {
                $propriete = (array) $propriete;
                $nomChamp = trim((string) ($propriete['nom'] ?? ''));
                if ($nomChamp === '' || $this->champExiste($nom, Fichiers::slug($nomChamp))) {
                    continue;
                }
                $liste = $propriete['liste'] ?? null;
                $def = $this->champs->ajouter(
                    $typeId,
                    $nomChamp,
                    (string) ($propriete['nature'] ?? 'texte'),
                    array_values(array_map('strval', (array) ($propriete['options'] ?? []))),
                    (bool) ($propriete['obligatoire'] ?? false),
                    is_string($liste) && $liste !== '' ? $liste : null,
                );
                ++$proprietesCreees;
                if (($propriete['actif'] ?? true) === false) {
                    $this->champs->definirActif($def['id'], false);
                }
            }
            if (($classe['actif'] ?? true) === false) {
                $this->nomenclature->definirActif($typeId, false);
            }
        }

        return ['classes' => $classesCreees, 'proprietes' => $proprietesCreees,
            'listes' => $listesCreees, 'valeurs' => $valeursCreees];
    }

    /**
     * Importe une définition depuis un fichier JSON portable.
     *
     * @return array{classes:int, proprietes:int, listes:int, valeurs:int}
     */
    public function importerFichier(string $cheminAbsolu): array
    {
        if (!is_file($cheminAbsolu)) {
            throw new RuntimeException("définition : fichier introuvable ($cheminAbsolu)");
        }
        $donnees = json_decode((string) file_get_contents($cheminAbsolu), true);
        if (!is_array($donnees)) {
            throw new RuntimeException("définition : « $cheminAbsolu » n'est pas un JSON lisible");
        }

        return $this->importer($donnees);
    }

    /** Une propriété de ce nom (clé) existe-t-elle déjà sur cette classe ? */
    private function champExiste(string $classe, string $cle): bool
    {
        foreach ($this->champs->pourType($classe) as $def) {
            if ($def['cle'] === $cle) {
                return true;
            }
        }

        return false;
    }
}

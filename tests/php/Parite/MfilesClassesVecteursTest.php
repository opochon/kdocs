<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\ClassesMetadata\Carte;
use Ged\ClassesMetadata\Definition;
use Ged\Socle\Champs;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Fichiers;
use Ged\Socle\Listes;
use Ged\Socle\Nomenclature;
use Ged\Socle\Registre;
use Ged\Tests\ClassesMetadata\SupportClassesMetadata;
use PHPUnit\Framework\TestCase;

/**
 * Rejeu des VECTEURS DE COMPORTEMENT M-Files de la famille 1 (classes d'objets, métadonnées
 * dynamiques, listes de valeurs nommées) contre les vrais services GEDv2 `Ged\Socle\Listes`,
 * `Ged\Socle\Champs` et `Ged\ClassesMetadata\Carte`. Chaque vecteur décrit un scénario et sa sortie
 * mesurée sur un fonds jetable ; la mesure est écrite pour le juge M-Files.
 *
 * Ces vecteurs sont dérivés de la DOCUMENTATION PUBLIQUE M-Files, pas de son code. Un écart fait
 * échouer le rejeu.
 */
final class MfilesClassesVecteursTest extends TestCase
{
    use SupportClassesMetadata;

    private Nomenclature $nomenclature;
    private Listes $listes;
    private Champs $champs;
    private Carte $carte;
    private DepotFichiers $depot;

    protected function setUp(): void
    {
        $this->setUpClassesMetadata();
        $this->nomenclature = new Nomenclature($this->config);
        $this->listes = new Listes($this->config);
        $this->champs = new Champs($this->config);
        $this->carte = new Carte($this->config);
        $this->depot = new DepotFichiers($this->config);
    }

    protected function tearDown(): void
    {
        $this->tearDownClassesMetadata();
    }

    /**
     * Un fonds NU (et sa base) pour chaque vecteur : deux scénarios ne partagent jamais un état —
     * une valeur retirée ou une propriété créée par un vecteur ne fausse pas le suivant.
     */
    private function fondsNeuf(): void
    {
        $this->tearDownClassesMetadata();
        $this->setUpClassesMetadata();
        Registre::oublier();
        $this->nomenclature = new Nomenclature($this->config);
        $this->listes = new Listes($this->config);
        $this->champs = new Champs($this->config);
        $this->carte = new Carte($this->config);
        $this->depot = new DepotFichiers($this->config);
    }

    public function testRejoueLesVecteursDesListesDeValeursNommees(): void
    {
        $mesure = $this->rejouer('mfiles-classes-listes');
        Vecteurs::ecrireMesure('mfiles-classes-listes', $mesure);

        self::assertSame(4, $mesure['total']);
        self::assertSame(4, $mesure['passes'], 'vecteurs de listes en écart : ' . json_encode($mesure['ecarts'], JSON_UNESCAPED_UNICODE));
    }

    public function testRejoueLesVecteursDeLaCarteDynamique(): void
    {
        $mesure = $this->rejouer('mfiles-classes-carte');
        Vecteurs::ecrireMesure('mfiles-classes-carte', $mesure);

        self::assertSame(8, $mesure['total']);
        self::assertSame(8, $mesure['passes'], 'vecteurs de carte en écart : ' . json_encode($mesure['ecarts'], JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array{categorie:string, total:int, passes:int, ecarts:list<array<string,mixed>>}
     */
    private function rejouer(string $fichier): array
    {
        $donnees = Vecteurs::charger($fichier);
        $passes = 0;
        $ecarts = [];
        foreach ($donnees['vecteurs'] as $vecteur) {
            $id = (string) $vecteur['id'];
            $attendu = (array) $vecteur['sortie'];
            $this->fondsNeuf();   // un fonds NEUF par vecteur : aucun mélange entre scénarios
            $obtenu = $this->executer((array) $vecteur['entree']);
            if ($obtenu === $attendu) {
                ++$passes;
            } else {
                $ecarts[] = ['id' => $id, 'attendu' => $attendu, 'obtenu' => $obtenu];
            }
        }

        return ['categorie' => (string) $donnees['categorie'], 'total' => count($donnees['vecteurs']),
            'passes' => $passes, 'ecarts' => $ecarts];
    }

    /**
     * @param array<string,mixed> $entree
     * @return array<string,mixed>
     */
    private function executer(array $entree): array
    {
        $this->nomenclature->assurer();
        $this->listes->assurer();

        return match ((string) $entree['scenario']) {
            'liste-creation' => $this->scenarioListeCreation($entree),
            'liste-retrait' => $this->scenarioListeRetrait($entree),
            'liste-alias' => $this->scenarioListeAlias($entree),
            'propriete-reference-liste' => $this->scenarioProprieteReferenceListe($entree),
            'carte-classe' => $this->scenarioCarteClasse($entree),
            'carte-document' => $this->scenarioCarteDocument($entree),
            'carte-obligatoire' => $this->scenarioCarteObligatoire($entree),
            'carte-liste-inconnue' => $this->scenarioCarteListeInconnue($entree),
            'carte-valeur-hors-liste' => $this->scenarioCarteValeurHorsListe($entree),
            'carte-alias-structure' => $this->scenarioCarteAliasStructure($entree),
            'carte-lots' => $this->scenarioCarteLots($entree),
            'definition-reversible' => $this->scenarioDefinitionReversible($entree),
            default => throw new \RuntimeException('scénario de vecteur inconnu : ' . (string) $entree['scenario']),
        };
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioListeCreation(array $entree): array
    {
        $id = $this->listes->ajouter((string) $entree['nom']);
        foreach ((array) $entree['valeurs'] as $libelle) {
            $this->listes->ajouterValeur($id, (string) $libelle);
        }
        $liste = $this->listes->trouver($id);
        if ($liste === null) {
            throw new \RuntimeException('liste créée non relue');
        }

        return ['nom' => $liste['nom'], 'cle' => $liste['cle'], 'valeurs' => array_column($liste['valeurs'], 'libelle')];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioListeRetrait(array $entree): array
    {
        $cle = (string) $entree['cle'];
        $libelle = (string) $entree['libelle'];
        $liste = $this->listes->parCle($cle);
        if ($liste === null) {
            throw new \RuntimeException("liste absente : $cle");
        }
        $valeur = $this->listes->valeurParLibelle($liste['id'], $libelle);
        if ($valeur === null) {
            throw new \RuntimeException("valeur absente : $libelle");
        }
        $activeAvant = $this->listes->estValeurActive($cle, $libelle);

        $this->listes->retirerValeur($liste['id'], (int) $valeur['id'], (string) $entree['auteur']);
        $relue = $this->listes->valeur($liste['id'], (int) $valeur['id']);

        return [
            'active_avant' => $activeAvant,
            'active_apres' => $this->listes->estValeurActive($cle, $libelle),
            'proposee_apres' => in_array($libelle, $this->listes->valeursDe($cle), true),
            'visible_inclusive' => in_array($libelle, $this->listes->valeursDe($cle, false), true),
            'date_posee' => $relue !== null && (string) ($relue['retire_le'] ?? '') !== '',
            'auteur' => (string) ($relue['retire_par'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioListeAlias(array $entree): array
    {
        $id = $this->listes->resoudreAlias((string) $entree['alias']);
        $liste = $id === null ? null : $this->listes->trouver($id);

        return ['cle' => (string) ($liste['cle'] ?? ''), 'type' => $liste === null ? '' : 'liste'];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioProprieteReferenceListe(array $entree): array
    {
        $this->champs->ajouter($this->idDuType((string) $entree['classe']), (string) $entree['nom'], 'liste', [],
            (bool) ($entree['obligatoire'] ?? false), (string) $entree['cle_liste']);
        $def = $this->champs->pourType((string) $entree['classe'])[0];

        return ['liste' => $def['liste'], 'obligatoire' => $def['obligatoire'], 'options' => $def['options']];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioCarteClasse(array $entree): array
    {
        foreach ((array) $entree['proprietes'] as $propriete) {
            $propriete = (array) $propriete;
            $this->champs->ajouter($this->idDuType((string) $entree['classe']), (string) $propriete['nom'],
                (string) $propriete['nature'], [], (bool) ($propriete['obligatoire'] ?? false),
                isset($propriete['liste']) ? (string) $propriete['liste'] : null);
        }
        $carte = $this->carte->pourClasse((string) $entree['classe']);
        $obligatoires = [];
        foreach ($carte['proprietes'] as $propriete) {
            if ($propriete['obligatoire'] === true) {
                $obligatoires[] = $propriete['cle'];
            }
        }

        return ['classe' => $carte['classe']['nom'], 'cles' => array_column($carte['proprietes'], 'cle'),
            'obligatoires' => $obligatoires];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioCarteDocument(array $entree): array
    {
        $propriete = (array) $entree['propriete'];
        $this->champs->ajouter($this->idDuType((string) $entree['classe']), (string) $propriete['nom'],
            (string) $propriete['nature'], [], (bool) ($propriete['obligatoire'] ?? false),
            isset($propriete['liste']) ? (string) $propriete['liste'] : null);
        $chemin = $this->deposer((string) $entree['classe']);
        $this->carte->poser($chemin, Fichiers::slug((string) $propriete['nom']), (string) $entree['valeur']);
        $carte = $this->carte->pourDocument($chemin);

        return ['classe' => $carte['classe'], 'valeur' => $carte['proprietes'][0]['valeur'],
            'options' => $carte['proprietes'][0]['options']];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioCarteObligatoire(array $entree): array
    {
        $propriete = (array) $entree['propriete'];
        $this->champs->ajouter($this->idDuType((string) $entree['classe']), (string) $propriete['nom'],
            (string) $propriete['nature'], [], (bool) ($propriete['obligatoire'] ?? false));

        return $this->refus(fn (): array => $this->carte->valider((string) $entree['classe'], []), 'obligatoire');
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioCarteListeInconnue(array $entree): array
    {
        $propriete = (array) $entree['propriete'];

        return $this->refus(fn (): array => [$this->champs->ajouter($this->idDuType((string) $entree['classe']),
            (string) $propriete['nom'], (string) $propriete['nature'], [], false, (string) $propriete['liste'])],
            (string) $propriete['liste']);
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioCarteValeurHorsListe(array $entree): array
    {
        $propriete = (array) $entree['propriete'];
        $this->champs->ajouter($this->idDuType((string) $entree['classe']), (string) $propriete['nom'],
            (string) $propriete['nature'], [], false, (string) $propriete['liste']);

        return $this->refus(fn (): array => $this->carte->valider((string) $entree['classe'],
            [Fichiers::slug((string) $propriete['nom']) => (string) $entree['valeur']]), (string) $propriete['liste']);
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioCarteAliasStructure(array $entree): array
    {
        $propriete = (array) $entree['propriete'];
        $this->champs->ajouter($this->idDuType((string) $entree['classe']), (string) $propriete['nom'],
            (string) $propriete['nature']);
        $alias = $this->carte->resoudreAlias((string) $entree['alias']);

        return ['type' => (string) ($alias['type'] ?? '')];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioCarteLots(array $entree): array
    {
        $propriete = (array) $entree['propriete'];
        $this->champs->ajouter($this->idDuType((string) $entree['classe']), (string) $propriete['nom'],
            (string) $propriete['nature'], [], false, (string) $propriete['liste']);
        $chemins = [];
        for ($i = 0; $i < (int) $entree['documents']; $i++) {
            $chemins[] = $this->deposer((string) $entree['classe'], 'lot-' . $i . '.pdf');
        }
        $cartes = $this->carte->poserSurPlusieurs($chemins, [Fichiers::slug((string) $propriete['nom']) => (string) $entree['valeur']]);
        $valeurs = [];
        foreach ($cartes as $carte) {
            $valeurs[] = $carte['proprietes'][0]['valeur'];
        }

        return ['documents' => count($cartes), 'valeurs' => $valeurs];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioDefinitionReversible(array $entree): array
    {
        $propriete = (array) $entree['propriete'];
        $this->champs->ajouter($this->idDuType((string) $entree['classe']), (string) $propriete['nom'],
            (string) $propriete['nature'], [], (bool) ($propriete['obligatoire'] ?? false),
            isset($propriete['liste']) ? (string) $propriete['liste'] : null);

        $definition = new Definition($this->config);
        $export = $definition->exporter();
        @unlink($this->racine . '/fonds/.ged/nomenclature.json');
        @unlink($this->racine . '/fonds/.ged/listes.json');
        Registre::oublier();
        $definition->importer($export);
        $relu = $definition->exporter();

        $compte = 0;
        foreach ($relu['classes'] as $classe) {
            if ($classe['nom'] === (string) $entree['classe']) {
                $compte = count($classe['proprietes']);
            }
        }

        return ['identique' => $export === $relu, 'proprietes_exportees' => $compte];
    }

    /**
     * Exécute une action en attendant un refus nommé ; rend {refus, nomme}.
     *
     * @param callable():array<int|string,mixed> $action
     * @return array{refus:bool, nomme:string}
     */
    private function refus(callable $action, string $attendu): array
    {
        try {
            $action();
        } catch (\RuntimeException $e) {
            return ['refus' => true, 'nomme' => str_contains($e->getMessage(), $attendu) ? $attendu : ''];
        }

        return ['refus' => false, 'nomme' => ''];
    }

    private function idDuType(string $nom): int
    {
        return $this->nomenclature->idDuType($nom) ?? throw new \RuntimeException("type absent : $nom");
    }

    /** Dépose un document de cette classe dans le fonds ; rend son chemin relatif. */
    private function deposer(string $classe, ?string $nomFichier = null): string
    {
        $nomFichier ??= strtolower($classe) . '-' . bin2hex(random_bytes(3)) . '.pdf';
        $id = $this->depot->deposer($this->pieceADeposer($nomFichier, 'contenu ' . $nomFichier), [
            'titre' => 'Pièce ' . $nomFichier, 'type' => $classe, 'date' => '2026-09-01',
        ]);

        return (string) $this->depot->fiche($id)['chemin_relatif'];
    }
}

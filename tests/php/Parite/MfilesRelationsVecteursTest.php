<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Socle\DepotFichiers;
use Ged\Socle\Registre;
use Ged\Socle\Relations;
use Ged\Tests\Socle\SupportRelations;
use PHPUnit\Framework\TestCase;

/**
 * Rejeu des VECTEURS DE COMPORTEMENT M-Files de la famille 2 (relations entre objets, composition
 * parent-enfant, références croisées) contre le vrai service GEDv2 `Ged\Socle\Relations`. Chaque vecteur
 * décrit un scénario et sa sortie mesurée sur un fonds jetable ; la mesure est écrite pour le juge
 * M-Files.
 *
 * Ces vecteurs sont dérivés de la DOCUMENTATION PUBLIQUE M-Files (relationships, subobjects, demoting
 * objects), pas de son code. Un écart fait échouer le rejeu.
 */
final class MfilesRelationsVecteursTest extends TestCase
{
    use SupportRelations;

    private Relations $relations;
    private DepotFichiers $depot;

    protected function setUp(): void
    {
        $this->setUpRelations();
        $this->services();
    }

    protected function tearDown(): void
    {
        $this->tearDownRelations();
    }

    public function testRejoueLesVecteursDesLiensTypes(): void
    {
        $mesure = $this->rejouer('mfiles-relations-liens');
        Vecteurs::ecrireMesure('mfiles-relations-liens', $mesure);

        self::assertSame(7, $mesure['total']);
        self::assertSame(7, $mesure['passes'], 'vecteurs de liens en écart : ' . json_encode($mesure['ecarts'], JSON_UNESCAPED_UNICODE));
    }

    public function testRejoueLesVecteursDeLaCompositionEtDeLIntegrite(): void
    {
        $mesure = $this->rejouer('mfiles-relations-composition');
        Vecteurs::ecrireMesure('mfiles-relations-composition', $mesure);

        self::assertSame(6, $mesure['total']);
        self::assertSame(6, $mesure['passes'], 'vecteurs de composition en écart : ' . json_encode($mesure['ecarts'], JSON_UNESCAPED_UNICODE));
    }

    /** Un fonds NU (et sa base) par vecteur : deux scénarios ne partagent jamais un état. */
    private function services(): void
    {
        Registre::oublier();
        $this->relations = new Relations($this->config);
        $this->depot = new DepotFichiers($this->config);
        $this->relations->assurer();
    }

    private function fondsNeuf(): void
    {
        $this->tearDownRelations();
        $this->setUpRelations();
        $this->services();
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
            $this->fondsNeuf();
            $id = (string) $vecteur['id'];
            $attendu = (array) $vecteur['sortie'];
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
        return match ((string) $entree['scenario']) {
            'relation-ajout' => $this->scenarioRelationAjout($entree),
            'relation-comptage' => $this->scenarioRelationComptage(),
            'relation-refus' => $this->scenarioRelationRefus($entree),
            'relation-retrait' => $this->scenarioRelationRetrait($entree),
            'relation-definition' => $this->scenarioRelationDefinition(),
            'composition-parent-enfant' => $this->scenarioCompositionParentEnfant(),
            'composition-comptage' => $this->scenarioCompositionComptage(),
            'composition-detachement' => $this->scenarioCompositionDetachement(),
            'reference-corbeille' => $this->scenarioReferenceCorbeille(),
            'reference-deplacement' => $this->scenarioReferenceDeplacement(),
            'hierarchie-fs' => $this->scenarioHierarchieFs(),
            default => throw new \RuntimeException('scénario de vecteur inconnu : ' . (string) $entree['scenario']),
        };
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioRelationAjout(array $entree): array
    {
        $a = $this->deposer('Facture A', 'Facture');
        $b = $this->deposer('Contrat B', 'Contrat');
        $this->relations->lier($a, $b, (string) $entree['type']);
        $source = $this->relations->pourDocument($a);
        $cible = $this->relations->pourDocument($b);

        return ['sortantes' => count($source), 'entrantes' => count($cible), 'type' => $source[0]['type'],
            'nom' => $source[0]['type_nom'], 'sens_source' => $source[0]['sens'], 'sens_cible' => $cible[0]['sens']];
    }

    /** @return array<string,mixed> */
    private function scenarioRelationComptage(): array
    {
        $a = $this->deposer('Facture A', 'Facture');
        $b = $this->deposer('Contrat B', 'Contrat');
        $c = $this->deposer('Reçu C', 'Reçu');
        $this->relations->lier($a, $b, 'reference');
        $this->relations->lier($a, $c, 'reference');

        return ['source' => $this->relations->compter($a), 'cible' => $this->relations->compter($b),
            'autre' => $this->relations->compter($c)];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioRelationRefus(array $entree): array
    {
        $a = $this->deposer('Facture A', 'Facture');
        $b = $this->deposer('Contrat B', 'Contrat');
        $motif = (string) $entree['motif'];
        $geste = (string) $entree['geste'];
        try {
            match ($geste) {
                'auto-lien' => $this->relations->lier($a, $a, 'reference'),
                'type-inconnu' => $this->relations->lier($a, $b, 'inventee'),
                'doublon' => $this->doublon($a, $b),
                default => throw new \RuntimeException('geste de refus inconnu : ' . $geste),
            };
        } catch (\RuntimeException $e) {
            return ['refus' => true, 'nomme' => str_contains($e->getMessage(), $motif) ? $motif : ''];
        }

        return ['refus' => false, 'nomme' => ''];
    }

    private function doublon(string $a, string $b): int
    {
        $this->relations->lier($a, $b, 'reference');

        return $this->relations->lier($a, $b, 'reference');
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioRelationRetrait(array $entree): array
    {
        $a = $this->deposer('Facture A', 'Facture');
        $b = $this->deposer('Contrat B', 'Contrat');
        $auteur = (string) $entree['auteur'];
        $id = $this->relations->lier($a, $b, 'reference');
        $this->relations->retirer($a, $id, $auteur);

        $relique = null;
        $retirees = 0;
        foreach ((array) ($this->meta($a)['relations'] ?? []) as $entreeRelation) {
            $entreeRelation = (array) $entreeRelation;
            if ((int) ($entreeRelation['id'] ?? 0) === $id) {
                $relique = $entreeRelation;
            }
            if (($entreeRelation['actif'] ?? true) === false) {
                ++$retirees;
            }
        }

        return ['actives_apres' => count($this->relations->pourDocument($a)), 'retirees' => $retirees,
            'date_posee' => $relique !== null && (string) ($relique['retire_le'] ?? '') !== '',
            'auteur' => (string) ($relique['retire_par'] ?? ''),
            'cible_conservee' => (string) ($relique['cible'] ?? '') === $b];
    }

    /** @return array<string,mixed> */
    private function scenarioRelationDefinition(): array
    {
        $a = $this->deposer('Facture A', 'Facture');
        $b = $this->deposer('Contrat B', 'Contrat');
        $this->relations->lier($a, $b, 'reference');
        $definition = $this->relations->exporter();

        @unlink($this->racine . '/fonds/.ged/relations.json');
        Registre::oublier();
        $this->relations->importer($definition);
        $relu = $this->relations->exporter();

        return ['identique' => $definition === $relu, 'types' => count($relu['types'])];
    }

    /** @return array<string,mixed> */
    private function scenarioCompositionParentEnfant(): array
    {
        $dossier = $this->deposer('Dossier client', 'Courrier');
        $piece = $this->deposer('Pièce A', 'Facture');
        $this->relations->lier($dossier, $piece, 'sous-objet');
        $this->relations->lier($dossier, $this->deposer('Pièce B', 'Reçu'), 'sous-objet');

        $parents = $this->relations->parents($piece);

        return ['enfants' => count($this->relations->enfants($dossier)), 'parents_piece' => count($parents),
            'parent_nomme' => (string) ($parents[0]['chemin'] ?? '') === $dossier];
    }

    /** @return array<string,mixed> */
    private function scenarioCompositionComptage(): array
    {
        $dossier = $this->deposer('Dossier client', 'Courrier');
        $this->relations->lier($dossier, $this->deposer('Pièce A', 'Facture'), 'sous-objet');
        $this->relations->lier($dossier, $this->deposer('Pièce B', 'Reçu'), 'sous-objet');

        return ['sous_objets' => $this->relations->compterEnfants($dossier), 'relations_totales' => $this->relations->compter($dossier)];
    }

    /** @return array<string,mixed> */
    private function scenarioCompositionDetachement(): array
    {
        $dossier = $this->deposer('Dossier client', 'Courrier');
        $piece = $this->deposer('Pièce A', 'Facture');
        $this->relations->lier($dossier, $piece, 'sous-objet');
        $this->relations->lier($dossier, $this->deposer('Pièce B', 'Reçu'), 'sous-objet');
        $detaches = $this->relations->detacher($dossier, $piece, 'alice@gedv2.test');

        return ['detaches' => $detaches, 'enfants_apres' => count($this->relations->enfants($dossier)),
            'parents_piece_apres' => count($this->relations->parents($piece)), 'journal' => $this->relations->journal($piece) !== []];
    }

    /** @return array<string,mixed> */
    private function scenarioReferenceCorbeille(): array
    {
        [$cibleId, $cible] = $this->deposerAvecId('Contrat B', 'Contrat');
        $a = $this->deposer('Facture A', 'Facture');
        $this->relations->lier($a, $cible, 'reference');
        $this->depot->miseALaCorbeille($cibleId, 'alice@gedv2.test');
        $carte = $this->relations->pourDocument($a);

        return ['relations' => count($carte), 'etat' => (string) ($carte[0]['etat'] ?? ''),
            'titre_conserve' => (string) ($carte[0]['cible_titre'] ?? '') !== '',
            'empreinte_conservee' => (string) ($carte[0]['empreinte'] ?? '') !== ''];
    }

    /** @return array<string,mixed> */
    private function scenarioReferenceDeplacement(): array
    {
        [$cibleId, $cible] = $this->deposerAvecId('Contrat B', 'Facture');
        $a = $this->deposer('Facture A', 'Facture');
        $this->relations->lier($a, $cible, 'reference');
        $this->depot->mettreAJour($cibleId, ['type' => 'Contrat'], 'alice@gedv2.test');
        $nouveau = (string) $this->depot->fiche($cibleId)['chemin_relatif'];
        $carte = $this->relations->pourDocument($a);

        return ['relations' => count($carte), 'etat' => (string) ($carte[0]['etat'] ?? ''),
            'chemin_change' => $nouveau !== $cible && (string) ($carte[0]['cible'] ?? '') === $nouveau];
    }

    /** @return array<string,mixed> */
    private function scenarioHierarchieFs(): array
    {
        $a = $this->deposer('Facture A', 'Facture');
        $b = $this->deposer('Reçu B', 'Facture');
        $hierarchie = $this->relations->hierarchie($a);

        return ['dossier_du_chemin' => (string) $hierarchie['dossier'] === dirname($a),
            'piece_soeur' => in_array($b, array_column($hierarchie['pieces'], 'chemin'), true),
            'enfants_fs' => count($hierarchie['enfants'])];
    }

    /** Dépose un document du fonds ; rend son chemin relatif. */
    private function deposer(string $titre, string $type): string
    {
        return $this->deposerAvecId($titre, $type)[1];
    }

    /**
     * Dépose un document du fonds ; rend [identifiant, chemin relatif].
     *
     * @return array{0:int, 1:string}
     */
    private function deposerAvecId(string $titre, string $type): array
    {
        $nom = strtolower(str_replace([' ', 'è', 'é', 'î'], ['-', 'e', 'e', 'i'], $titre)) . '-' . bin2hex(random_bytes(3)) . '.pdf';
        $id = $this->depot->deposer($this->pieceADeposer($nom, 'contenu ' . $titre), [
            'titre' => $titre, 'type' => $type, 'date' => '2026-09-01',
        ]);

        return [$id, (string) $this->depot->fiche($id)['chemin_relatif']];
    }
}

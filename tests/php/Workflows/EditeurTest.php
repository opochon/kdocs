<?php

declare(strict_types=1);

namespace Ged\Tests\Workflows;

use Ged\Workflows\Circuits;
use Ged\Workflows\Editeur;
use Ged\Workflows\Gabarits;
use Ged\Workflows\Regles;
use PHPUnit\Framework\TestCase;

/**
 * Persistance de l'éditeur : le graphe enregistré se relit à l'identique, les états/transitions et les
 * règles du moteur sont bien posés, et rien n'est jamais supprimé (une règle qui quitte le dessin passe
 * en veille).
 */
final class EditeurTest extends TestCase
{
    use SupportWorkflows;

    protected function setUp(): void
    {
        $this->setUpWorkflows();
    }

    protected function tearDown(): void
    {
        $this->tearDownWorkflows();
    }

    public function testGabaritOuvertDansLEditeurPuisModifieEtRelu(): void
    {
        Gabarits::installer($this->pdo, Gabarits::FACTURE_A_SEUIL);
        $circuitId = (int) (new Circuits($this->pdo))->parGabarit(Gabarits::FACTURE_A_SEUIL)['id'];
        $editeur = new Editeur($this->pdo);

        $graphe = $editeur->graphe($circuitId);
        self::assertNotNull($graphe);
        self::assertNotEmpty($graphe['noeuds'], 'le gabarit s’ouvre dans l’éditeur');
        self::assertNotEmpty(array_filter($graphe['noeuds'], static fn (array $n): bool => $n['genre'] === 'etat'), 'ses états sont dessinés');

        // 1. Changer le seuil de la règle « direction » (montant ≥ 10000).
        $change = false;
        foreach ($graphe['noeuds'] as $i => $noeud) {
            if ($noeud['genre'] === 'condition' && ($noeud['config']['champ'] ?? '') === 'montant'
                && ($noeud['config']['valeur'] ?? null) == 10000) {
                $graphe['noeuds'][$i]['config']['valeur'] = 5000;
                $change = true;
            }
        }
        self::assertTrue($change, 'la condition de seuil est trouvée dans le dessin');

        // 2. Ajouter un état, 3. relier une transition depuis ce nouvel état.
        $graphe['noeuds'][] = ['id' => 'neuf', 'genre' => 'etat', 'type' => 'etat', 'nom' => 'Vérifié',
            'x' => 900, 'y' => 40, 'config' => ['nom' => 'verifie', 'libelle' => 'Vérifié', 'initial' => false, 'final' => false]];
        $versCible = null;
        foreach ($graphe['noeuds'] as $noeud) {
            if ($noeud['genre'] === 'etat' && ($noeud['config']['nom'] ?? '') === 'valide') {
                $versCible = $noeud['id'];
            }
        }
        self::assertNotNull($versCible);
        $graphe['liens'][] = ['id' => 'l-neuf', 'de' => 'neuf', 'vers' => $versCible, 'sortie' => 'default',
            'libelle' => 'Vérifier', 'permission' => 'workflow.transition.valider'];

        $rapport = $editeur->enregistrer($circuitId, $graphe);
        self::assertSame($circuitId, $rapport['circuit_id']);
        self::assertSame(2, $rapport['regles'], 'les deux règles du gabarit sont mises à jour, pas dupliquées');

        // 4. Rouvrir : les modifications sont présentes.
        $relu = $editeur->graphe($circuitId);
        self::assertNotNull($relu);
        $seuil = null;
        $etatNeuf = null;
        foreach ($relu['noeuds'] as $noeud) {
            if ($noeud['genre'] === 'condition' && ($noeud['config']['champ'] ?? '') === 'montant'
                && ($noeud['config']['operateur'] ?? '') === 'superieur_ou_egal') {
                $seuil = $noeud['config']['valeur'];
            }
            if ($noeud['genre'] === 'etat' && ($noeud['config']['nom'] ?? '') === 'verifie') {
                $etatNeuf = $noeud;
            }
        }
        self::assertSame(5000, $seuil, 'le seuil modifié est relu');
        self::assertNotNull($etatNeuf, 'l’état ajouté est relu');
        self::assertContains('neuf', array_merge(...array_map(static fn (array $l): array => [$l['de'], $l['vers']], $relu['liens'])),
            'la transition ajoutée est relue');

        // Le modèle du moteur porte bien le nouvel état et sa transition.
        $circuit = (new \Ged\Workflows\Circuits($this->pdo))->parId($circuitId);
        self::assertContains('verifie', array_column($circuit['etats'], 'nom'));
        self::assertContains(['de' => 'verifie', 'vers' => 'valide', 'libelle' => 'Vérifier', 'permission' => 'workflow.transition.valider'],
            $circuit['transitions']);
    }

    public function testUneRegleQuiQuitteLeDessinPasseEnVeilleSansEtreSupprimee(): void
    {
        Gabarits::installer($this->pdo, Gabarits::FACTURE_A_SEUIL);
        $circuitId = (int) (new \Ged\Workflows\Circuits($this->pdo))->parGabarit(Gabarits::FACTURE_A_SEUIL)['id'];
        $editeur = new Editeur($this->pdo);
        $regles = new Regles($this->pdo);

        $avant = count($regles->toutes());
        $graphe = $editeur->graphe($circuitId);
        // On retire tous les nœuds de règle : aucun dessin de règle ne reste.
        $graphe['noeuds'] = array_values(array_filter($graphe['noeuds'], static fn (array $n): bool => $n['genre'] === 'etat'));
        $graphe['liens'] = array_values(array_filter($graphe['liens'], static function (array $l) use ($graphe): bool {
            $genres = [];
            foreach ($graphe['noeuds'] as $n) {
                $genres[$n['id']] = $n['genre'];
            }

            return ($genres[$l['de']] ?? null) === 'etat' && ($genres[$l['vers']] ?? null) === 'etat';
        }));

        $rapport = $editeur->enregistrer($circuitId, $graphe);
        self::assertSame(0, $rapport['regles']);
        self::assertSame(2, $rapport['en_veille'], 'les deux règles du gabarit passent en veille');
        self::assertCount($avant, $regles->toutes(), 'aucune règle n’est supprimée');
        foreach ($regles->toutes() as $regle) {
            if ($regle['gabarit'] === Gabarits::FACTURE_A_SEUIL) {
                self::assertFalse($regle['actif'], 'la règle détachée est en veille');
            }
        }
    }

    public function testLeGabaritContratAEcheanceSOuvreDansLEditeur(): void
    {
        Gabarits::installer($this->pdo, Gabarits::CONTRAT_A_ECHEANCE);
        $circuitId = (int) (new Circuits($this->pdo))->parGabarit(Gabarits::CONTRAT_A_ECHEANCE)['id'];

        $graphe = (new Editeur($this->pdo))->graphe($circuitId);

        self::assertNotNull($graphe);
        self::assertSame(
            ['actif', 'a_renouveler', 'resilie'],
            array_column(array_map(static fn (array $n): array => $n['config'], array_filter(
                $graphe['noeuds'],
                static fn (array $n): bool => $n['genre'] === 'etat'
            )), 'nom'),
            'les trois états du contrat sont dessinés'
        );
        self::assertNotEmpty(
            array_filter($graphe['noeuds'], static fn (array $n): bool => $n['genre'] === 'declencheur' && ($n['config']['evenement'] ?? '') === 'echeance'),
            'la règle de rappel à échéance est dessinée'
        );
    }
}

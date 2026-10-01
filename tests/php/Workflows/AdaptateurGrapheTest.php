<?php

declare(strict_types=1);

namespace Ged\Tests\Workflows;

use Ged\Workflows\AdaptateurGraphe;
use Ged\Workflows\ErreurWorkflow;
use PHPUnit\Framework\TestCase;

/**
 * Oracle de l'adaptateur graphique : le dessin (nœuds + liaisons) se projette sur le modèle du moteur
 * (circuit = états + transitions, règles = déclencheur + conditions + actions) et revient IDENTIQUE.
 * Le format de nœuds de GEDv1 n'entre jamais dans le modèle.
 */
final class AdaptateurGrapheTest extends TestCase
{
    /** @return array<string, mixed> un graphe canonique représentatif (facture à seuil + circuit). */
    private function graphe(): array
    {
        return [
            'nom' => 'Circuit de contrôle',
            'gabarit' => null,
            'actif' => true,
            'noeuds' => [
                ['id' => 'n1', 'genre' => 'declencheur', 'type' => 'declencheur', 'nom' => 'Facture reçue → direction',
                    'x' => 40, 'y' => 40, 'config' => ['evenement' => 'document_depose']],
                ['id' => 'n2', 'genre' => 'condition', 'type' => 'condition_type', 'nom' => 'Type = Facture',
                    'x' => 240, 'y' => 40, 'config' => ['champ' => 'type', 'operateur' => 'egal', 'valeur' => 'Facture']],
                ['id' => 'n3', 'genre' => 'condition', 'type' => 'condition_montant', 'nom' => 'Montant ≥ 10 000',
                    'x' => 440, 'y' => 40, 'config' => ['champ' => 'montant', 'operateur' => 'superieur_ou_egal', 'valeur' => 10000]],
                ['id' => 'n4', 'genre' => 'action', 'type' => 'action_demander_validation', 'nom' => 'Validation direction',
                    'x' => 640, 'y' => 40, 'config' => ['type' => 'demander_validation', 'circuit' => 'Circuit de contrôle', 'vers' => 'a_valider', 'assigne' => 'direction']],
                ['id' => 'n5', 'genre' => 'action', 'type' => 'action_notifier', 'nom' => 'Notifier le demandeur',
                    'x' => 840, 'y' => 40, 'config' => ['type' => 'notifier', 'destinataire' => 'responsable', 'message' => 'Montant {montant}']],
                ['id' => 'e1', 'genre' => 'etat', 'type' => 'etat', 'nom' => 'Reçu',
                    'x' => 40, 'y' => 260, 'config' => ['nom' => 'recu', 'libelle' => 'Reçu', 'initial' => true, 'final' => false]],
                ['id' => 'e2', 'genre' => 'etat', 'type' => 'etat', 'nom' => 'À valider',
                    'x' => 240, 'y' => 260, 'config' => ['nom' => 'a_valider', 'libelle' => 'À valider', 'initial' => false, 'final' => false]],
                ['id' => 'e3', 'genre' => 'etat', 'type' => 'etat', 'nom' => 'Validé',
                    'x' => 440, 'y' => 260, 'config' => ['nom' => 'valide', 'libelle' => 'Validé', 'initial' => false, 'final' => true]],
            ],
            'liens' => [
                ['id' => 'l1', 'de' => 'n1', 'vers' => 'n2', 'sortie' => 'default'],
                ['id' => 'l2', 'de' => 'n2', 'vers' => 'n3', 'sortie' => 'default'],
                ['id' => 'l3', 'de' => 'n3', 'vers' => 'n4', 'sortie' => 'default'],
                ['id' => 'l4', 'de' => 'n4', 'vers' => 'n5', 'sortie' => 'default'],
                ['id' => 'l5', 'de' => 'e1', 'vers' => 'e2', 'sortie' => 'default', 'libelle' => 'Soumettre', 'permission' => 'workflow.transition.soumettre'],
                ['id' => 'l6', 'de' => 'e2', 'vers' => 'e3', 'sortie' => 'default', 'libelle' => 'Valider', 'permission' => 'workflow.transition.valider'],
            ],
        ];
    }

    public function testAllerRetourGrapheCircuitGrapheIdentique(): void
    {
        $graphe = $this->graphe();
        $modele = AdaptateurGraphe::versModele($graphe);

        $retour = AdaptateurGraphe::versGraphe($modele['circuit'], $modele['regles']);

        self::assertSame($graphe, $retour, 'graphe → circuit/règles → graphe : identique');
    }

    public function testLeModelePorteLeCircuitEtLesRegles(): void
    {
        $modele = AdaptateurGraphe::versModele($this->graphe());

        self::assertSame('Circuit de contrôle', $modele['circuit']['nom']);
        self::assertSame(
            [
                ['nom' => 'recu', 'libelle' => 'Reçu', 'initial' => true, 'final' => false],
                ['nom' => 'a_valider', 'libelle' => 'À valider', 'initial' => false, 'final' => false],
                ['nom' => 'valide', 'libelle' => 'Validé', 'initial' => false, 'final' => true],
            ],
            $modele['circuit']['etats']
        );
        self::assertSame(
            [
                ['de' => 'recu', 'vers' => 'a_valider', 'libelle' => 'Soumettre', 'permission' => 'workflow.transition.soumettre'],
                ['de' => 'a_valider', 'vers' => 'valide', 'libelle' => 'Valider', 'permission' => 'workflow.transition.valider'],
            ],
            $modele['circuit']['transitions']
        );

        self::assertCount(1, $modele['regles']);
        $regle = $modele['regles'][0];
        self::assertSame('Facture reçue → direction', $regle['nom']);
        self::assertSame('document_depose', $regle['declencheur']);
        self::assertSame(
            [
                ['champ' => 'type', 'operateur' => 'egal', 'valeur' => 'Facture'],
                ['champ' => 'montant', 'operateur' => 'superieur_ou_egal', 'valeur' => 10000],
            ],
            $regle['conditions']
        );
        self::assertSame(
            [
                ['type' => 'demander_validation', 'circuit' => 'Circuit de contrôle', 'vers' => 'a_valider', 'assigne' => 'direction'],
                ['type' => 'notifier', 'destinataire' => 'responsable', 'message' => 'Montant {montant}'],
            ],
            $regle['actions']
        );
    }

    public function testUnCircuitSansDispositionRevientEnGraphePuisSeReEnregistreIdentique(): void
    {
        $circuit = [
            'nom' => 'Contrat à échéance', 'gabarit' => 'contrat_a_echeance', 'actif' => true,
            'etats' => [
                ['nom' => 'actif', 'libelle' => 'Actif', 'initial' => true, 'final' => false],
                ['nom' => 'a_renouveler', 'libelle' => 'À renouveler', 'initial' => false, 'final' => false],
            ],
            'transitions' => [
                ['de' => 'actif', 'vers' => 'a_renouveler', 'libelle' => 'À renouveler', 'permission' => 'workflow.transition.renouveler'],
            ],
        ];
        $regles = [[
            'nom' => 'Contrat à échéance (J-90)', 'declencheur' => 'echeance', 'actif' => true, 'gabarit' => 'contrat_a_echeance',
            'conditions' => [
                ['champ' => 'type', 'operateur' => 'egal', 'valeur' => 'Contrat'],
                ['champ' => 'delai_echeance_jours', 'operateur' => 'inferieur_ou_egal', 'valeur' => 90],
            ],
            'actions' => [['type' => 'notifier', 'destinataire' => 'responsable', 'message' => 'Échéance {jours}']],
        ]];

        $graphe = AdaptateurGraphe::versGraphe($circuit, $regles);
        self::assertNotEmpty($graphe['noeuds']);
        // 3 liaisons de la chaîne de règle (déclencheur → 2 conditions → action) + 1 transition d'état.
        self::assertCount(4, $graphe['liens']);

        $modele = AdaptateurGraphe::versModele($graphe);
        self::assertSame($circuit['etats'], $modele['circuit']['etats']);
        self::assertSame($circuit['transitions'], $modele['circuit']['transitions']);
        self::assertSame($regles[0]['conditions'], $modele['regles'][0]['conditions']);
        self::assertSame($regles[0]['actions'], $modele['regles'][0]['actions']);
        self::assertSame($regles[0]['nom'], $modele['regles'][0]['nom']);

        self::assertSame($graphe, AdaptateurGraphe::versGraphe($modele['circuit'], $modele['regles']));
    }

    public function testUnBranchementSurUneRegleEstRefuseEtNomme(): void
    {
        $graphe = $this->graphe();
        // Le nœud de condition n2 reçoit une seconde sortie : le modèle « conditions = ET » ne sait pas le porter.
        $graphe['liens'][] = ['id' => 'l7', 'de' => 'n2', 'vers' => 'n5', 'sortie' => 'default'];

        $this->expectException(ErreurWorkflow::class);
        $this->expectExceptionMessageMatches('/branchement/');
        AdaptateurGraphe::versModele($graphe);
    }

    public function testUnNoeudDEtatNeSeReliePasAUneRegle(): void
    {
        $graphe = $this->graphe();
        $graphe['liens'][] = ['id' => 'l8', 'de' => 'n4', 'vers' => 'e2', 'sortie' => 'default'];

        $this->expectException(ErreurWorkflow::class);
        AdaptateurGraphe::versModele($graphe);
    }

    public function testUnNoeudSansGenreConnuEstRefuse(): void
    {
        $graphe = $this->graphe();
        $graphe['noeuds'][] = ['id' => 'x1', 'genre' => 'process_ocr', 'type' => 'process_ocr', 'nom' => 'OCR', 'x' => 0, 'y' => 0, 'config' => []];

        $this->expectException(ErreurWorkflow::class);
        AdaptateurGraphe::versModele($graphe);
    }
}

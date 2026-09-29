<?php

declare(strict_types=1);

namespace Ged\Tests\Workflows;

use Ged\Workflows\Conditions;
use Ged\Workflows\Ecouteur;
use Ged\Workflows\Gabarits;
use Ged\Workflows\Journal;
use Ged\Workflows\Regles;
use PHPUnit\Framework\TestCase;

/**
 * Portes 2 et 4 : l'essai à blanc sur un vrai document du fonds rend le même résultat que l'exécution
 * réelle, en n'écrivant RIEN (empreintes du fonds et de la base) ; une règle en erreur est signalée
 * sans jamais bloquer l'ingestion ; l'horloge est injectable.
 */
final class MoteurTest extends TestCase
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

    public function testEssaiBlancZeroEcritureEtMemeResultatQueLExecutionReelle(): void
    {
        Gabarits::installer($this->pdo, Gabarits::FACTURE_A_SEUIL);
        // Une règle de secteur en plus : le rappel du gabarit écrit dans l'index, la preuve couvre donc les deux.
        (new Regles($this->pdo))->creer([
            'nom' => 'Marquer la facture à contrôler',
            'declencheur' => 'document_depose',
            'conditions' => [['champ' => 'type', 'operateur' => 'egal', 'valeur' => 'Facture']],
            'actions' => [['type' => 'poser', 'champ' => 'statut', 'valeur' => 'a_controler']],
            'actif' => true,
        ]);

        $idEssai = $this->deposer('facture-essai.pdf',
            ['titre' => 'Facture Essai', 'type' => 'Facture', 'emetteur' => 'Atelier Fictif', 'date' => '2026-03-01', 'montant' => 12000.0]);
        $idReel = $this->deposer('facture-reelle.pdf',
            ['titre' => 'Facture Réelle', 'type' => 'Facture', 'emetteur' => 'Atelier Fictif', 'date' => '2026-03-01', 'montant' => 12000.0]);

        $fonds = $this->fonds();
        $fondsAvant = $this->empreinteFonds();
        $baseAvant = $this->empreinteBase();

        // ESSAI À BLANC sur un VRAI document du fonds (lu depuis son .meta.json).
        $essai = $this->moteur()->essayer('document_depose', $fonds->parId($idEssai));

        self::assertSame($fondsAvant, $this->empreinteFonds(), 'essai à blanc : le fonds n’est pas touché');
        self::assertSame($baseAvant, $this->empreinteBase(), 'essai à blanc : la base n’est pas touchée');
        self::assertNotEmpty($essai['etapes'], 'l’essai montre ce qui se passerait');
        self::assertSame('direction', $this->assigneDemarrage($essai));

        // EXÉCUTION RÉELLE sur une fiche rigoureusement identique (autre document).
        $reel = $this->moteur()->executer('document_depose', $fonds->parId($idReel), 'testeur@gedv2.test');

        self::assertSame($essai['regles'], $reel['regles'], 'même décision de règles');
        self::assertSame($essai['etapes'], $reel['etapes'], 'même plan d’actions');
        self::assertSame($essai['erreurs'], $reel['erreurs'], 'mêmes erreurs');
        self::assertNotSame($baseAvant, $this->empreinteBase(), 'l’exécution réelle, elle, écrit (base)');
        self::assertNotSame($fondsAvant, $this->empreinteFonds(), 'l’exécution réelle, elle, écrit (fonds)');
    }

    public function testUneRegleEnErreurEstSignaleeSansBloquerLesAutres(): void
    {
        $regles = new Regles($this->pdo);
        $regles->creer([
            'nom' => 'Règle cassée (circuit absent)',
            'declencheur' => 'document_depose',
            'conditions' => [],
            'actions' => [['type' => 'demander_validation', 'circuit' => 'circuit_absent', 'assigne' => 'direction']],
            'actif' => true,
        ]);
        $regles->creer([
            'nom' => 'Règle valide (notification)',
            'declencheur' => 'document_depose',
            'conditions' => [],
            'actions' => [['type' => 'notifier', 'destinataire' => 'responsable', 'message' => 'Vu {titre}']],
            'actif' => true,
        ]);

        $id = $this->deposer('lettre.pdf', ['titre' => 'Lettre fictive', 'type' => 'Correspondance']);
        $ecouteur = new Ecouteur($this->moteur(), new Journal($this->pdo));

        $res = $ecouteur->recevoir('document_depose', $id, $this->fonds()->parId($id));

        self::assertNotEmpty($res['erreurs'], 'la règle cassée est signalée');
        self::assertStringContainsString('circuit_absent', $res['erreurs'][0]['cause']);
        $journal = new Journal($this->pdo);
        self::assertNotEmpty($journal->parGenre('erreur'), 'l’erreur est journalisée');
        self::assertNotEmpty($journal->parGenre('notification'), 'la règle valide a bien agi');
        self::assertFileExists($this->fonds()->parId($id)['chemin'], 'le document continue sa vie');
    }

    public function testUneErreurDActionNeBloquePasLeResteDuPlan(): void
    {
        // Le dépôt refusera un document inconnu de l'index : c'est une erreur d'ACTION, en pleine application.
        $regles = new Regles($this->pdo);
        $regles->creer([
            'nom' => 'Pose impossible',
            'declencheur' => 'document_depose',
            'conditions' => [],
            'actions' => [['type' => 'poser', 'champ' => 'statut', 'valeur' => 'a_controler']],
            'actif' => true,
        ]);
        $regles->creer([
            'nom' => 'Notification utile',
            'declencheur' => 'document_depose',
            'conditions' => [],
            'actions' => [['type' => 'notifier', 'destinataire' => 'responsable', 'message' => 'Reçu {titre}']],
            'actif' => true,
        ]);

        $ecouteur = new Ecouteur($this->moteur(), new Journal($this->pdo));
        $res = $ecouteur->recevoir('document_depose', 999999, $this->fiche(999999));

        self::assertNotEmpty($res['erreurs'], 'l’erreur d’action est signalée');
        self::assertNotEmpty((new Journal($this->pdo))->parGenre('notification'), 'le reste du plan s’applique');
    }

    public function testLeMoteurNeLeveJamaisQuandLesTablesManquent(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS workflow_journal, workflow_instances, workflow_definitions');
        $ecouteur = new Ecouteur($this->moteur(), new Journal($this->pdo));

        $res = $ecouteur->recevoir('document_depose', 1, $this->fiche(1));

        self::assertIsArray($res);
        self::assertArrayHasKey('erreurs', $res);
        self::assertNotEmpty($res['erreurs'], 'l’ingestion n’est jamais interrompue par le secteur Workflows');

        \Ged\Workflows\Schema::installer($this->pdo);   // remise en état pour la suite
    }

    public function testHorlogeFigeeInjecteeHorodateLeJournal(): void
    {
        self::assertSame('2026-04-01 08:00:00', $this->horloge->maintenant()->format('Y-m-d H:i:s'));
        $this->horloge->avancer('+1 day');
        self::assertSame('2026-04-02 08:00:00', $this->horloge->maintenant()->format('Y-m-d H:i:s'));

        (new Regles($this->pdo))->creer([
            'nom' => 'Trace', 'declencheur' => 'document_depose', 'conditions' => [],
            'actions' => [['type' => 'notifier', 'destinataire' => 'responsable', 'message' => 'x']], 'actif' => true,
        ]);
        $this->moteur()->executer('document_depose', $this->fiche(7), 'testeur@gedv2.test');

        $entree = (new Journal($this->pdo))->pourDocument(7)[0];
        self::assertSame('2026-04-02 08:00:00', $entree['horodatage'], 'l’horodatage vient de l’horloge injectée');
    }

    public function testConditionsEvalueesSurFiche(): void
    {
        $contexte = $this->fiche(1, ['montant' => 1500.0, 'etiquettes' => ['urgent']]);

        self::assertTrue(Conditions::satisfaites([
            ['champ' => 'type', 'operateur' => 'egal', 'valeur' => 'Facture'],
            ['champ' => 'montant', 'operateur' => 'superieur', 'valeur' => 1000],
            ['champ' => 'etiquette', 'operateur' => 'dans', 'valeur' => 'urgent'],
        ], $contexte));
        self::assertFalse(Conditions::satisfaites([['champ' => 'montant', 'operateur' => 'superieur', 'valeur' => 9999]], $contexte));
        self::assertFalse(Conditions::satisfaites([['champ' => 'type', 'operateur' => 'egal', 'valeur' => 'Contrat']], $contexte));
    }

    /** @param array<string, mixed> $resultat */
    private function assigneDemarrage(array $resultat): ?string
    {
        foreach ($resultat['etapes'] as $etape) {
            if ($etape['type'] === 'demarrer') {
                return (string) ($etape['assigne'] ?? '');
            }
        }

        return null;
    }
}

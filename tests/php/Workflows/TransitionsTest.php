<?php

declare(strict_types=1);

namespace Ged\Tests\Workflows;

use Ged\Workflows\Circuits;
use Ged\Workflows\ErreurWorkflow;
use Ged\Workflows\Gabarits;
use Ged\Workflows\Journal;
use PHPUnit\Framework\TestCase;

/**
 * Porte 3 : chaque transition est tracée (qui, quand, depuis quel état) et refusée sans le droit
 * de la transition ; l'état ne bouge jamais si le droit manque.
 */
final class TransitionsTest extends TestCase
{
    use SupportWorkflows;

    private int $documentId;
    private int $circuitId;

    protected function setUp(): void
    {
        $this->setUpWorkflows();
        Gabarits::installer($this->pdo, Gabarits::FACTURE_A_SEUIL);
        $this->circuitId = (int) (new Circuits($this->pdo))->parGabarit(Gabarits::FACTURE_A_SEUIL)['id'];
        $this->documentId = $this->deposer('facture-a-valider.pdf',
            ['titre' => 'Facture à valider', 'type' => 'Facture', 'emetteur' => 'Atelier Fictif', 'date' => '2026-03-01', 'montant' => 5000.0]);
        // Un dépôt démarre la validation : l'instance est en « a_valider », assignée à comptabilité.
        $this->moteur()->executer('document_depose', $this->fonds()->parId($this->documentId), 'ingestion');
    }

    protected function tearDown(): void
    {
        $this->tearDownWorkflows();
    }

    public function testRefusSansLeDroitLaTransitionEstNommeeEtTracee(): void
    {
        $moteur = $this->moteur();

        try {
            $moteur->transitionner($this->documentId, 'refuse', 'olivier', ['workflow.transition.valider']);
            self::fail('la transition devait être refusée sans le droit « refuser »');
        } catch (ErreurWorkflow $e) {
            self::assertStringContainsString('workflow.transition.refuser', $e->getMessage(), 'le droit manquant est nommé');
            self::assertStringContainsString('refusée', $e->getMessage());
        }

        self::assertSame('a_valider', $this->etat(), 'un refus ne change pas l’état');
        $refus = (new Journal($this->pdo))->parGenre('refus');
        self::assertNotEmpty($refus, 'le refus est journalisé');
        self::assertSame('a_valider', $refus[0]['de_etat']);
        self::assertSame('refuse', $refus[0]['vers_etat']);
        self::assertSame('olivier', $refus[0]['auteur']);
    }

    public function testTransitionAutoriseeTraceQuiQuandEtDepuisQuelEtat(): void
    {
        $this->moteur()->transitionner($this->documentId, 'valide', 'olivier', ['workflow.transition.valider']);

        self::assertSame('valide', $this->etat());

        $trace = (new Journal($this->pdo))->parGenre('transition');
        self::assertNotEmpty($trace);
        self::assertSame('olivier', $trace[0]['auteur']);
        self::assertSame('2026-04-01 08:00:00', $trace[0]['horodatage'], 'l’horloge injectée date la trace');
        self::assertSame('a_valider', $trace[0]['de_etat'], 'depuis quel état');
        self::assertSame('valide', $trace[0]['vers_etat']);
        self::assertSame($this->documentId, (int) $trace[0]['document_id']);
    }

    public function testTransitionSansInstanceEstRefuseeNommee(): void
    {
        try {
            $this->moteur()->transitionner(999999, 'valide', 'olivier', ['workflow.transition.valider']);
            self::fail('aucune transition ne doit s’appliquer à un document hors circuit');
        } catch (ErreurWorkflow $e) {
            self::assertStringContainsString('aucune transition', $e->getMessage());
        }
    }

    private function etat(): string
    {
        return (string) (new Circuits($this->pdo))->instance($this->circuitId, $this->documentId)['etat'];
    }
}

<?php

declare(strict_types=1);

namespace Ged\Tests\Workflows;

use Ged\Kernel;
use Ged\Securite\DroitsRoutes;
use Ged\Workflows\Circuits;
use Ged\Workflows\Gabarits;
use Ged\Workflows\Journal;
use Ged\Workflows\Regles;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Les routes du secteur : chacune déclare sa permission, l'essai à blanc par route n'écrit rien,
 * l'événement de dépôt agit, la transition sans droit est refusée et nommée.
 */
final class ModuleTest extends TestCase
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

    public function testChaqueRouteWorkflowDeclareSaPermission(): void
    {
        $routes = [
            ['POST', '/workflows/regles'],
            ['POST', '/workflows/regles/12/basculer'],
            ['POST', '/workflows/gabarits/facture_a_seuil'],
            ['POST', '/workflows/essai'],
            ['POST', '/workflows/evenements'],
            ['POST', '/workflows/transitions'],
            ['GET', '/workflows'],
        ];

        foreach ($routes as [$methode, $chemin]) {
            self::assertNotNull(DroitsRoutes::permissionPour($methode, $chemin), "permission déclarée : $methode $chemin");
        }
    }

    public function testGabaritParRoutePuisEcranListe(): void
    {
        [$code] = $this->requete('POST', '/workflows/gabarits/' . Gabarits::FACTURE_A_SEUIL);
        self::assertSame(303, $code);

        [$code, $html] = $this->requete('GET', '/workflows');
        self::assertSame(200, $code);
        self::assertStringContainsString('data-testid="circuit-', $html);
        self::assertStringContainsString('Facture à seuil', $html);
    }

    public function testEssaiParRouteNEcritRienEtAfficheLeResultat(): void
    {
        Gabarits::installer($this->pdo, Gabarits::FACTURE_A_SEUIL);
        $id = $this->deposer('facture-essai-route.pdf',
            ['titre' => 'Facture Essai Route', 'type' => 'Facture', 'emetteur' => 'Atelier Fictif', 'date' => '2026-03-01', 'montant' => 12000.0]);

        $fondsAvant = $this->empreinteFonds();
        $baseAvant = $this->empreinteBase();

        [$code, , $location] = $this->requete('POST', '/workflows/essai', ['document_id' => $id, 'declencheur' => 'document_depose']);
        self::assertSame(303, $code);
        self::assertStringContainsString('/workflows?', (string) $location);

        self::assertSame($fondsAvant, $this->empreinteFonds(), 'route d’essai : fonds intact');
        self::assertSame($baseAvant, $this->empreinteBase(), 'route d’essai : base intacte');

        [$code, $html] = $this->requete('GET', '/workflows?essai=' . $id . '&declencheur=document_depose');
        self::assertSame(200, $code);
        self::assertStringContainsString('data-testid="essai-resultat"', $html);
        self::assertStringContainsString('zero-ecriture', $html);
        self::assertStringContainsString('direction', $html);
    }

    public function testEvenementDeDepotParRouteJournaliseLAction(): void
    {
        (new Regles($this->pdo))->creer([
            'nom' => 'Notifier au dépôt', 'declencheur' => 'document_depose', 'conditions' => [],
            'actions' => [['type' => 'notifier', 'destinataire' => 'responsable', 'message' => 'Déposé : {titre}']],
            'actif' => true,
        ]);
        $id = $this->deposer('depot-route.pdf', ['titre' => 'Document déposé', 'type' => 'Correspondance']);

        [$code] = $this->requete('POST', '/workflows/evenements', ['document_id' => $id, 'declencheur' => 'document_depose']);
        self::assertSame(303, $code);

        $journal = (new Journal($this->pdo))->parGenre('notification');
        self::assertNotEmpty($journal, 'le dépôt a déclenché la règle');
        self::assertSame($id, (int) $journal[0]['document_id']);

        [$code, $html] = $this->requete('GET', '/workflows');
        self::assertSame(200, $code);
        self::assertStringContainsString('Déposé : Document déposé', $html, 'le journal est visible à l’écran');
    }

    public function testTransitionParRouteSansDroitEstRefuseeEtNommee(): void
    {
        Gabarits::installer($this->pdo, Gabarits::FACTURE_A_SEUIL);
        $id = $this->deposer('facture-transition.pdf',
            ['titre' => 'Facture transition', 'type' => 'Facture', 'date' => '2026-03-01', 'montant' => 500.0]);
        $this->requete('POST', '/workflows/evenements', ['document_id' => $id, 'declencheur' => 'document_depose']);

        // En SAPI cli, l'appel de test n'a pas de session : les droits sont donc vides.
        [$code, $html] = $this->requete('POST', '/workflows/transitions', ['document_id' => $id, 'vers' => 'valide']);

        self::assertSame(403, $code);
        self::assertStringContainsString('data-testid="erreur-cause"', $html);
        self::assertStringContainsString('refusée', $html);
        $circuitId = (int) (new Circuits($this->pdo))->parGabarit(Gabarits::FACTURE_A_SEUIL)['id'];
        self::assertSame('a_valider', (new Circuits($this->pdo))->instance($circuitId, $id)['etat'], 'l’état n’a pas bougé');
    }

    public function testEcranRepondMemeSansTablesDuSecteur(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS workflow_journal, workflow_instances, workflow_definitions');

        [$code, $html] = $this->requete('GET', '/workflows');

        self::assertSame(200, $code);
        self::assertStringContainsString('data-testid="ecran-workflows"', $html);
        self::assertStringContainsString('data-testid="workflows-non-installes"', $html);

        \Ged\Workflows\Schema::installer($this->pdo);
    }

    /**
     * @param array<string, mixed> $corps
     * @return array{int, string, string}
     */
    private function requete(string $methode, string $chemin, array $corps = []): array
    {
        $requete = (new ServerRequestFactory())->createServerRequest($methode, $chemin);
        if ($corps !== []) {
            $requete = $requete->withParsedBody($corps);
        }
        $reponse = Kernel::creer(dirname(__DIR__, 3))->handle($requete);

        return [$reponse->getStatusCode(), (string) $reponse->getBody(), $reponse->getHeaderLine('Location')];
    }
}

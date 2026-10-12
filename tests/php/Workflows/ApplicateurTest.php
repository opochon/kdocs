<?php

declare(strict_types=1);

namespace Ged\Tests\Workflows;

use Ged\Socle\DepotFichiers;
use Ged\Workflows\Action;
use Ged\Workflows\Applicateur;
use Ged\Workflows\CourrielFaux;
use Ged\Workflows\Declencheur;
use Ged\Workflows\DepotWorkflows;
use Ged\Workflows\Executions;
use Ged\Workflows\WebhookFaux;
use PHPUnit\Framework\TestCase;

/**
 * Exécution d'un workflow Paperless par le VRAI moteur, sur `gedv2_test` et un fonds de test : les
 * actions écrivent par le contrat `Depot` (le dossier fait foi) et CHAQUE exécution est tracée
 * (`WorkflowRun`, via `Executions`). Une action en erreur est isolée : les suivantes passent.
 */
final class ApplicateurTest extends TestCase
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

    /** @param list<array<string,mixed>> $actions @param list<array<string,mixed>> $triggers */
    private function workflow(array $triggers, array $actions): array
    {
        $depot = new DepotWorkflows($this->pdo);
        $id = $depot->creer(['nom' => 'Workflow ' . bin2hex(random_bytes(3)), 'ordre' => 0, 'actif' => true,
            'triggers' => $triggers, 'actions' => $actions]);
        $workflow = $depot->parId($id);
        self::assertNotNull($workflow);

        return $workflow;
    }

    private function applicateur(?CourrielFaux $courriel = null, ?WebhookFaux $webhook = null): Applicateur
    {
        return new Applicateur($this->pdo, new DepotFichiers($this->config), $this->horloge,
            $courriel ?? new CourrielFaux(), $webhook ?? new WebhookFaux());
    }

    public function testLeWorkflowSAppliqueParLeDepotEtTraceUneExecution(): void
    {
        $id = $this->deposer('facture-an-7001.pdf', ['titre' => 'Facture AN-7001', 'type' => 'Facture', 'date' => '2026-03-01']);
        $workflow = $this->workflow(
            [['type' => Declencheur::AJOUT, 'filter_filename' => '*facture*']],
            [['type' => Action::AFFECTATION, 'order' => 0, 'assign_title' => 'Facture traitée',
                'assign_tags' => ['traite'], 'assign_custom_fields' => ['IBAN'], 'assign_custom_fields_values' => ['IBAN' => 'CH93']]],
        );

        $fiche = Applicateur::fiche((new DepotFichiers($this->config))->fiche($id));
        $rapport = $this->applicateur()->appliquer($workflow, Declencheur::AJOUT, $fiche, 'testeur');

        self::assertTrue($rapport['declenche']);
        self::assertSame(1, $rapport['ecritures']);
        self::assertSame([], $rapport['erreurs']);

        $ligne = (new DepotFichiers($this->config))->fiche($id);
        self::assertNotNull($ligne);
        self::assertSame('Facture traitée', $ligne['titre']);
        self::assertContains('traite', (array) $ligne['etiquettes']);
        self::assertSame('CH93', $ligne['meta']['champs']['IBAN'] ?? null);

        $executions = new Executions($this->pdo);
        $runs = $executions->pourWorkflow((int) $workflow['id']);
        self::assertCount(1, $runs);
        self::assertSame(Declencheur::AJOUT, $runs[0]['type']);
        self::assertSame($id, $runs[0]['document_id']);
        self::assertNotNull($executions->derniere((int) $workflow['id'], $id));
    }

    public function testUnDeclencheurQuiNeCorrespondPasNExecuteRien(): void
    {
        $id = $this->deposer('note.pdf', ['titre' => 'Note', 'date' => '2026-03-01']);
        $workflow = $this->workflow(
            [['type' => Declencheur::AJOUT, 'filter_filename' => '*facture*']],
            [['type' => Action::AFFECTATION, 'assign_title' => 'Ne doit pas passer']],
        );

        $fiche = Applicateur::fiche((new DepotFichiers($this->config))->fiche($id));
        $rapport = $this->applicateur()->appliquer($workflow, Declencheur::AJOUT, $fiche, 'testeur');

        self::assertFalse($rapport['declenche']);
        self::assertSame('Note', (new DepotFichiers($this->config))->fiche($id)['titre']);
        self::assertSame([], (new Executions($this->pdo))->pourWorkflow((int) $workflow['id']));
    }

    public function testUneActionEnErreurNEmpechePasLesSuivantes(): void
    {
        $id = $this->deposer('facture-erreur.pdf', ['titre' => 'Facture', 'date' => '2026-03-01']);
        $workflow = $this->workflow(
            [['type' => Declencheur::AJOUT]],
            [
                ['type' => Action::WEBHOOK, 'order' => 0, 'webhook' => ['url' => '']],
                ['type' => Action::AFFECTATION, 'order' => 1, 'assign_title' => 'Malgré l’erreur'],
            ],
        );

        $fiche = Applicateur::fiche((new DepotFichiers($this->config))->fiche($id));
        $rapport = $this->applicateur()->appliquer($workflow, Declencheur::AJOUT, $fiche, 'testeur');

        self::assertTrue($rapport['declenche']);
        self::assertCount(1, $rapport['erreurs']);
        self::assertStringContainsString('URL vide', $rapport['erreurs'][0]['cause']);
        self::assertSame('Malgré l’erreur', (new DepotFichiers($this->config))->fiche($id)['titre']);
    }

    public function testLesActionsSontExecuteesDansLOrdreDeclare(): void
    {
        $id = $this->deposer('facture-ordre.pdf', ['titre' => 'Facture', 'date' => '2026-03-01']);
        $workflow = $this->workflow(
            [['type' => Declencheur::AJOUT]],
            [
                ['type' => Action::AFFECTATION, 'order' => 20, 'assign_title' => 'Deuxieme'],
                ['type' => Action::AFFECTATION, 'order' => 10, 'assign_title' => 'Premiere'],
            ],
        );

        $fiche = Applicateur::fiche((new DepotFichiers($this->config))->fiche($id));
        $this->applicateur()->appliquer($workflow, Declencheur::AJOUT, $fiche, 'testeur');

        self::assertSame('Deuxieme', (new DepotFichiers($this->config))->fiche($id)['titre']);
    }

    public function testLesActionsCourrielEtWebhookPassentParLesPortsInjectes(): void
    {
        $id = $this->deposer('facture-ports.pdf', ['titre' => 'Facture ports', 'date' => '2026-03-01']);
        $workflow = $this->workflow(
            [['type' => Declencheur::AJOUT]],
            [
                ['type' => Action::COURRIEL, 'order' => 0, 'email' => ['subject' => 'Doc {{title}}',
                    'body' => 'Voir {{filename}}', 'to' => 'test@exemple.test', 'include_document' => true]],
                ['type' => Action::WEBHOOK, 'order' => 1, 'webhook' => ['url' => 'http://exemple.test/hook',
                    'use_params' => true, 'params' => ['titre' => '{{title}}'], 'as_json' => true]],
            ],
        );

        $courriel = new CourrielFaux();
        $webhook = new WebhookFaux();
        $fiche = Applicateur::fiche((new DepotFichiers($this->config))->fiche($id));
        $this->applicateur($courriel, $webhook)->appliquer($workflow, Declencheur::AJOUT, $fiche, 'testeur');

        self::assertCount(1, $courriel->envoyes);
        self::assertSame('Doc Facture ports', $courriel->envoyes[0]['sujet']);
        self::assertCount(1, $webhook->appels);
        self::assertSame(['titre' => 'Facture ports'], $webhook->appels[0]['donnees']);
    }

    public function testLExecutionPlanifieeDependDuChampDeDateEtDeLOffset(): void
    {
        $id = $this->deposer('contrat.pdf', ['titre' => 'Contrat', 'date' => '2026-03-01']);
        $workflow = $this->workflow(
            [['type' => Declencheur::PLANIFIE, 'schedule_date_field' => Declencheur::DATE_CREATION, 'schedule_offset_days' => -90]],
            [['type' => Action::AFFECTATION, 'assign_title' => 'Rappel contrat']],
        );

        $fiche = Applicateur::fiche((new DepotFichiers($this->config))->fiche($id));
        $fiche['dates']['created'] = '2026-03-01';
        $rapport = $this->applicateur()->appliquer($workflow, Declencheur::PLANIFIE, $fiche, 'horloge');

        self::assertTrue($rapport['declenche']);
        self::assertSame('Rappel contrat', (new DepotFichiers($this->config))->fiche($id)['titre']);
    }

    public function testLesEnumerationsDeWorkflowRunEtDUneDefinitionSontPortees(): void
    {
        $id = $this->deposer('facture-modele.pdf', ['titre' => 'Facture', 'date' => '2026-03-01']);
        $workflow = $this->workflow([['type' => Declencheur::AJOUT]], [['type' => Action::AFFECTATION, 'assign_title' => 'X']]);

        self::assertSame($workflow['nom'], (new DepotWorkflows($this->pdo))->parId((int) $workflow['id'])['nom']);
        self::assertSame(0, $workflow['ordre']);
        self::assertTrue($workflow['actif']);
        self::assertIsArray($workflow['triggers']);
        self::assertIsArray($workflow['actions']);
        self::assertNotNull($id);
    }
}

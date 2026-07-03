<?php

declare(strict_types=1);

/**
 * GAP-030 — Module contrats + échéances.
 *
 * Tests hermétiques : SQLite en mémoire injecté dans ContractService réel
 * (pas de mock du service — le vrai SQL est exercé).
 * Le contrôleur est instancié via une sous-classe anonyme qui surcharge
 * makeService() pour fournir le service alimenté par le PDO de test.
 */

namespace Tests\Feature;

use KDocs\Apps\Contracts\Controllers\ContractsController;
use KDocs\Apps\Contracts\Services\ContractService;
use KDocs\Core\PluginRegistry;
use PDO;

class ContractsModuleTest extends ApiTestCase
{
    private PDO $pdo;

    /** Crée la table contracts en mémoire avant chaque test. */
    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('CREATE TABLE contracts (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            document_id  INTEGER  NULL,
            title        TEXT     NOT NULL,
            counterparty TEXT     NULL,
            start_date   TEXT     NULL,
            due_date     TEXT     NULL,
            notice_days  INTEGER  NOT NULL DEFAULT 30,
            status       TEXT     NOT NULL DEFAULT \'active\',
            created_at   TEXT     NOT NULL,
            updated_at   TEXT     NOT NULL
        )');
    }

    /** Retourne une sous-classe anonyme du contrôleur qui injecte le PDO de test. */
    private function makeController(): ContractsController
    {
        $pdo = $this->pdo;

        return new class($pdo) extends ContractsController {
            private PDO $db;

            public function __construct(PDO $db)
            {
                $this->db = $db;
            }

            protected function makeService(): ContractService
            {
                return new ContractService($this->db);
            }
        };
    }

    /** Insère un contrat minimal et retourne son id. */
    private function insertContract(string $title, ?string $dueDate, string $status = 'active'): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO contracts (title, due_date, status, notice_days, created_at, updated_at)
             VALUES (:title, :due_date, :status, 30, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':title'      => $title,
            ':due_date'   => $dueDate,
            ':status'     => $status,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /contracts — index
    // -------------------------------------------------------------------------

    public function testIndexReturns200WithContractList(): void
    {
        $this->insertContract('Contrat B', date('Y-m-d', strtotime('+180 days')));
        $this->insertContract('Contrat A', date('Y-m-d', strtotime('+30 days')));

        $controller = $this->makeController();
        $response   = $controller->index(
            $this->createMockRequest('GET'),
            $this->createMockResponse(),
            []
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->assertJsonResponse($response);

        $this->assertArrayHasKey('contracts', $data);
        $this->assertArrayHasKey('count', $data);
        $this->assertSame(2, $data['count']);
        $this->assertCount(2, $data['contracts']);
    }

    public function testIndexContractRowsHaveDueDateField(): void
    {
        $this->insertContract('Contrat Alpha', date('Y-m-d', strtotime('+60 days')));

        $controller = $this->makeController();
        $response   = $controller->index(
            $this->createMockRequest('GET'),
            $this->createMockResponse(),
            []
        );

        $data = $this->assertJsonResponse($response);
        $this->assertArrayHasKey('due_date', $data['contracts'][0]);
    }

    public function testIndexContractsSortedByDueDateAsc(): void
    {
        // Insérer dans l'ordre inverse pour vérifier le tri
        $this->insertContract('Contrat Lointain', date('Y-m-d', strtotime('+180 days')));
        $this->insertContract('Contrat Proche',   date('Y-m-d', strtotime('+30 days')));

        $controller = $this->makeController();
        $response   = $controller->index(
            $this->createMockRequest('GET'),
            $this->createMockResponse(),
            []
        );

        $data = $this->assertJsonResponse($response);
        // Le contrat le plus proche doit être en premier
        $this->assertSame('Contrat Proche',   $data['contracts'][0]['title']);
        $this->assertSame('Contrat Lointain', $data['contracts'][1]['title']);
    }

    // -------------------------------------------------------------------------
    // GET /contracts/upcoming — échéances à venir
    // -------------------------------------------------------------------------

    public function testUpcomingReturnsOnlyContractsInWindow(): void
    {
        $this->insertContract('Dans la fenêtre',  date('Y-m-d', strtotime('+30 days')));
        $this->insertContract('Hors fenêtre',     date('Y-m-d', strtotime('+180 days')));

        $controller = $this->makeController();
        $response   = $controller->upcoming(
            $this->createMockRequest('GET'),
            $this->createMockResponse(),
            []
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->assertJsonResponse($response);

        $this->assertSame(1, $data['count']);
        $this->assertCount(1, $data['contracts']);
        $this->assertSame('Dans la fenêtre', $data['contracts'][0]['title']);
    }

    public function testUpcomingExcludesContractsWithoutDueDate(): void
    {
        $this->insertContract('Sans échéance', null);
        $this->insertContract('Avec échéance', date('Y-m-d', strtotime('+10 days')));

        $controller = $this->makeController();
        $response   = $controller->upcoming(
            $this->createMockRequest('GET'),
            $this->createMockResponse(),
            []
        );

        $data = $this->assertJsonResponse($response);
        $this->assertSame(1, $data['count']);
        $this->assertSame('Avec échéance', $data['contracts'][0]['title']);
    }

    // -------------------------------------------------------------------------
    // POST /contracts — création
    // -------------------------------------------------------------------------

    public function testStoreCreatesContractAndReturns201(): void
    {
        $controller = $this->makeController();
        $response   = $controller->store(
            $this->createMockRequest('POST', [], ['title' => 'Nouveau contrat', 'due_date' => '2027-06-01']),
            $this->createMockResponse(),
            []
        );

        $this->assertSame(201, $response->getStatusCode());
        $data = $this->assertJsonResponse($response);
        $this->assertTrue($data['success']);
        $this->assertIsInt($data['id']);
        $this->assertGreaterThan(0, $data['id']);
    }

    public function testStoreContractIsRetrievableViaIndex(): void
    {
        $controller = $this->makeController();
        $controller->store(
            $this->createMockRequest('POST', [], ['title' => 'Contrat persisté', 'due_date' => '2027-01-01']),
            $this->createMockResponse(),
            []
        );

        $response = $controller->index(
            $this->createMockRequest('GET'),
            $this->createMockResponse(),
            []
        );

        $data = $this->assertJsonResponse($response);
        $this->assertSame(1, $data['count']);
        $this->assertSame('Contrat persisté', $data['contracts'][0]['title']);
    }

    public function testStoreMissingTitleReturns400(): void
    {
        $controller = $this->makeController();
        $response   = $controller->store(
            $this->createMockRequest('POST', [], ['due_date' => '2027-06-01']),
            $this->createMockResponse(),
            []
        );

        $this->assertSame(400, $response->getStatusCode());
        $data = $this->assertJsonResponse($response);
        $this->assertArrayHasKey('error', $data);
    }

    // -------------------------------------------------------------------------
    // Gating PluginRegistry
    // -------------------------------------------------------------------------

    public function testPluginRegistryDefaultsToDisabledForContracts(): void
    {
        // CONTRACTS_APP_ENABLED n'est pas posé dans phpunit.xml → doit être false
        $this->assertFalse(PluginRegistry::isEnabled('contracts'));
    }
}

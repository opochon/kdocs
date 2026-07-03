<?php

declare(strict_types=1);

/**
 * GAP-033 — Dossier RH digital.
 *
 * Tests hermétiques : SQLite en mémoire avec schéma minimal
 * (hr_employees, hr_employee_documents, documents).
 * Le contrôleur est instancié via une sous-classe anonyme qui surcharge
 * makeService() pour fournir le HrService alimenté par le PDO de test.
 */

namespace Tests\Feature;

use KDocs\Apps\Rh\Controllers\HrController;
use KDocs\Apps\Rh\Services\HrService;
use PDO;

class HrModuleTest extends ApiTestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Table employés
        $this->pdo->exec('CREATE TABLE hr_employees (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NULL,
            first_name TEXT    NOT NULL,
            last_name  TEXT    NOT NULL,
            email      TEXT    NULL,
            hired_at   TEXT    NULL,
            position   TEXT    NULL,
            created_at TEXT    NOT NULL
        )');

        // Table de liaison employé ↔ document
        $this->pdo->exec('CREATE TABLE hr_employee_documents (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id INTEGER NOT NULL,
            document_id INTEGER NOT NULL,
            category    TEXT    NOT NULL,
            created_at  TEXT    NOT NULL
        )');

        // Table documents minimale pour le LEFT JOIN des titres
        $this->pdo->exec('CREATE TABLE documents (
            id    INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT    NOT NULL
        )');
    }

    /** Retourne une sous-classe anonyme du contrôleur injectant le PDO de test. */
    private function makeController(): HrController
    {
        $pdo = $this->pdo;

        return new class($pdo) extends HrController {
            private PDO $db;

            public function __construct(PDO $db)
            {
                $this->db = $db;
            }

            protected function makeService(): HrService
            {
                return new HrService($this->db);
            }
        };
    }

    /** Insère un employé et retourne son id. */
    private function insertEmployee(string $firstName, string $lastName, string $position = 'Développeur'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO hr_employees (first_name, last_name, position, created_at)
             VALUES (:fn, :ln, :pos, :ca)'
        );
        $stmt->execute([
            ':fn'  => $firstName,
            ':ln'  => $lastName,
            ':pos' => $position,
            ':ca'  => date('Y-m-d H:i:s'),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Insère un document et retourne son id. */
    private function insertDocument(string $title): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO documents (title) VALUES (:title)');
        $stmt->execute([':title' => $title]);
        return (int) $this->pdo->lastInsertId();
    }

    // -------------------------------------------------------------------------
    // GET /rh/employees/{id} — show
    // -------------------------------------------------------------------------

    public function testShowReturns200WithEmployeeData(): void
    {
        $empId  = $this->insertEmployee('Alice', 'Dupont');
        $docId1 = $this->insertDocument('Contrat de travail');
        $docId2 = $this->insertDocument('Fiche de salaire janvier');

        $service = new HrService($this->pdo);
        $service->attachDocument($empId, $docId1, 'contrat');
        $service->attachDocument($empId, $docId2, 'salaire');

        $controller = $this->makeController();
        $response   = $controller->show(
            $this->createMockRequest('GET'),
            $this->createMockResponse(),
            ['id' => (string) $empId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->assertJsonResponse($response);

        $this->assertSame('Alice', $data['first_name']);
        $this->assertSame('Dupont', $data['last_name']);
    }

    public function testShowReturnsDocumentsGroupedByCategory(): void
    {
        $empId  = $this->insertEmployee('Bob', 'Martin');
        $docId1 = $this->insertDocument('Contrat CDI');
        $docId2 = $this->insertDocument('Certificat de formation');

        $service = new HrService($this->pdo);
        $service->attachDocument($empId, $docId1, 'contrat');
        $service->attachDocument($empId, $docId2, 'certificat');

        $controller = $this->makeController();
        $response   = $controller->show(
            $this->createMockRequest('GET'),
            $this->createMockResponse(),
            ['id' => (string) $empId]
        );

        $data = $this->assertJsonResponse($response);

        $this->assertArrayHasKey('documents', $data);
        $this->assertIsArray($data['documents']);

        // Deux catégories distinctes
        $this->assertArrayHasKey('contrat',    $data['documents']);
        $this->assertArrayHasKey('certificat', $data['documents']);

        // Contenu de chaque catégorie
        $this->assertCount(1, $data['documents']['contrat']);
        $this->assertSame('Contrat CDI', $data['documents']['contrat'][0]['title']);
        $this->assertCount(1, $data['documents']['certificat']);
        $this->assertSame('Certificat de formation', $data['documents']['certificat'][0]['title']);
    }

    public function testShowReturns404ForUnknownEmployee(): void
    {
        $controller = $this->makeController();
        $response   = $controller->show(
            $this->createMockRequest('GET'),
            $this->createMockResponse(),
            ['id' => '999']
        );

        $this->assertSame(404, $response->getStatusCode());
        $data = $this->assertJsonResponse($response);
        $this->assertArrayHasKey('error', $data);
    }

    public function testShowEmployeeWithNoDocumentsReturnsEmptyDocumentMap(): void
    {
        $empId = $this->insertEmployee('Claire', 'Lefebvre');

        $controller = $this->makeController();
        $response   = $controller->show(
            $this->createMockRequest('GET'),
            $this->createMockResponse(),
            ['id' => (string) $empId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->assertJsonResponse($response);
        $this->assertSame([], $data['documents']);
    }

    // -------------------------------------------------------------------------
    // GET /rh/employees — index
    // -------------------------------------------------------------------------

    public function testIndexReturnsAllEmployees(): void
    {
        $this->insertEmployee('Alice', 'Dupont');
        $this->insertEmployee('Bob',   'Martin');

        $controller = $this->makeController();
        $response   = $controller->index(
            $this->createMockRequest('GET'),
            $this->createMockResponse(),
            []
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->assertJsonResponse($response);

        $this->assertArrayHasKey('employees', $data);
        $this->assertArrayHasKey('count', $data);
        $this->assertSame(2, $data['count']);
        $this->assertCount(2, $data['employees']);
    }

    public function testIndexReturnsEmployeeFirstName(): void
    {
        $this->insertEmployee('David', 'Bernard', 'Chef de projet');

        $controller = $this->makeController();
        $response   = $controller->index(
            $this->createMockRequest('GET'),
            $this->createMockResponse(),
            []
        );

        $data      = $this->assertJsonResponse($response);
        $firstNames = array_column($data['employees'], 'first_name');
        $this->assertContains('David', $firstNames);
    }

    public function testIndexReturnsEmptyListWhenNoEmployees(): void
    {
        $controller = $this->makeController();
        $response   = $controller->index(
            $this->createMockRequest('GET'),
            $this->createMockResponse(),
            []
        );

        $data = $this->assertJsonResponse($response);
        $this->assertSame(0, $data['count']);
        $this->assertSame([], $data['employees']);
    }
}

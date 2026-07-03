<?php

declare(strict_types=1);

/**
 * GAP-042 — Portail client : lecture seule (aucun bouton d'édition/suppression).
 *
 * Hermétique : SQLite en mémoire injecté via sous-classe anonyme de PortalController.
 */

namespace Tests\Feature;

use KDocs\Apps\Portal\Controllers\PortalController;
use KDocs\Apps\Portal\Services\PortalService;

class PortalReadOnlyTest extends ApiTestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Schéma minimal
        $this->db->exec('CREATE TABLE correspondents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL
        )');

        $this->db->exec('CREATE TABLE documents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT,
            created_at TEXT,
            correspondent_id INTEGER,
            deleted_at TEXT
        )');

        // Insérer un correspondant et ses documents
        $this->db->exec("INSERT INTO correspondents (id, name) VALUES (1, 'ClientAlpha')");
        $this->db->exec("INSERT INTO documents (title, created_at, correspondent_id) VALUES ('Contrat 2026', '2026-01-15 10:00:00', 1)");
        $this->db->exec("INSERT INTO documents (title, created_at, correspondent_id) VALUES ('Facture mars', '2026-03-01 08:00:00', 1)");
    }

    // -------------------------------------------------------------------------
    // Helper : contrôleur avec service SQLite injecté
    // -------------------------------------------------------------------------

    private function makeController(): PortalController
    {
        $db = $this->db;

        return new class($db) extends PortalController {
            private \PDO $db;

            public function __construct(\PDO $db)
            {
                $this->db = $db;
            }

            protected function makePortalService(): PortalService
            {
                return new PortalService($this->db);
            }
        };
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function testShowRetourne200AvecDocuments(): void
    {
        $ctrl    = $this->makeController();
        $request = $this->createMockRequest('GET');
        $response = $this->createMockResponse();

        $result = $ctrl->show($request, $response, ['client' => 'ClientAlpha']);

        $this->assertSame(200, $result->getStatusCode());

        $body = $result->getBodyContents();
        $this->assertStringContainsString('Contrat 2026', $body);
        $this->assertStringContainsString('Facture mars', $body);
    }

    public function testHtmlNePasContenirActionsEdition(): void
    {
        $ctrl   = $this->makeController();
        $result = $ctrl->show(
            $this->createMockRequest('GET'),
            $this->createMockResponse(),
            ['client' => 'ClientAlpha']
        );

        $body = $result->getBodyContents();

        // Assertions négatives — lecture seule : aucune action d'édition/suppression
        $this->assertStringNotContainsStringIgnoringCase('edit', $body, 'Pas de mot "edit"');
        $this->assertStringNotContainsStringIgnoringCase('delete', $body, 'Pas de mot "delete"');
        $this->assertStringNotContainsStringIgnoringCase('supprimer', $body, 'Pas de bouton supprimer');
        $this->assertStringNotContainsStringIgnoringCase('modifier', $body, 'Pas de bouton modifier');
        $this->assertStringNotContainsStringIgnoringCase('<form', $body, 'Pas de formulaire POST');
    }

    public function testClientInconnuRetourne404(): void
    {
        $ctrl   = $this->makeController();
        $result = $ctrl->show(
            $this->createMockRequest('GET'),
            $this->createMockResponse(),
            ['client' => 'ClientInexistant']
        );

        $this->assertSame(404, $result->getStatusCode());
    }

    public function testDocumentsSupprimesSontExclus(): void
    {
        // Ajouter un document soft-deleted
        $this->db->exec(
            "INSERT INTO documents (title, created_at, correspondent_id, deleted_at)
             VALUES ('Document supprimé', '2026-04-01 00:00:00', 1, '2026-04-02 10:00:00')"
        );

        $ctrl   = $this->makeController();
        $result = $ctrl->show(
            $this->createMockRequest('GET'),
            $this->createMockResponse(),
            ['client' => 'ClientAlpha']
        );

        $body = $result->getBodyContents();
        $this->assertStringNotContainsString('Document supprimé', $body);
        // Les deux documents actifs restent affichés
        $this->assertStringContainsString('Contrat 2026', $body);
        $this->assertStringContainsString('Facture mars', $body);
    }
}

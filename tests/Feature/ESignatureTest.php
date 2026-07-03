<?php

declare(strict_types=1);

/**
 * GAP-043 — E-signature : sign() + verify() + contrôleur sign().
 *
 * Hermétique : SQLite en mémoire injecté.
 * Le contrôleur est testé via une sous-classe anonyme qui overrides
 * makeESignatureService() pour injecter le service avec SQLite.
 */

namespace Tests\Feature;

use KDocs\Controllers\Api\DocumentsApiController;
use KDocs\Services\Compliance\ESignatureService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response as SlimResponse;

class ESignatureTest extends TestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Schéma minimal
        $this->db->exec('CREATE TABLE documents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT,
            content TEXT,
            created_at TEXT
        )');

        $this->db->exec('CREATE TABLE document_signatures (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            document_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            content_hash VARCHAR(64) NOT NULL,
            signature TEXT NOT NULL,
            signed_at TEXT NOT NULL,
            UNIQUE (document_id, user_id)
        )');

        $this->db->exec('CREATE TABLE audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT NOT NULL,
            object_type TEXT NOT NULL,
            object_id INTEGER,
            object_name TEXT,
            changes TEXT,
            ip_address TEXT,
            user_agent TEXT,
            created_at TEXT NOT NULL
        )');

        // Document de test
        $this->db->exec(
            "INSERT INTO documents (id, title, content, created_at) VALUES (42, 'Rapport annuel', 'Contenu du rapport', '2026-01-01 00:00:00')"
        );
    }

    private function makeService(): ESignatureService
    {
        return new ESignatureService($this->db);
    }

    // -------------------------------------------------------------------------
    // Tests du service ESignatureService
    // -------------------------------------------------------------------------

    public function testSignCreeSignatureEtAuditLog(): void
    {
        $service = $this->makeService();
        $result  = $service->sign(42, 7);

        $this->assertArrayHasKey('signature', $result);
        $this->assertArrayHasKey('content_hash', $result);
        $this->assertArrayHasKey('signed_at', $result);
        $this->assertFalse($result['already_signed']);
        $this->assertNotEmpty($result['signature']);
        $this->assertSame(64, strlen($result['content_hash']), 'SHA-256 = 64 chars hex');

        // Vérifier que la signature est en base
        $sig = $this->db->query('SELECT * FROM document_signatures')->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($sig);
        $this->assertSame(42, (int) $sig['document_id']);
        $this->assertSame(7, (int) $sig['user_id']);
        $this->assertSame($result['content_hash'], $sig['content_hash']);
        $this->assertSame($result['signature'], $sig['signature']);

        // Vérifier la ligne audit_logs
        $log = $this->db->query("SELECT * FROM audit_logs WHERE action = 'document.signed'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($log, 'Une ligne audit_logs doit être créée');
        $this->assertSame(7, (int) $log['user_id']);
        $this->assertSame('document', $log['object_type']);
        $this->assertSame(42, (int) $log['object_id']);
    }

    public function testSignIdempotentMemeUser(): void
    {
        $service = $this->makeService();

        $first  = $service->sign(42, 7);
        $second = $service->sign(42, 7);

        $this->assertFalse($first['already_signed']);
        $this->assertTrue($second['already_signed'], 'Deuxième appel doit retourner already_signed=true');
        $this->assertSame($first['signature'],    $second['signature']);
        $this->assertSame($first['content_hash'], $second['content_hash']);
        $this->assertSame($first['signed_at'],    $second['signed_at']);

        // Une seule ligne dans document_signatures
        $count = (int) $this->db->query('SELECT COUNT(*) FROM document_signatures')->fetchColumn();
        $this->assertSame(1, $count);

        // Une seule ligne dans audit_logs (pas de doublon pour re-sign)
        $auditCount = (int) $this->db->query("SELECT COUNT(*) FROM audit_logs WHERE action='document.signed'")->fetchColumn();
        $this->assertSame(1, $auditCount);
    }

    public function testDeuxUtilisateursPeuventSigner(): void
    {
        $service = $this->makeService();
        $service->sign(42, 7);
        $service->sign(42, 99);

        $count = (int) $this->db->query('SELECT COUNT(*) FROM document_signatures')->fetchColumn();
        $this->assertSame(2, $count);
    }

    public function testSignDocumentInconnuLeveException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeService()->sign(999, 1);
    }

    public function testVerifyRetourneTrueSiIntact(): void
    {
        $service = $this->makeService();
        $service->sign(42, 7);

        $this->assertTrue($service->verify(42, 7));
    }

    public function testVerifyRetourneFalseApresModification(): void
    {
        $service = $this->makeService();
        $service->sign(42, 7);

        // Modifier le contenu du document
        $this->db->exec("UPDATE documents SET content = 'Contenu falsifié' WHERE id = 42");

        $this->assertFalse($service->verify(42, 7), 'verify() doit retourner false si contenu modifié');
    }

    public function testVerifyRetourneFalseSiPasSigné(): void
    {
        $this->assertFalse($this->makeService()->verify(42, 99));
    }

    // -------------------------------------------------------------------------
    // Tests du contrôleur DocumentsApiController::sign()
    // -------------------------------------------------------------------------

    private function makeController(): DocumentsApiController
    {
        $db = $this->db;

        return new class($db) extends DocumentsApiController {
            private \PDO $db;

            public function __construct(\PDO $db)
            {
                $this->db = $db;
            }

            protected function makeESignatureService(): ESignatureService
            {
                return new ESignatureService($this->db);
            }
        };
    }

    public function testControleurSign200AvecSignature(): void
    {
        $ctrl = $this->makeController();

        $request = $this->createMock(\Psr\Http\Message\ServerRequestInterface::class);
        $request->method('getAttribute')->with('user')->willReturn(['id' => 7]);

        $response = new SlimResponse();
        $result   = $ctrl->sign($request, $response, ['id' => '42']);

        $this->assertSame(200, $result->getStatusCode());

        $body = json_decode((string) $result->getBody(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);
        $data = $body['data'];
        $this->assertArrayHasKey('signature', $data);
        $this->assertNotEmpty($data['signature']);
    }

    public function testControleurSign404DocInconnu(): void
    {
        $ctrl = $this->makeController();

        $request = $this->createMock(\Psr\Http\Message\ServerRequestInterface::class);
        $request->method('getAttribute')->with('user')->willReturn(['id' => 1]);

        $response = new SlimResponse();
        $result   = $ctrl->sign($request, $response, ['id' => '9999']);

        $this->assertSame(404, $result->getStatusCode());
    }
}

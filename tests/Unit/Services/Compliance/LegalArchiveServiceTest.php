<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Compliance;

use KDocs\Services\Compliance\LegalArchiveService;
use KDocs\Services\Compliance\LegalSealedException;
use PHPUnit\Framework\TestCase;

/**
 * GAP-020 — scellement WORM : legal_sealed=1 → toute écriture lève LegalSealedException.
 * Hermétique : SQLite en mémoire injecté (schéma minimal documents + document_types).
 */
class LegalArchiveServiceTest extends TestCase
{
    private \PDO $db;
    private LegalArchiveService $service;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->db->exec('CREATE TABLE document_types (id INTEGER PRIMARY KEY, label TEXT)');
        $this->db->exec('CREATE TABLE documents (
            id INTEGER PRIMARY KEY,
            title TEXT,
            document_type_id INTEGER,
            document_date TEXT,
            created_at TEXT,
            deleted_at TEXT,
            legal_sealed INTEGER NOT NULL DEFAULT 0,
            legal_sealed_at TEXT,
            legal_sealed_by INTEGER,
            retention_until TEXT
        )');

        $this->db->exec("INSERT INTO document_types (id, label) VALUES (1, 'Facture')");
        $this->db->exec("INSERT INTO documents (id, title, document_type_id, document_date, created_at)
                         VALUES (42, 'Facture fournisseur', 1, '2026-01-15', '2026-01-16 08:00:00')");

        $this->service = new LegalArchiveService($this->db);
    }

    public function testSealMarqueLeDocumentEtFixeLaRetention(): void
    {
        $result = $this->service->seal(42, 7);

        $this->assertTrue($result['sealed']);
        $this->assertFalse($result['already_sealed']);
        $this->assertSame('2036-01-15', $result['retention_until']);

        $row = $this->db->query('SELECT * FROM documents WHERE id = 42')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $row['legal_sealed']);
        $this->assertSame(7, (int) $row['legal_sealed_by']);
        $this->assertNotEmpty($row['legal_sealed_at']);
        $this->assertSame('2036-01-15', $row['retention_until']);
    }

    public function testSealIdempotent(): void
    {
        $this->service->seal(42, 7);
        $second = $this->service->seal(42, 99);

        $this->assertTrue($second['already_sealed']);
        $this->assertSame('2036-01-15', $second['retention_until']);

        // Le scellement initial n'est pas écrasé.
        $row = $this->db->query('SELECT legal_sealed_by FROM documents WHERE id = 42')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(7, (int) $row['legal_sealed_by']);
    }

    public function testIsSealedRefleteLEtat(): void
    {
        $this->assertFalse($this->service->isSealed(42));
        $this->service->seal(42);
        $this->assertTrue($this->service->isSealed(42));
    }

    public function testAssertWritableLeveExceptionSiScelle(): void
    {
        $this->service->seal(42);

        $this->expectException(LegalSealedException::class);
        $this->expectExceptionCode(403);
        $this->service->assertWritable(42);
    }

    public function testAssertWritablePasseSiNonScelle(): void
    {
        $this->service->assertWritable(42);
        $this->addToAssertionCount(1);
    }

    public function testSealDocumentInconnuLeveException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->seal(999);
    }

    public function testArchiveDocumentDelegueAuSeal(): void
    {
        $result = $this->service->archiveDocument(42);

        $this->assertTrue($result['archived']);
        $this->assertSame('2036-01-15', $result['retention_until']);
        $this->assertTrue($this->service->isSealed(42));
    }
}

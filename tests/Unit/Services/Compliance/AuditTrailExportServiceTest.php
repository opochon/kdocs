<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Compliance;

use KDocs\Services\Compliance\AuditTrailExportService;
use PHPUnit\Framework\TestCase;

/**
 * GAP-022 — export piste de révision : tri chronologique, filtres, décodage JSON.
 * Hermétique : SQLite en mémoire injecté, schéma minimal audit_logs.
 */
class AuditTrailExportServiceTest extends TestCase
{
    private \PDO $db;
    private AuditTrailExportService $service;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->db->exec('CREATE TABLE audit_logs (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER,
            action      TEXT NOT NULL,
            object_type TEXT,
            object_id   INTEGER,
            object_name TEXT,
            changes     TEXT,
            ip_address  TEXT,
            user_agent  TEXT,
            created_at  TEXT DEFAULT CURRENT_TIMESTAMP
        )');

        // 3 événements insérés dans l'ordre anti-chronologique pour vérifier le tri.
        $this->db->exec("INSERT INTO audit_logs
            (user_id, action, object_type, object_id, object_name, changes, ip_address, created_at)
            VALUES (1, 'CREATE', 'document', 10, 'Doc A', '{\"size\":1024}', '127.0.0.1', '2026-01-01 08:00:00')");
        $this->db->exec("INSERT INTO audit_logs
            (user_id, action, object_type, object_id, object_name, changes, ip_address, created_at)
            VALUES (2, 'UPDATE', 'document', 10, 'Doc A', '{\"title\":\"Nouveau titre\"}', '10.0.0.1', '2026-01-02 09:00:00')");
        $this->db->exec("INSERT INTO audit_logs
            (user_id, action, object_type, object_id, object_name, changes, ip_address, created_at)
            VALUES (1, 'DELETE', 'document', 20, 'Doc B', NULL, '127.0.0.1', '2026-01-03 10:00:00')");

        $this->service = new AuditTrailExportService($this->db);
    }

    public function testExportSansFiltreLivreLesTroisEnregistrements(): void
    {
        $result = $this->service->export();

        $this->assertSame(3, $result['count']);
        $this->assertCount(3, $result['timeline']);
        $this->assertArrayHasKey('generated_at', $result);
        $this->assertArrayHasKey('filters', $result);
    }

    public function testTimelineTrieeChronologiquement(): void
    {
        $timeline = $this->service->export()['timeline'];

        $this->assertSame('2026-01-01 08:00:00', $timeline[0]['at']);
        $this->assertSame('2026-01-02 09:00:00', $timeline[1]['at']);
        $this->assertSame('2026-01-03 10:00:00', $timeline[2]['at']);
    }

    public function testFiltreParObjectId(): void
    {
        $result = $this->service->export(['object_id' => 10]);

        $this->assertSame(2, $result['count']);
        foreach ($result['timeline'] as $entry) {
            $this->assertSame(10, $entry['object_id']);
        }
    }

    public function testFiltreFrom(): void
    {
        $result = $this->service->export(['from' => '2026-01-02 00:00:00']);

        $this->assertSame(2, $result['count']);
        $this->assertSame('UPDATE', $result['timeline'][0]['action']);
        $this->assertSame('DELETE', $result['timeline'][1]['action']);
    }

    public function testFiltreTo(): void
    {
        $result = $this->service->export(['to' => '2026-01-01 23:59:59']);

        $this->assertSame(1, $result['count']);
        $this->assertSame('CREATE', $result['timeline'][0]['action']);
    }

    public function testFiltreFromTo(): void
    {
        $result = $this->service->export([
            'from' => '2026-01-02 00:00:00',
            'to'   => '2026-01-02 23:59:59',
        ]);

        $this->assertSame(1, $result['count']);
        $this->assertSame('UPDATE', $result['timeline'][0]['action']);
    }

    public function testFiltreParObjectType(): void
    {
        $result = $this->service->export(['object_type' => 'document']);

        $this->assertSame(3, $result['count']);
    }

    public function testFiltreParUserId(): void
    {
        $result = $this->service->export(['user_id' => 2]);

        $this->assertSame(1, $result['count']);
        $this->assertSame(2, $result['timeline'][0]['user_id']);
    }

    public function testChangesDecodéesEnTableau(): void
    {
        $timeline = $this->service->export()['timeline'];

        $this->assertIsArray($timeline[0]['changes']);
        $this->assertSame(1024, $timeline[0]['changes']['size']);
    }

    public function testChangesNullSiAbsente(): void
    {
        $timeline    = $this->service->export()['timeline'];
        $deleteEntry = $timeline[2];

        $this->assertNull($deleteEntry['changes']);
    }

    public function testStructureEntreeTimeline(): void
    {
        $entry = $this->service->export()['timeline'][0];

        $this->assertArrayHasKey('at', $entry);
        $this->assertArrayHasKey('user_id', $entry);
        $this->assertArrayHasKey('action', $entry);
        $this->assertArrayHasKey('object_type', $entry);
        $this->assertArrayHasKey('object_id', $entry);
        $this->assertArrayHasKey('object_name', $entry);
        $this->assertArrayHasKey('changes', $entry);
        $this->assertArrayHasKey('ip_address', $entry);
    }

    public function testExportSansResultatRenvoieTimelineVide(): void
    {
        $result = $this->service->export(['user_id' => 999]);

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['timeline']);
    }

    public function testFiltresConservésDansLaRéponse(): void
    {
        $filters = ['object_type' => 'document', 'user_id' => 1];
        $result  = $this->service->export($filters);

        $this->assertSame($filters, $result['filters']);
    }
}

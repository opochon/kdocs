<?php

namespace KDocs\Tests\Unit\Services\Ingest;

use KDocs\Services\Ingest\CmdV4CapabilityProbe;
use KDocs\Services\Ingest\CmdV4IngestEngine;
use KDocs\Services\Ingest\GedNativeIngestEngine;
use KDocs\Services\Ingest\IngestEngineRouter;
use KDocs\Tests\TestCase;

class IngestEngineRouterTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['CMD_V4_ENABLED']);
        parent::tearDown();
    }

    public function testRouterSelectsCmdV4ForInvoicePdfWhenAvailable(): void
    {
        $_ENV['CMD_V4_ENABLED'] = 'true';

        $v4Probe = $this->createMock(CmdV4CapabilityProbe::class);
        $v4Probe->method('probe')->willReturn([
            'v4_available' => true,
            'invoice_routing_available' => true,
        ]);
        $v4Probe->method('isInvoiceCandidate')->willReturn(true);

        $v4Engine = $this->createMock(CmdV4IngestEngine::class);
        $v4Engine->expects($this->once())->method('process')->willReturn([
            'engine' => 'cmd_v4',
            'extract_done' => true,
            'invoice_enriched' => true,
            'sidecar_error' => null,
        ]);

        $native = $this->createMock(GedNativeIngestEngine::class);
        $native->expects($this->never())->method('process');

        $router = new IngestEngineRouter($v4Probe, $v4Engine, $native);
        $result = $router->process(42, __DIR__ . '/facture.pdf', [
            'id' => 42,
            'mime_type' => 'application/pdf',
        ]);

        $this->assertSame('cmd_v4', $result['engine']);
        $this->assertTrue($result['invoice_enriched']);
    }

    public function testRouterSelectsNativeWhenV4Unavailable(): void
    {
        $v4Probe = $this->createMock(CmdV4CapabilityProbe::class);
        $v4Probe->method('probe')->willReturn([
            'v4_available' => false,
            'invoice_routing_available' => false,
        ]);
        $v4Probe->method('isInvoiceCandidate')->willReturn(false);

        $native = $this->createMock(GedNativeIngestEngine::class);
        $native->expects($this->once())->method('process')->willReturn([
            'engine' => 'native',
            'extract_done' => true,
            'classification_queued' => true,
        ]);

        $v4Engine = $this->createMock(CmdV4IngestEngine::class);
        $v4Engine->expects($this->never())->method('process');

        $router = new IngestEngineRouter($v4Probe, $v4Engine, $native);
        $result = $router->process(42, __FILE__, ['id' => 42, 'content' => 'hello']);

        $this->assertSame('native', $result['engine']);
        $this->assertTrue($result['classification_queued']);
    }

    public function testStatusExposesCmdV4Probe(): void
    {
        $v4Probe = $this->createMock(CmdV4CapabilityProbe::class);
        $v4Probe->method('probe')->willReturn(['v4_available' => false, 'invoice_routing_available' => false]);

        $router = new IngestEngineRouter($v4Probe);
        $status = $router->getStatus();

        $this->assertSame('native', $status['active_engine']);
        $this->assertArrayHasKey('cmd_v4', $status);
    }
}

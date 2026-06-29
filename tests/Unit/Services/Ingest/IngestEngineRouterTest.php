<?php

namespace KDocs\Tests\Unit\Services\Ingest;

use KDocs\Services\Ingest\ClearMyDocsIngestEngine;
use KDocs\Services\Ingest\CmdResultMapper;
use KDocs\Services\Ingest\GedNativeIngestEngine;
use KDocs\Services\Ingest\IngestEngineRouter;
use KDocs\Services\Ingest\ClearMyDocsCapabilityProbe;
use KDocs\Tests\TestCase;

class IngestEngineRouterTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['INGEST_ENGINE'], $_ENV['CLEARMYDOCS_ENABLED'], $_ENV['CMD_V4_ENABLED']);
        parent::tearDown();
    }

    public function testRouterSelectsCmdV4ForInvoicePdfWhenAvailable(): void
    {
        $_ENV['INGEST_ENGINE'] = 'auto';
        $_ENV['CLEARMYDOCS_ENABLED'] = 'false';
        $_ENV['CMD_V4_ENABLED'] = 'true';

        $probe = $this->createMock(ClearMyDocsCapabilityProbe::class);
        $probe->method('probe')->willReturn([
            'configured_mode' => 'auto',
            'coupled_available' => false,
            'active_engine' => 'native',
        ]);
        $probe->method('requiresCoupledEngine')->willReturn(false);

        $v4Probe = $this->createMock(\KDocs\Services\Ingest\CmdV4CapabilityProbe::class);
        $v4Probe->method('probe')->willReturn([
            'v4_available' => true,
            'invoice_routing_available' => true,
        ]);
        $v4Probe->method('isInvoiceCandidate')->willReturn(true);

        $v4Engine = $this->createMock(\KDocs\Services\Ingest\CmdV4IngestEngine::class);
        $v4Engine->expects($this->once())->method('process')->willReturn([
            'engine' => 'cmd_v4',
            'extract_done' => true,
            'invoice_enriched' => true,
            'sidecar_error' => null,
        ]);

        $native = $this->createMock(GedNativeIngestEngine::class);
        $native->expects($this->never())->method('process');

        $coupled = $this->createMock(ClearMyDocsIngestEngine::class);
        $coupled->expects($this->never())->method('process');

        $router = new IngestEngineRouter($probe, $v4Probe, $v4Engine, $coupled, $native);
        $result = $router->process(42, __DIR__ . '/facture.pdf', [
            'id' => 42,
            'mime_type' => 'application/pdf',
        ]);

        $this->assertSame('cmd_v4', $result['engine']);
        $this->assertTrue($result['invoice_enriched']);
    }

    public function testRouterSelectsNativeWhenCoupledUnavailable(): void
    {
        $_ENV['INGEST_ENGINE'] = 'auto';
        $_ENV['CLEARMYDOCS_ENABLED'] = 'false';

        $probe = $this->createMock(ClearMyDocsCapabilityProbe::class);
        $probe->method('probe')->willReturn([
            'configured_mode' => 'auto',
            'coupled_available' => false,
            'active_engine' => 'native',
        ]);
        $probe->method('requiresCoupledEngine')->willReturn(false);

        $native = $this->createMock(GedNativeIngestEngine::class);
        $native->expects($this->once())->method('process')->willReturn([
            'engine' => 'native',
            'extract_done' => true,
            'classification_queued' => true,
        ]);

        $coupled = $this->createMock(ClearMyDocsIngestEngine::class);
        $coupled->expects($this->never())->method('process');

        $v4Probe = $this->createMock(\KDocs\Services\Ingest\CmdV4CapabilityProbe::class);
        $v4Probe->method('probe')->willReturn([
            'v4_available' => false,
            'invoice_routing_available' => false,
        ]);
        $v4Probe->method('isInvoiceCandidate')->willReturn(false);

        $router = new IngestEngineRouter($probe, $v4Probe, null, $coupled, $native);
        $result = $router->process(42, __FILE__, ['id' => 42, 'content' => 'hello']);

        $this->assertSame('native', $result['engine']);
        $this->assertTrue($result['classification_queued']);
    }
}

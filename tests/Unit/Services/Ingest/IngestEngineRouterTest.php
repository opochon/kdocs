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
        unset($_ENV['INGEST_ENGINE'], $_ENV['CLEARMYDOCS_ENABLED']);
        parent::tearDown();
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

        $router = new IngestEngineRouter($probe, $coupled, $native);
        $result = $router->process(42, __FILE__, ['id' => 42, 'content' => 'hello']);

        $this->assertSame('native', $result['engine']);
        $this->assertTrue($result['classification_queued']);
    }
}

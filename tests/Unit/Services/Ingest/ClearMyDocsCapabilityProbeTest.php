<?php

namespace KDocs\Tests\Unit\Services\Ingest;

use KDocs\Services\ClearMyDocsSidecarClient;
use KDocs\Services\Ingest\ClearMyDocsCapabilityProbe;
use KDocs\Services\Ingest\ClearMyDocsIngestEngine;
use KDocs\Services\Ingest\CmdResultMapper;
use KDocs\Services\Ingest\GedNativeIngestEngine;
use KDocs\Services\Ingest\IngestEngineRouter;
use KDocs\Tests\TestCase;

class ClearMyDocsCapabilityProbeTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $_ENV['INGEST_ENGINE'],
            $_ENV['CLEARMYDOCS_ENABLED'],
            $_ENV['CLEARMYDOCS_PATH'],
            $_ENV['CLEARMYDOCS_MIN_VERSION']
        );
        parent::tearDown();
    }

    public function testNativeModeNeverUsesCoupled(): void
    {
        $_ENV['INGEST_ENGINE'] = 'native';
        $_ENV['CLEARMYDOCS_ENABLED'] = 'true';

        $client = $this->createMock(ClearMyDocsSidecarClient::class);
        $client->method('baseUrl')->willReturn('http://127.0.0.1:5101');
        $client->method('request')->willReturn([
            'status' => 'ok',
            'version' => '3.0.0',
            'capabilities' => ['ingest'],
        ]);

        $probe = new ClearMyDocsCapabilityProbe($client);
        $this->assertFalse($probe->shouldUseCoupledEngine());
        $this->assertSame('native', $probe->probe()['active_engine']);
    }

    public function testAutoModeUsesCoupledWhenHealthOk(): void
    {
        $_ENV['INGEST_ENGINE'] = 'auto';
        $_ENV['CLEARMYDOCS_ENABLED'] = 'true';
        $_ENV['CLEARMYDOCS_PATH'] = dirname(__DIR__, 4);
        $_ENV['CLEARMYDOCS_MIN_VERSION'] = '3.0.0';

        $client = $this->createMock(ClearMyDocsSidecarClient::class);
        $client->method('baseUrl')->willReturn('http://127.0.0.1:5101');
        $client->method('request')->willReturn([
            'status' => 'ok',
            'version' => '3.0.0',
            'capabilities' => ['segment', 'extract', 'analyze', 'ingest'],
        ]);

        $probe = new ClearMyDocsCapabilityProbe($client);
        $status = $probe->probe();

        $this->assertTrue($status['coupled_available']);
        $this->assertSame('coupled', $status['active_engine']);
        $this->assertTrue($probe->shouldUseCoupledEngine());
    }
}

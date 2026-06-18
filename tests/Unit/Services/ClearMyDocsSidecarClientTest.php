<?php

namespace KDocs\Tests\Unit\Services;

use KDocs\Services\ClearMyDocsSidecarClient;
use KDocs\Tests\TestCase;

class ClearMyDocsSidecarClientTest extends TestCase
{
    public function testIsEnabledReadsEnv(): void
    {
        $_ENV['CLEARMYDOCS_ENABLED'] = 'false';
        $client = new ClearMyDocsSidecarClient();
        $this->assertFalse($client->isEnabled());

        $_ENV['CLEARMYDOCS_ENABLED'] = 'true';
        $this->assertTrue($client->isEnabled());
    }

    public function testBaseUrlPrefersSidecarUrl(): void
    {
        $_ENV['CLEARMYDOCS_SIDECAR_URL'] = 'http://127.0.0.1:5101';
        $_ENV['CLEARMYDOCS_API_URL'] = 'http://127.0.0.1:8790';

        $client = new ClearMyDocsSidecarClient();
        $this->assertSame('http://127.0.0.1:5101', $client->baseUrl());
    }

    public function testSegmentPdfReturnsNullWhenDisabled(): void
    {
        $_ENV['CLEARMYDOCS_ENABLED'] = 'false';
        $client = new ClearMyDocsSidecarClient();

        $this->assertNull($client->segmentPdf(__FILE__));
    }

    public function testSegmentPdfReturnsNullWhenSidecarUnavailable(): void
    {
        $_ENV['CLEARMYDOCS_ENABLED'] = 'true';
        $_ENV['CLEARMYDOCS_SIDECAR_URL'] = 'http://127.0.0.1:59999';

        $client = new ClearMyDocsSidecarClient();
        $this->assertNull($client->segmentPdf(__FILE__));
    }
}

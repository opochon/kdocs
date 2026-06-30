<?php



declare(strict_types=1);



namespace KDocs\Tests\Unit\Services\Ingest;



use KDocs\Services\Ingest\CmdV4CapabilityProbe;

use KDocs\Services\Ingest\CmdV4Client;

use KDocs\Tests\TestCase;



class CmdV4CapabilityProbeTest extends TestCase

{

    private array $envBackup = [];



    protected function setUp(): void

    {

        foreach ([

            'CMD_V4_ENABLED',

            'CMD_V4_URL',

            'CMD_V4_PATH',

            'CMD_V4_INVOICE_ENABLED',

            'CMD_V4_INVOICE_STRICT',

        ] as $key) {

            $this->envBackup[$key] = $_ENV[$key] ?? getenv($key);

        }



        $_ENV['CMD_V4_ENABLED'] = 'false';

        putenv('CMD_V4_ENABLED=false');

    }



    protected function tearDown(): void

    {

        foreach ($this->envBackup as $key => $value) {

            if ($value === false || $value === null) {

                unset($_ENV[$key]);

                putenv($key);

            } else {

                $_ENV[$key] = $value;

                putenv("{$key}={$value}");

            }

        }

    }



    public function testDisabledWhenFlagOff(): void

    {

        $probe = new CmdV4CapabilityProbe();

        $status = $probe->probe(false);



        $this->assertFalse($status['v4_available']);

        $this->assertFalse($status['invoice_routing_available']);

    }



    public function testInvoiceCandidateDetectsPdf(): void

    {

        $client = $this->createMock(CmdV4Client::class);

        $probe = new CmdV4CapabilityProbe($client);



        $this->assertTrue($probe->isInvoiceCandidate(__DIR__ . '/fixture-facture.pdf', [

            'mime_type' => 'application/pdf',

        ]));

        $this->assertFalse($probe->isInvoiceCandidate(__DIR__ . '/fixture.txt', [

            'mime_type' => 'text/plain',

        ]));

    }



    public function testShouldRouteInvoicesFalseWhenInvoiceEnrichmentDisabled(): void

    {

        $client = $this->createMock(CmdV4Client::class);

        $client->method('isEnabled')->willReturn(true);

        $client->method('health')->willReturn(['ok' => true, 'version' => '4.0.0']);

        $client->method('baseUrl')->willReturn('http://127.0.0.1:8510');

        $client->method('projectProfile')->willReturn('legal_ch');



        $_ENV['CMD_V4_ENABLED'] = 'true';

        $_ENV['CMD_V4_PATH'] = dirname(__DIR__, 4);

        $_ENV['CMD_V4_INVOICE_ENABLED'] = 'false';

        putenv('CMD_V4_ENABLED=true');

        putenv('CMD_V4_PATH=' . dirname(__DIR__, 4));

        putenv('CMD_V4_INVOICE_ENABLED=false');



        $probe = new CmdV4CapabilityProbe($client);

        $this->assertFalse($probe->shouldRouteInvoices());

    }

}



<?php

declare(strict_types=1);

namespace KDocs\Tests\Unit\Services\Ingest;

use KDocs\Services\Ingest\CmdV4Client;
use KDocs\Tests\TestCase;

/**
 * Oracle étape 6 — substrat annexe + fraîcheur (client HTTP, sans serveur CMD live).
 */
class CmdV4ClientEtape6Test extends TestCase
{
    private array $envBackup = [];

    protected function setUp(): void
    {
        foreach (['CMD_V4_ENABLED', 'CMD_V4_URL', 'CMD_V4_PROJECT_PROFILE'] as $key) {
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

    public function testAnalyzeFileReturnsNullWhenDisabled(): void
    {
        $client = new CmdV4Client();
        $this->assertNull($client->analyzeFile(__FILE__));
    }

    public function testEtape6EndpointsReturnNullWhenDisabled(): void
    {
        $client = new CmdV4Client();
        $this->assertNull($client->getAnnexe('slug-test'));
        $this->assertNull($client->getDocsManifest('slug-test'));
        $this->assertNull($client->getFidelity('slug-test'));
        $this->assertNull($client->getFreshness('slug-test'));
    }

    public function testAnalyzeFileCallsApiWhenEnabled(): void
    {
        $_ENV['CMD_V4_ENABLED'] = 'true';
        putenv('CMD_V4_ENABLED=true');

        $client = $this->getMockBuilder(CmdV4Client::class)
            ->onlyMethods(['request'])
            ->getMock();
        $client->method('request')->willReturnCallback(static function (string $method, string $path): ?array {
            if ($method === 'POST' && $path === '/api/analyze-file') {
                return ['job_id' => 'job-1', 'slug' => 'proj-abc'];
            }
            return null;
        });

        $result = $client->analyzeFile(__FILE__, 'legal_ch');
        $this->assertSame(['job_id' => 'job-1', 'slug' => 'proj-abc'], $result);
    }

    public function testGetAnnexeRequiresAnnexeMdKey(): void
    {
        $_ENV['CMD_V4_ENABLED'] = 'true';
        putenv('CMD_V4_ENABLED=true');

        $client = $this->getMockBuilder(CmdV4Client::class)
            ->onlyMethods(['request'])
            ->getMock();
        $client->method('request')->willReturnOnConsecutiveCalls(
            ['gate' => 'F0'],
            ['annexe_md' => '# facts', 'gate' => 'F0'],
        );

        $this->assertNull($client->getAnnexe('proj-abc'));
        $this->assertSame('F0', $client->getAnnexe('proj-abc2')['gate']);
    }
}

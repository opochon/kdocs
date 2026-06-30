<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use KDocs\Core\ConnectorRegistry;
use PHPUnit\Framework\TestCase;

class ConnectorRegistryTest extends TestCase
{
    private array $envBackup = [];

    protected function setUp(): void
    {
        foreach ([
            'WINBIZ_ENABLED',
            'WINBIZ_BRIDGE_URL',
            'INVOICES_APP_ENABLED',
            'SMQ_APP_ENABLED',
            'CMD_V4_ENABLED',
        ] as $key) {
            $this->envBackup[$key] = $_ENV[$key] ?? getenv($key);
        }

        $_ENV['WINBIZ_ENABLED'] = 'false';
        $_ENV['WINBIZ_BRIDGE_URL'] = '';
        $_ENV['INVOICES_APP_ENABLED'] = 'false';
        $_ENV['SMQ_APP_ENABLED'] = 'false';
        $_ENV['CMD_V4_ENABLED'] = 'false';
        putenv('WINBIZ_ENABLED=false');
        putenv('WINBIZ_BRIDGE_URL=');
        putenv('INVOICES_APP_ENABLED=false');
        putenv('SMQ_APP_ENABLED=false');
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

    public function testConfigLoadsIngestNative(): void
    {
        $cfg = ConnectorRegistry::config();
        $this->assertArrayHasKey('ingest', $cfg);
        $this->assertArrayHasKey('ingest-native', $cfg['ingest']);
        $this->assertTrue($cfg['ingest']['ingest-native']['always'] ?? false);
    }

    public function testHealthAllIncludesNativeAlwaysAvailable(): void
    {
        $health = ConnectorRegistry::healthAll(false);
        $this->assertArrayHasKey('connectors', $health);
        $this->assertSame('available', $health['connectors']['ingest-native']['status']);
        $this->assertTrue($health['connectors']['ingest-native']['available']);
    }

    public function testDisabledErpNotAvailable(): void
    {
        $health = ConnectorRegistry::healthAll(false);
        $this->assertSame('disabled', $health['connectors']['erp-winbiz']['status']);
        $this->assertFalse($health['connectors']['erp-winbiz']['available']);
    }

    public function testInvoicesPluginBlockedWhenWinBizDisabled(): void
    {
        $_ENV['INVOICES_APP_ENABLED'] = 'true';
        putenv('INVOICES_APP_ENABLED=true');

        $health = ConnectorRegistry::healthAll(false);
        $this->assertSame('blocked', $health['plugins']['invoices']['status']);
        $this->assertContains('erp-winbiz', $health['plugins']['invoices']['blocked_by']);
    }

    public function testIsConnectorAvailableNative(): void
    {
        $this->assertTrue(ConnectorRegistry::isConnectorAvailable('ingest-native'));
        $this->assertFalse(ConnectorRegistry::isConnectorAvailable('erp-winbiz'));
    }
}

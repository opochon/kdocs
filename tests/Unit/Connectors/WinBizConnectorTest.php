<?php

namespace KDocs\Tests\Unit\Connectors;

use KDocs\Tests\TestCase;
use KDocs\Connectors\ConnectorInterface;

class WinBizConnectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3) . '/connectors/winbiz/WinBizConnector.php';
    }

    public function testImplementsConnectorInterface(): void
    {
        $connector = new \KDocs\Connectors\WinBiz\WinBizConnector();
        $this->assertInstanceOf(ConnectorInterface::class, $connector);
    }

    public function testIsConnectedFalseByDefault(): void
    {
        $connector = new \KDocs\Connectors\WinBiz\WinBizConnector();
        $this->assertFalse($connector->isConnected());
    }

    public function testTestConnectionReturnsArrayWhenDisabled(): void
    {
        $connector = new \KDocs\Connectors\WinBiz\WinBizConnector();
        $result = $connector->testConnection();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
    }
}

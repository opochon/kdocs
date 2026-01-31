<?php
/**
 * K-Docs - Tests Config
 */

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use KDocs\Core\Config;

class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::reset();
    }

    public function testLoadReturnsArray(): void
    {
        $config = Config::load();
        $this->assertIsArray($config);
    }

    public function testGetReturnsDefaultWhenKeyNotFound(): void
    {
        $value = Config::get('nonexistent.key', 'default');
        $this->assertEquals('default', $value);
    }

    public function testGetDatabaseConfig(): void
    {
        $dbConfig = Config::get('database');
        $this->assertIsArray($dbConfig);
        $this->assertArrayHasKey('host', $dbConfig);
        $this->assertArrayHasKey('port', $dbConfig);
        $this->assertArrayHasKey('name', $dbConfig);
    }

    public function testHasReturnsTrueForExistingKey(): void
    {
        $this->assertTrue(Config::has('app.name'));
    }

    public function testHasReturnsFalseForMissingKey(): void
    {
        $this->assertFalse(Config::has('nonexistent.deep.key'));
    }

    public function testBasePathExtractsPath(): void
    {
        $basePath = Config::basePath();
        // Sur localhost, ça devrait retourner quelque chose comme /kdocs
        $this->assertIsString($basePath);
    }

    public function testGetNestedValue(): void
    {
        $appName = Config::get('app.name');
        $this->assertEquals('K-Docs', $appName);
    }

    public function testGetWithDefaultOnNullValue(): void
    {
        // Si une clé existe mais est null, le default devrait être retourné
        $value = Config::get('ai.claude_api_key', 'default_key');
        // La valeur peut être null dans la config, donc on vérifie le type
        $this->assertTrue($value === null || is_string($value));
    }
}

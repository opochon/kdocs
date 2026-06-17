<?php
/**
 * Tests unitaires Config
 */

namespace KDocs\Tests\Unit\Core;

use KDocs\Tests\TestCase;
use KDocs\Core\Config;

class ConfigTest extends TestCase
{
    public function testLoadReturnsArray(): void
    {
        $config = Config::load();
        
        $this->assertIsArray($config);
    }
    
    public function testLoadContainsAppSection(): void
    {
        $config = Config::load();
        
        $this->assertArrayHasKey('app', $config);
    }
    
    public function testLoadContainsDatabaseSection(): void
    {
        $config = Config::load();
        
        $this->assertArrayHasKey('database', $config);
    }
    
    public function testGetReturnsDefault(): void
    {
        $value = Config::get('nonexistent.key', 'default');
        
        $this->assertEquals('default', $value);
    }
    
    public function testGetNestedValue(): void
    {
        $config = Config::load();
        
        // Test nested access
        $appName = Config::get('app.name', 'K-Docs');
        
        $this->assertIsString($appName);
    }
    
    public function testBasePathReturnsString(): void
    {
        $path = Config::basePath();
        
        $this->assertIsString($path);
        $this->assertDirectoryExists($path);
    }
}

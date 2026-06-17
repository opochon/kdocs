<?php
/**
 * Test Case de base pour K-Docs
 */

namespace KDocs\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use KDocs\Core\Database;
use KDocs\Core\Config;

abstract class TestCase extends BaseTestCase
{
    protected static ?\PDO $db = null;
    
    public static function setUpBeforeClass(): void
    {
        // Charger config test si existe
        $configPath = dirname(__DIR__, 2) . '/config/config.php';
        if (file_exists($configPath)) {
            Config::load();
        }
    }
    
    protected function getDb(): \PDO
    {
        if (self::$db === null) {
            self::$db = Database::getInstance();
        }
        return self::$db;
    }
    
    /**
     * Helper pour créer un mock de service
     */
    protected function mockService(string $class, array $methods = []): object
    {
        $mock = $this->createMock($class);
        foreach ($methods as $method => $return) {
            $mock->method($method)->willReturn($return);
        }
        return $mock;
    }
    
    /**
     * Assert qu'un tableau a une structure donnée
     */
    protected function assertArrayStructure(array $expected, array $actual): void
    {
        foreach ($expected as $key) {
            $this->assertArrayHasKey($key, $actual, "Missing key: $key");
        }
    }
}

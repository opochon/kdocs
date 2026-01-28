<?php
/**
 * Tests for Cache class
 */

namespace Tests\Unit\Core;

use KDocs\Core\Cache;
use PHPUnit\Framework\TestCase;

class CacheTest extends TestCase
{
    private Cache $cache;
    private string $testCacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = Cache::getInstance();
        $this->testCacheDir = dirname(__DIR__, 3) . '/storage/cache';

        // Clean up test keys
        $this->cache->delete('test_key');
        $this->cache->delete('test_remember');
        $this->cache->delete('test_expired');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Clean up test keys
        $this->cache->delete('test_key');
        $this->cache->delete('test_remember');
        $this->cache->delete('test_expired');
    }

    public function testGetInstanceReturnsSameInstance(): void
    {
        $instance1 = Cache::getInstance();
        $instance2 = Cache::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function testSetAndGet(): void
    {
        $result = $this->cache->set('test_key', 'test_value', 60);
        $this->assertTrue($result);

        $value = $this->cache->get('test_key');
        $this->assertEquals('test_value', $value);
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $value = $this->cache->get('nonexistent_key', 'default');
        $this->assertEquals('default', $value);
    }

    public function testGetReturnsNullForMissingKeyWithNoDefault(): void
    {
        $value = $this->cache->get('nonexistent_key');
        $this->assertNull($value);
    }

    public function testHasReturnsTrueForExistingKey(): void
    {
        $this->cache->set('test_key', 'test_value', 60);

        $this->assertTrue($this->cache->has('test_key'));
    }

    public function testHasReturnsFalseForMissingKey(): void
    {
        $this->assertFalse($this->cache->has('nonexistent_key'));
    }

    public function testDelete(): void
    {
        $this->cache->set('test_key', 'test_value', 60);
        $this->assertTrue($this->cache->has('test_key'));

        $result = $this->cache->delete('test_key');
        $this->assertTrue($result);

        $this->assertFalse($this->cache->has('test_key'));
    }

    public function testDeleteNonexistentKeyReturnsTrue(): void
    {
        $result = $this->cache->delete('nonexistent_key');
        $this->assertTrue($result);
    }

    public function testSetWithArray(): void
    {
        $data = ['foo' => 'bar', 'numbers' => [1, 2, 3]];
        $this->cache->set('test_key', $data, 60);

        $value = $this->cache->get('test_key');
        $this->assertEquals($data, $value);
    }

    public function testSetWithObject(): void
    {
        $data = (object)['foo' => 'bar'];
        $this->cache->set('test_key', $data, 60);

        $value = $this->cache->get('test_key');
        // JSON decode returns array, not object
        $this->assertEquals(['foo' => 'bar'], $value);
    }

    public function testSetWithInteger(): void
    {
        $this->cache->set('test_key', 42, 60);

        $value = $this->cache->get('test_key');
        $this->assertSame(42, $value);
    }

    public function testSetWithBoolean(): void
    {
        $this->cache->set('test_key', true, 60);

        $value = $this->cache->get('test_key');
        $this->assertTrue($value);
    }

    public function testRemember(): void
    {
        $callCount = 0;
        $callback = function() use (&$callCount) {
            $callCount++;
            return 'generated_value';
        };

        // First call should execute callback
        $value1 = $this->cache->remember('test_remember', $callback, 60);
        $this->assertEquals('generated_value', $value1);
        $this->assertEquals(1, $callCount);

        // Second call should return cached value without callback
        $value2 = $this->cache->remember('test_remember', $callback, 60);
        $this->assertEquals('generated_value', $value2);
        $this->assertEquals(1, $callCount); // Still 1
    }

    public function testStatsReturnsArray(): void
    {
        $stats = $this->cache->stats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_files', $stats);
        $this->assertArrayHasKey('total_size', $stats);
        $this->assertArrayHasKey('expired_files', $stats);
        $this->assertArrayHasKey('valid_files', $stats);
    }

    public function testEnableDisable(): void
    {
        // Cache should be enabled by default
        $this->assertTrue($this->cache->isEnabled());

        // Disable cache
        $this->cache->setEnabled(false);
        $this->assertFalse($this->cache->isEnabled());

        // Operations should return defaults when disabled
        $this->cache->set('test_key', 'value', 60);
        $this->assertNull($this->cache->get('test_key'));
        $this->assertFalse($this->cache->has('test_key'));

        // Re-enable
        $this->cache->setEnabled(true);
        $this->assertTrue($this->cache->isEnabled());
    }

    public function testClear(): void
    {
        // Set multiple values
        $this->cache->set('test_key_1', 'value1', 60);
        $this->cache->set('test_key_2', 'value2', 60);

        // Clear all
        $count = $this->cache->clear();

        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testCleanup(): void
    {
        // Set an expired value (TTL = 0)
        $this->cache->set('test_expired', 'value', 0);

        // Run cleanup
        $count = $this->cache->cleanup();

        $this->assertGreaterThanOrEqual(0, $count);
    }
}

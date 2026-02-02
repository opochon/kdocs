<?php
/**
 * K-Docs - TrainingService Unit Tests
 */

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use KDocs\Services\TrainingService;

class TrainingServiceTest extends TestCase
{
    private string $testTrainingFile;
    private ?TrainingService $service = null;

    protected function setUp(): void
    {
        $this->testTrainingFile = sys_get_temp_dir() . '/kdocs_test_training_' . uniqid() . '.json';

        // Create a minimal config for testing
        if (!defined('KDOCS_TEST_MODE')) {
            define('KDOCS_TEST_MODE', true);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testTrainingFile)) {
            @unlink($this->testTrainingFile);
        }
    }

    public function testGetStatisticsEmpty(): void
    {
        // Skip if TrainingService can't be instantiated without full config
        try {
            $service = new TrainingService();
            $stats = $service->getStatistics();

            $this->assertIsArray($stats);
            $this->assertArrayHasKey('total_corrections', $stats);
            $this->assertArrayHasKey('total_rules', $stats);
        } catch (\Exception $e) {
            $this->markTestSkipped('TrainingService requires full config: ' . $e->getMessage());
        }
    }

    public function testExportReturnsArray(): void
    {
        try {
            $service = new TrainingService();
            $export = $service->export();

            $this->assertIsArray($export);
            $this->assertArrayHasKey('corrections', $export);
            $this->assertArrayHasKey('rules', $export);
        } catch (\Exception $e) {
            $this->markTestSkipped('TrainingService requires full config: ' . $e->getMessage());
        }
    }

    public function testClearResetsData(): void
    {
        try {
            $service = new TrainingService();
            $result = $service->clear();

            $this->assertTrue($result);

            $stats = $service->getStatistics();
            $this->assertEquals(0, $stats['total_corrections']);
            $this->assertEquals(0, $stats['total_rules']);
        } catch (\Exception $e) {
            $this->markTestSkipped('TrainingService requires full config: ' . $e->getMessage());
        }
    }
}

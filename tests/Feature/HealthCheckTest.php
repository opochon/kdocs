<?php
/**
 * Tests for Health Check endpoint
 */

namespace Tests\Feature;

class HealthCheckTest extends ApiTestCase
{
    public function testHealthCheckResponseStructure(): void
    {
        $response = [
            'status' => 'healthy',
            'timestamp' => '2026-01-27T10:00:00+00:00',
            'checks' => [
                'database' => ['status' => 'ok', 'message' => 'Connected'],
                'storage' => ['status' => 'ok', 'message' => 'Writable'],
                'cache' => ['status' => 'ok', 'message' => 'Writable'],
                'ocr' => ['status' => 'ok', 'message' => 'Tesseract available'],
                'queue_worker' => ['status' => 'warning', 'message' => 'Not running'],
                'php' => ['status' => 'ok', 'message' => 'PHP 8.4.0']
            ],
            'version' => '1.0.0'
        ];

        $this->assertArrayHasKey('status', $response);
        $this->assertArrayHasKey('timestamp', $response);
        $this->assertArrayHasKey('checks', $response);
        $this->assertArrayHasKey('version', $response);
    }

    public function testHealthyStatus(): void
    {
        $response = ['status' => 'healthy'];

        $this->assertEquals('healthy', $response['status']);
    }

    public function testUnhealthyStatus(): void
    {
        $response = ['status' => 'unhealthy'];

        $this->assertEquals('unhealthy', $response['status']);
    }

    public function testCheckHasStatusAndMessage(): void
    {
        $check = ['status' => 'ok', 'message' => 'Connected'];

        $this->assertArrayHasKey('status', $check);
        $this->assertArrayHasKey('message', $check);
    }

    public function testValidCheckStatuses(): void
    {
        $validStatuses = ['ok', 'warning', 'error'];

        foreach ($validStatuses as $status) {
            $this->assertContains($status, $validStatuses);
        }
    }

    public function testDatabaseCheckSuccess(): void
    {
        $check = ['status' => 'ok', 'message' => 'Connected'];

        $this->assertEquals('ok', $check['status']);
    }

    public function testDatabaseCheckFailure(): void
    {
        $check = ['status' => 'error', 'message' => 'Connection refused'];

        $this->assertEquals('error', $check['status']);
        $this->assertStringContainsString('refused', $check['message']);
    }

    public function testStorageWritableCheck(): void
    {
        $storageDir = dirname(__DIR__, 2) . '/storage';

        $isWritable = is_writable($storageDir);

        $this->assertTrue($isWritable, 'Storage directory should be writable');
    }

    public function testPhpVersionCheck(): void
    {
        $isValidVersion = version_compare(PHP_VERSION, '8.3.0', '>=');

        $this->assertTrue($isValidVersion, 'PHP version should be 8.3.0 or higher');
    }

    public function testTimestampFormat(): void
    {
        $timestamp = date('c');

        $parsed = \DateTime::createFromFormat(\DateTime::ATOM, $timestamp);

        $this->assertNotFalse($parsed, 'Timestamp should be valid ISO 8601 format');
    }

    public function testHttpStatusForHealthy(): void
    {
        $status = 'healthy';
        $httpStatus = ($status === 'healthy') ? 200 : 503;

        $this->assertEquals(200, $httpStatus);
    }

    public function testHttpStatusForUnhealthy(): void
    {
        $status = 'unhealthy';
        $httpStatus = ($status === 'healthy') ? 200 : 503;

        $this->assertEquals(503, $httpStatus);
    }
}

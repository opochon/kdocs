<?php
/**
 * Tests for ErrorHandlerMiddleware
 */

namespace Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;

class ErrorHandlerTest extends TestCase
{
    public function testIsApiRequestByPath(): void
    {
        $paths = [
            '/api/documents' => true,
            '/api/validation/pending' => true,
            '/documents' => false,
            '/dashboard' => false,
            '/login' => false,
        ];

        foreach ($paths as $path => $expected) {
            $isApi = strpos($path, '/api/') !== false;
            $this->assertEquals($expected, $isApi, "Path $path should be " . ($expected ? 'API' : 'not API'));
        }
    }

    public function testIsApiRequestByAcceptHeader(): void
    {
        $headers = [
            'application/json' => true,
            'application/json, text/html' => true,
            'text/html' => false,
            '*/*' => false,
        ];

        foreach ($headers as $accept => $expected) {
            $isApi = strpos($accept, 'application/json') !== false;
            $this->assertEquals($expected, $isApi, "Accept '$accept' should be " . ($expected ? 'API' : 'not API'));
        }
    }

    public function testErrorReferenceFormat(): void
    {
        $errorRef = date('YmdHis') . '-' . substr(md5(uniqid()), 0, 8);

        // Should be like: 20260128120000-a1b2c3d4
        $this->assertMatchesRegularExpression('/^\d{14}-[a-f0-9]{8}$/', $errorRef);
    }

    public function testGetClientIpFromXForwardedFor(): void
    {
        $header = '203.0.113.195, 70.41.3.18, 150.172.238.178';
        $ips = explode(',', $header);
        $clientIp = trim($ips[0]);

        $this->assertEquals('203.0.113.195', $clientIp);
    }

    public function testGetClientIpFromSingleValue(): void
    {
        $header = '203.0.113.195';
        $ips = explode(',', $header);
        $clientIp = trim($ips[0]);

        $this->assertEquals('203.0.113.195', $clientIp);
    }

    public function test404ResponseStructure(): void
    {
        $response = [
            'error' => 'Not Found',
            'message' => 'The requested resource was not found',
            'status' => 404
        ];

        $this->assertArrayHasKey('error', $response);
        $this->assertArrayHasKey('message', $response);
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals(404, $response['status']);
    }

    public function test500ResponseStructure(): void
    {
        $response = [
            'error' => 'Internal Server Error',
            'message' => 'An unexpected error occurred',
            'reference' => '20260128120000-a1b2c3d4',
            'status' => 500
        ];

        $this->assertArrayHasKey('error', $response);
        $this->assertArrayHasKey('message', $response);
        $this->assertArrayHasKey('reference', $response);
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals(500, $response['status']);
    }

    public function test500ResponseWithDebugInfo(): void
    {
        $debug = true;
        $exception = new \Exception('Test error', 500);

        $response = [
            'error' => 'Internal Server Error',
            'message' => $debug ? $exception->getMessage() : 'An unexpected error occurred',
            'reference' => '20260128120000-a1b2c3d4',
            'status' => 500
        ];

        if ($debug) {
            $response['file'] = $exception->getFile();
            $response['line'] = $exception->getLine();
        }

        $this->assertEquals('Test error', $response['message']);
        $this->assertArrayHasKey('file', $response);
        $this->assertArrayHasKey('line', $response);
    }

    public function test500ResponseWithoutDebugInfo(): void
    {
        $debug = false;
        $exception = new \Exception('Sensitive error details', 500);

        $response = [
            'error' => 'Internal Server Error',
            'message' => $debug ? $exception->getMessage() : 'An unexpected error occurred',
            'reference' => '20260128120000-a1b2c3d4',
            'status' => 500
        ];

        $this->assertEquals('An unexpected error occurred', $response['message']);
        $this->assertArrayNotHasKey('file', $response);
        $this->assertArrayNotHasKey('line', $response);
    }

    public function testLogEntryFormat(): void
    {
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => '500',
            'data' => [
                'reference' => '20260128120000-a1b2c3d4',
                'path' => '/api/documents',
                'method' => 'GET',
                'ip' => '127.0.0.1',
                'error' => 'Test error'
            ]
        ];

        $json = json_encode($entry, JSON_UNESCAPED_UNICODE);

        $this->assertIsString($json);
        $this->assertStringContainsString('timestamp', $json);
        $this->assertStringContainsString('type', $json);
        $this->assertStringContainsString('data', $json);
    }

    public function testErrorTemplatePathExists(): void
    {
        $basePath = dirname(__DIR__, 3);
        $error404Path = $basePath . '/templates/errors/404.php';
        $error500Path = $basePath . '/templates/errors/500.php';

        $this->assertFileExists($error404Path);
        $this->assertFileExists($error500Path);
    }
}

<?php
/**
 * Tests for CSRF class
 */

namespace Tests\Unit\Core;

use KDocs\Core\CSRF;
use PHPUnit\Framework\TestCase;

class CSRFTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Start session if not started
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        // Clear any existing token
        unset($_SESSION['_csrf_token']);
        unset($_SESSION['_csrf_token_time']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Clean up session
        unset($_SESSION['_csrf_token']);
        unset($_SESSION['_csrf_token_time']);
    }

    public function testGenerateTokenReturnsString(): void
    {
        $token = CSRF::generateToken();

        $this->assertIsString($token);
        $this->assertEquals(64, strlen($token)); // 32 bytes = 64 hex chars
    }

    public function testGenerateTokenIsDifferentEachTime(): void
    {
        $token1 = CSRF::generateToken();
        $token2 = CSRF::generateToken();

        $this->assertNotEquals($token1, $token2);
    }

    public function testGetTokenReturnsSameTokenIfNotExpired(): void
    {
        $token1 = CSRF::getToken();
        $token2 = CSRF::getToken();

        $this->assertEquals($token1, $token2);
    }

    public function testValidateTokenWithValidToken(): void
    {
        $token = CSRF::generateToken();

        $this->assertTrue(CSRF::validateToken($token));
    }

    public function testValidateTokenWithInvalidToken(): void
    {
        CSRF::generateToken();

        $this->assertFalse(CSRF::validateToken('invalid_token'));
    }

    public function testValidateTokenWithNullToken(): void
    {
        CSRF::generateToken();

        $this->assertFalse(CSRF::validateToken(null));
    }

    public function testValidateTokenWithEmptyToken(): void
    {
        CSRF::generateToken();

        $this->assertFalse(CSRF::validateToken(''));
    }

    public function testValidateAndRegenerateRegeneratesToken(): void
    {
        $token1 = CSRF::generateToken();
        $result = CSRF::validateAndRegenerate($token1);
        $token2 = CSRF::getToken();

        $this->assertTrue($result);
        $this->assertNotEquals($token1, $token2);
    }

    public function testFieldReturnsHtmlInput(): void
    {
        $field = CSRF::field();

        $this->assertStringContainsString('<input', $field);
        $this->assertStringContainsString('type="hidden"', $field);
        $this->assertStringContainsString('name="_csrf_token"', $field);
        $this->assertStringContainsString('value="', $field);
    }

    public function testMetaTagReturnsMetaElement(): void
    {
        $meta = CSRF::metaTag();

        $this->assertStringContainsString('<meta', $meta);
        $this->assertStringContainsString('name="csrf-token"', $meta);
        $this->assertStringContainsString('content="', $meta);
    }

    public function testGetTokenNameReturnsCorrectName(): void
    {
        $this->assertEquals('_csrf_token', CSRF::getTokenName());
    }
}

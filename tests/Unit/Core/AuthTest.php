<?php
/**
 * K-Docs - Tests Auth
 */

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }

    public function testSessionIdIsGenerated(): void
    {
        $sessionId = bin2hex(random_bytes(32));
        $this->assertEquals(64, strlen($sessionId));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $sessionId);
    }

    public function testPasswordHashVerification(): void
    {
        $password = 'test123';
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $this->assertTrue(password_verify($password, $hash));
        $this->assertFalse(password_verify('wrong', $hash));
    }

    public function testPasswordHashIsUnique(): void
    {
        $password = 'samePassword';
        $hash1 = password_hash($password, PASSWORD_DEFAULT);
        $hash2 = password_hash($password, PASSWORD_DEFAULT);

        // Les hash doivent être différents (salt différent)
        $this->assertNotEquals($hash1, $hash2);

        // Mais les deux doivent valider le même mot de passe
        $this->assertTrue(password_verify($password, $hash1));
        $this->assertTrue(password_verify($password, $hash2));
    }

    public function testEmptyPasswordFails(): void
    {
        $password = '';
        $hash = password_hash('validPassword', PASSWORD_DEFAULT);

        $this->assertFalse(password_verify($password, $hash));
    }
}

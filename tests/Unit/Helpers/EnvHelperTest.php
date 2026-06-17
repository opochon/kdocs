<?php

namespace KDocs\Tests\Unit\Helpers;

use KDocs\Tests\TestCase;

class EnvHelperTest extends TestCase
{
    public function testEnvReturnsDefaultWhenMissing(): void
    {
        $this->assertSame('fallback', env('GEDV1_TEST_MISSING_KEY_' . uniqid(), 'fallback'));
    }

    public function testEnvReadsFromEnvArray(): void
    {
        $_ENV['GEDV1_TEST_KEY'] = 'value';
        $this->assertSame('value', env('GEDV1_TEST_KEY'));
        unset($_ENV['GEDV1_TEST_KEY']);
    }
}

<?php

namespace KDocs\Tests\Unit\Core;

use KDocs\Core\PublicAssets;
use KDocs\Tests\TestCase;

class PublicAssetsTest extends TestCase
{
    public function testResolveTailwindCss(): void
    {
        $file = PublicAssets::resolveFile('css/tailwind.css');

        $this->assertNotNull($file);
        $this->assertFileExists($file);
    }

    public function testRejectPathTraversal(): void
    {
        $this->assertNull(PublicAssets::resolveFile('../config/config.php'));
    }

    public function testMimeTypeCss(): void
    {
        $this->assertSame(
            'text/css; charset=utf-8',
            PublicAssets::mimeType(PublicAssets::publicRoot() . '/css/tailwind.css')
        );
    }
}

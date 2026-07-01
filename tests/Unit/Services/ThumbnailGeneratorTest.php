<?php

declare(strict_types=1);

/**
 * Oracle GAP-002 / pdftoppm : binaire configuré et exécutable (Poppler).
 */

namespace KDocs\Tests\Unit\Services;

use KDocs\Core\Config;
use KDocs\Tests\TestCase;

class ThumbnailGeneratorTest extends TestCase
{
    public function testPdftoppmPathResolvable(): void
    {
        Config::load();
        $path = Config::get('tools.pdftoppm');

        $this->assertNotEmpty($path, 'tools.pdftoppm non configuré');
        $this->assertFileExists($path, "pdftoppm introuvable : {$path}");

        $cmd = escapeshellarg($path) . ' -v 2>&1';
        $output = shell_exec($cmd);
        $this->assertNotFalse($output);
        $this->assertMatchesRegularExpression('/pdftoppm|poppler/i', (string) $output);
    }
}

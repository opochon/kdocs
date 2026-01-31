<?php
/**
 * K-Docs - Tests SystemHelper
 */

namespace Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use KDocs\Helpers\SystemHelper;

class SystemHelperTest extends TestCase
{
    public function testWhichCommandReturnsCorrectCommand(): void
    {
        $cmd = SystemHelper::whichCommand();

        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertEquals('where', $cmd);
        } else {
            $this->assertEquals('which', $cmd);
        }
    }

    public function testNullRedirectReturnsCorrectSyntax(): void
    {
        $redirect = SystemHelper::nullRedirect();

        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertEquals('2>nul', $redirect);
        } else {
            $this->assertEquals('2>/dev/null', $redirect);
        }
    }

    public function testGetDefaultPathsReturnsArray(): void
    {
        $paths = SystemHelper::getDefaultPaths('tesseract');
        $this->assertIsArray($paths);
    }

    public function testGetDefaultPathsForUnknownTool(): void
    {
        $paths = SystemHelper::getDefaultPaths('unknown_tool');
        $this->assertIsArray($paths);
        $this->assertEmpty($paths);
    }

    public function testCommandExistsForPhp(): void
    {
        // PHP devrait toujours être dans le PATH
        $this->assertTrue(SystemHelper::commandExists('php'));
    }

    public function testCommandExistsReturnsFalseForFakeCommand(): void
    {
        $this->assertFalse(SystemHelper::commandExists('nonexistent_command_xyz_123'));
    }

    public function testIsWindowsReturnsBoolean(): void
    {
        $result = SystemHelper::isWindows();
        $this->assertIsBool($result);
        $this->assertEquals(PHP_OS_FAMILY === 'Windows', $result);
    }

    public function testIsLinuxReturnsBoolean(): void
    {
        $result = SystemHelper::isLinux();
        $this->assertIsBool($result);
        $this->assertEquals(PHP_OS_FAMILY === 'Linux', $result);
    }

    public function testPathSeparator(): void
    {
        $separator = SystemHelper::pathSeparator();

        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertEquals('\\', $separator);
        } else {
            $this->assertEquals('/', $separator);
        }
    }

    public function testNormalizePath(): void
    {
        $path = 'some/path\\to/file';
        $normalized = SystemHelper::normalizePath($path);

        // Le chemin normalisé ne devrait contenir que DIRECTORY_SEPARATOR
        $this->assertStringNotContainsString(
            DIRECTORY_SEPARATOR === '/' ? '\\' : '/',
            $normalized
        );
    }

    public function testFindExecutableReturnsNullForMissingCommand(): void
    {
        $result = SystemHelper::findExecutable('nonexistent_command_xyz', []);
        $this->assertNull($result);
    }

    public function testFindExecutableReturnsFallbackPath(): void
    {
        // Créer un fichier temporaire pour simuler un exécutable
        $tempFile = sys_get_temp_dir() . '/test_executable_' . uniqid();
        touch($tempFile);

        $result = SystemHelper::findExecutable('nonexistent_command', [$tempFile]);
        $this->assertEquals($tempFile, $result);

        unlink($tempFile);
    }

    public function testGetDefaultPathsForAllTools(): void
    {
        $tools = ['tesseract', 'ghostscript', 'imagemagick', 'pdftotext', 'pdftoppm', 'libreoffice'];

        foreach ($tools as $tool) {
            $paths = SystemHelper::getDefaultPaths($tool);
            $this->assertIsArray($paths, "getDefaultPaths('$tool') should return an array");
        }
    }
}

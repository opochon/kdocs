<?php

declare(strict_types=1);

namespace Ged\Tests\Socle;

use PHPUnit\Framework\TestCase;

/** Oracle V2-13x : une requête HTTP n'installe ni ne migre jamais le schéma. */
final class DdlHorsHttpTest extends TestCase
{
    public function testLeModuleSocleNePosePasLeSchemaAuDemarrage(): void
    {
        $module = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Socle/Module.php');

        self::assertDoesNotMatchRegularExpression('/(?:Base::assurerBase|Schema::installer)\\s*\\(/', $module);
    }

    public function testAucunModuleOuServiceHttpNAppelleUnInstallateurDeSchema(): void
    {
        $racine = dirname(__DIR__, 3) . '/src';
        $infractions = [];
        $iterateur = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine));
        foreach ($iterateur as $fichier) {
            if (!$fichier instanceof \SplFileInfo || !$fichier->isFile() || $fichier->getExtension() !== 'php') {
                continue;
            }
            if ($fichier->getFilename() === 'Schema.php') {
                continue;
            }
            $contenu = (string) file_get_contents($fichier->getPathname());
            if (preg_match('/(?:\\\\[A-Za-z_][A-Za-z0-9_\\\\]*)*Schema::installer\s*\(/', $contenu) === 1) {
                $infractions[] = str_replace('\\', '/', substr($fichier->getPathname(), strlen(dirname(__DIR__, 3)) + 1));
            }
        }

        self::assertSame([], $infractions, 'seuls les outils CLI et les amorces de test installent le schéma');
    }
}

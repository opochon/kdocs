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

    public function testLaCollaborationNeMigrePasLorsDeChaqueFiche(): void
    {
        $collaboration = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Socle/Collaboration.php');

        self::assertDoesNotMatchRegularExpression('/Schema::installer\\s*\\(/', $collaboration);
    }

    public function testLaRechercheNeMigrePasDepuisUnEcran(): void
    {
        $service = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Recherche/Service.php');
        $ecran = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Recherche/Ecran.php');

        self::assertDoesNotMatchRegularExpression('/Schema::installer\\s*\\(/', $service . $ecran);
    }

    public function testLesReconstructeursHttpSupposentLeSchemaInstalle(): void
    {
        $racine = dirname(__DIR__, 3) . '/src/Socle/';
        $sources = (string) file_get_contents($racine . 'ReconstructionIndex.php')
            . (string) file_get_contents($racine . 'Reconstructeur.php');

        self::assertDoesNotMatchRegularExpression('/Schema(?:Recherche)?::installer\\s*\\(/', $sources);
    }

    public function testLesEcransNommentLeSchemaAbsentSansLeCreer(): void
    {
        $racine = dirname(__DIR__, 3) . '/src/';
        $bibliotheque = (string) file_get_contents($racine . 'Ui/Ecrans.php');
        $recherche = (string) file_get_contents($racine . 'Recherche/ErreurSql.php');

        self::assertStringContainsString('Schéma documentaire absent ou indisponible', $bibliotheque);
        self::assertStringContainsString('Schéma de recherche absent ou indisponible', $recherche);
        self::assertStringContainsString('tools/installer.php', $bibliotheque . $recherche);
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

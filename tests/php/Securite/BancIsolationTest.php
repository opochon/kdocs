<?php

declare(strict_types=1);

namespace Ged\Tests\Securite;

use PHPUnit\Framework\TestCase;

/** Oracle V2-13v Q2 : les bancs avec PDO ne peuvent viser que gedv2_test. */
final class BancIsolationTest extends TestCase
{
    public function testChaqueBancPdoImposeEtVerifieLaBaseDeTest(): void
    {
        $racine = dirname(__DIR__, 3);
        $bancsPdo = [];
        foreach (glob($racine . '/tools/banc/*.php') ?: [] as $fichier) {
            $source = (string) file_get_contents($fichier);
            if (!str_contains($source, 'Base::pdo(')) {
                continue;
            }
            $bancsPdo[] = basename($fichier);

            self::assertMatchesRegularExpression("/(DB_NAME=gedv2_test|'DB_NAME'\\s*=>\\s*'gedv2_test')/", $source,
                basename($fichier) . ' impose gedv2_test');
            self::assertMatchesRegularExpression("/DB_NAME[^\\n]+!==?\\s*'gedv2_test'/", $source,
                basename($fichier) . ' refuse une autre base avant PDO');
        }
        self::assertSame(['charge.php', 'contrat.php', 'mesurer-classement.php', 'rejouer-ingestion.php', 'sonde-securite.php'], $bancsPdo);
    }

    public function testLeCompteReelDeLaSondeEstMarqueEtNettoye(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/tools/banc/sonde-securite.php');

        self::assertStringContainsString('sonde-utilisateur-', $source);
        self::assertMatchesRegularExpression('/DELETE FROM comptes WHERE id = \\?/', $source);
    }
}

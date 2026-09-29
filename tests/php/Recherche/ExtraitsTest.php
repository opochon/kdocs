<?php

declare(strict_types=1);

namespace Ged\Tests\Recherche;

use Ged\Recherche\Extraits;
use Ged\Recherche\Termes;
use PHPUnit\Framework\TestCase;

/** Découpage des termes et des extraits : ce que l'écran surligne, sans jamais produire de HTML. */
final class ExtraitsTest extends TestCase
{
    public function testSegmentsMarquentLeTermeEnGardantLaCasse(): void
    {
        self::assertSame([
            ['t' => 'Contrat de ', 'hit' => false],
            ['t' => 'Maintenance', 'hit' => true],
            ['t' => ' annuelle', 'hit' => false],
        ], Extraits::segments('Contrat de Maintenance annuelle', ['maintenance']));
    }

    public function testExtraitEncadreLaPremiereOccurrence(): void
    {
        $extraits = Extraits::extraire(str_repeat('x', 200) . ' maintenance ' . str_repeat('y', 200), ['maintenance']);

        self::assertCount(1, $extraits);
        self::assertContains(true, array_column($extraits[0], 'hit'), 'le terme est surligné dans l’extrait');
        self::assertStringStartsWith('…', $extraits[0][0]['t'], 'l’extrait est tronqué avant');
    }

    public function testTermesDecoupentGuillemetsEtExclusion(): void
    {
        $termes = Termes::depuis('"contrat de maintenance" -annuelle');

        self::assertSame(['contrat de maintenance'], $termes->positifs());
        self::assertSame(['annuelle'], $termes->negatifs());
        self::assertSame('+"contrat de maintenance" -annuelle*', $termes->expressionFulltext());
    }

    public function testUnTermeDUnCaractereNEstPasUneRequete(): void
    {
        $termes = Termes::depuis('a');

        self::assertSame([], $termes->positifs());
        self::assertSame('', $termes->expressionFulltext(), 'expression vide : le repli LIKE prend la main');
    }
}

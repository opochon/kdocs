<?php

declare(strict_types=1);

namespace Ged\Tests\DepotFileserver;

use Ged\DepotFileserver\Scanner;
use PHPUnit\Framework\TestCase;

/**
 * Oracle du scan initial : empreinte sha256 de TOUS les fichiers du dépôt, hors dossiers internes,
 * portable (clés normalisées `/`), déterministe.
 */
final class ScannerTest extends TestCase
{
    private string $fonds;

    protected function setUp(): void
    {
        $this->fonds = SupportDepot::dossier('scanner');
    }

    protected function tearDown(): void
    {
        SupportDepot::supprimer($this->fonds);
    }

    public function testLeScanEmpreinteTousLesFichiersVisibles(): void
    {
        SupportDepot::ecrire($this->fonds . '/2026/facture.txt', 'facture');
        SupportDepot::ecrire($this->fonds . '/2026/facture.txt.meta.json', '{"titre":"Facture"}');
        SupportDepot::ecrire($this->fonds . '/divers/notes.md', '# Notes');

        $scan = (new Scanner($this->fonds))->scan();

        self::assertSame(
            ['2026/facture.txt', '2026/facture.txt.meta.json', 'divers/notes.md'],
            array_keys($scan),
        );
        self::assertSame(hash('sha256', 'facture'), $scan['2026/facture.txt']['empreinte']);
        self::assertSame(7, $scan['2026/facture.txt']['taille']);
    }

    public function testLeScanIgnoreLeDossierInterneEtLesDossiersMasques(): void
    {
        SupportDepot::ecrire($this->fonds . '/vrai.txt', 'vrai');
        SupportDepot::ecrire($this->fonds . '/.depot-fileserver/empreintes.json', '{}');
        SupportDepot::ecrire($this->fonds . '/.vrai.txt/v001_deadbeef.txt', 'ancienne version');
        SupportDepot::ecrire($this->fonds . '/Thumbs.db', 'bruit OS');

        $scan = (new Scanner($this->fonds))->scan();

        self::assertSame(['vrai.txt'], array_keys($scan));
    }

    public function testLeScanEstDeterministeEtPortable(): void
    {
        SupportDepot::ecrire($this->fonds . '/b/x.txt', 'x');
        SupportDepot::ecrire($this->fonds . '/a/y.txt', 'y');

        $premier = (new Scanner($this->fonds))->scan();
        $second = (new Scanner($this->fonds))->scan();

        self::assertSame(array_keys($premier), array_keys($second));
        self::assertSame($premier, $second);
        self::assertStringNotContainsString('\\', implode('|', array_keys($premier)));
    }

    public function testUnDepotAbsentEstRefuseAvecLaCause(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('dépôt absent');
        (new Scanner($this->fonds . '/nulle-part'))->scan();
    }
}

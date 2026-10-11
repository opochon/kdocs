<?php

declare(strict_types=1);

namespace Ged\Tests\Edition;

use Ged\Edition\Formats;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Oracle de la liste BLANCHE des formats éditables, côté serveur (source unique `Ged\Edition\Formats`).
 *
 * Même liste que l'agent local : le bouton « Ouvrir dans Word » ne doit apparaître que pour ces formats,
 * et l'API d'ouverture/de dépôt de version doit refuser les autres (403 nommé). Les formats à macros
 * Office Open XML (`docm`, `xlsm`, `pptm`, et `xlsb` qui peut en contenir) sont explicitement exclus,
 * ainsi que les modèles (`dot`, …) et tous les exécutables ou scripts.
 */
final class FormatsTest extends TestCase
{
    public function testLaListeBlancheEstExactementCelleDuCahierDesCharges(): void
    {
        self::assertSame(
            ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'csv'],
            Formats::EXTENSIONS_EDITABLES,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function nomsRefuses(): iterable
    {
        foreach ([
            'facture.exe', 'FACTURE.EXE', 'script.com', 'lancer.bat', 'lancer.cmd', 'profil.ps1',
            'app.js', 'macro.vbs', 'raccourci.lnk', 'site.url', 'page.hta', 'programme.msi',
            'ecran.scr', 'facture.pdf.exe', 'nom.docx:x.exe', 'sans-extension', 'document',
            'courrier.docm', 'courrier.dot', 'courrier.dotx', 'classeur.xlsm', 'classeur.xlsb',
            'diapo.pptm', 'modele.potm',
        ] as $nom) {
            yield $nom => [$nom];
        }
    }

    #[DataProvider('nomsRefuses')]
    public function testUnNomHorsListeBlancheEstRefuse(string $nom): void
    {
        self::assertFalse(Formats::editable($nom), "nom accepté à tort : $nom");
        self::assertNotSame('', Formats::motif($nom));
    }

    /** @return iterable<string, array{string}> */
    public static function nomsAcceptes(): iterable
    {
        foreach (['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'csv'] as $extension) {
            yield $extension => ['rapport.' . $extension];
            yield strtoupper($extension) => ['rapport.' . strtoupper($extension)];
        }
        yield 'double extension docx' => ['facture.pdf.docx'];
    }

    #[DataProvider('nomsAcceptes')]
    public function testUnNomDeLaListeBlancheEstAccepte(string $nom): void
    {
        self::assertTrue(Formats::editable($nom), "nom refusé à tort : $nom");
    }

    public function testLeLibelleSuitLeGroupeDuFormat(): void
    {
        self::assertSame('Ouvrir dans Word', Formats::libelle('rapport.docx'));
        self::assertSame('Ouvrir dans Word', Formats::libelle('note.odt'));
        self::assertSame('Ouvrir dans Excel', Formats::libelle('classeur.xlsx'));
        self::assertSame('Ouvrir dans Excel', Formats::libelle('export.csv'));
        self::assertSame('Ouvrir dans PowerPoint', Formats::libelle('diapo.pptx'));
        self::assertSame('Ouvrir dans PowerPoint', Formats::libelle('diaporama.odp'));
        self::assertSame('Ouvrir sur le poste', Formats::libelle('facture.exe'));
    }
}

<?php

declare(strict_types=1);

namespace Ged\Tests;

use Ged\Ui\ExtraitsLisibles;
use PHPUnit\Framework\TestCase;

/**
 * V2-10c — les extraits « Documents semblables » ne doivent plus montrer de texte OCR brut illisible.
 * L'heuristique est sobre : une proportion de lettres suffisante ET une majorité de mots contenant une
 * voyelle (un mot français en porte toujours). Elle est testée sur du vrai texte et sur deux formes de
 * bruit (symboles, consonnes).
 */
final class ExtraitsLisiblesTest extends TestCase
{
    public function testUnVraiTexteFrancaisEstLisible(): void
    {
        self::assertTrue(ExtraitsLisibles::lisible(
            'Facture AN-5001 du 12.01.2026, émetteur Atelier Numérique Sàrl, montant 4 244,74 CHF.'));
        self::assertTrue(ExtraitsLisibles::lisible(
            '… le présent contrat de maintenance court jusqu’au 31 décembre 2026 …'));
    }

    public function testUnBruitDeSymbolesNestPasLisible(): void
    {
        self::assertFalse(ExtraitsLisibles::lisible('@@@ x7#qZ |||| 8O0 l1I ~~~ ## /// \\\\ *'));
    }

    public function testUnBruitDeConsonnesNestPasLisible(): void
    {
        self::assertFalse(ExtraitsLisibles::lisible('bcdfg hjklm npqrs tvwxz bcdfg hjklm npqrs tvwxz'));
    }

    public function testUnExtraitTropCourtNestPasJugeLisible(): void
    {
        self::assertFalse(ExtraitsLisibles::lisible(''));
        self::assertFalse(ExtraitsLisibles::lisible('ab'));
    }

    public function testRetenirNeGardeQueLesExtraitsLisiblesEtBorneLeNombre(): void
    {
        $extraits = [
            [['t' => '@@@ 7#qZ |||| ~~~', 'hit' => true]],
            [['t' => 'Facture AN-5001 du 12.01.2026, émise par Atelier Numérique Sàrl.', 'hit' => true]],
            [['t' => 'bcdfg hjklm npqrs tvwxz bcdfg hjklm', 'hit' => false]],
            [['t' => 'Le contrat de maintenance court jusqu’au 31 décembre 2026.', 'hit' => false]],
            [['t' => 'Troisième extrait lisible mais au-delà de la borne.', 'hit' => false]],
        ];

        $gardes = ExtraitsLisibles::retenir($extraits, 2);

        self::assertCount(2, $gardes, 'la borne est respectée');
        self::assertSame('Facture AN-5001 du 12.01.2026, émise par Atelier Numérique Sàrl.', $gardes[0][0]['t']);
        self::assertSame('Le contrat de maintenance court jusqu’au 31 décembre 2026.', $gardes[1][0]['t']);
    }

    public function testRetenirConserveLesSegmentsEtLeursMarques(): void
    {
        $extraits = [[['t' => 'Contrat de ', 'hit' => false], ['t' => 'maintenance', 'hit' => true], ['t' => ' 2026.', 'hit' => false]]];

        $gardes = ExtraitsLisibles::retenir($extraits, 3);

        self::assertCount(1, $gardes);
        self::assertSame($extraits[0], $gardes[0], 'les segments et leurs surlignages sont intacts');
    }
}

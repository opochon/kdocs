<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Apercu\Outils;
use Ged\Ingestion\ParseurPiece;
use Ged\Ingestion\RegistreParseurs;
use PHPUnit\Framework\TestCase;

/**
 * Famille « parseurs » du lot ged-parite-chaine-1 : `ParseurPiece` répond aux mêmes questions que
 * `DocumentParser` et ses sous-classes Paperless (métadonnées, pages, texte, vignette) en réutilisant
 * `Apercu`, `RegistreParseurs` et le moteur d'ingestion. Les cas purement Python (OCR Tesseract,
 * Gotenberg/Tika, PDF chiffré) sont listés comme écarts dans la note de lot.
 */
final class ChaineParseursTest extends TestCase
{
    private function corpus(string $relatif): string
    {
        return dirname(__DIR__, 2) . '/corpus/' . $relatif;
    }

    public function testCompteLesPagesDunPdf(): void
    {
        self::assertSame(
            1,
            ParseurPiece::nombreDePages($this->corpus('apercu/documents/201-facture-an-5001.pdf'), 'application/pdf'),
        );
        self::assertSame(
            100,
            ParseurPiece::nombreDePages($this->corpus('courrier-100p/courrier-du-matin-100p.pdf'), 'application/pdf'),
        );
        self::assertNull(ParseurPiece::nombreDePages('C:/nulle-part/x.pdf', 'application/pdf'));
    }

    public function testLitUnTexteLocalEtRemplaceLesOctetsInvalides(): void
    {
        $fichier = (string) tempnam(sys_get_temp_dir(), 'gedv2_texte_');
        try {
            file_put_contents($fichier, "Ceci est un texte de test.\n");
            self::assertSame("Ceci est un texte de test.\n", ParseurPiece::texte($fichier, 'text/plain'));

            file_put_contents($fichier, "Pantothens\xe4ure\n");   // octet non UTF-8, comme le test Paperless
            $texte = ParseurPiece::texte($fichier, 'text/plain');
            self::assertNotNull($texte);
            self::assertTrue(mb_check_encoding($texte, 'UTF-8'), 'un texte mal encodé reste lisible');
            self::assertStringContainsString('Pantothens', $texte);
        } finally {
            @unlink($fichier);
        }
    }

    public function testMetadonneesDunePiece(): void
    {
        $pdf = $this->corpus('apercu/documents/201-facture-an-5001.pdf');
        self::assertSame(
            ['mime' => 'application/pdf', 'famille' => RegistreParseurs::RASTERISE, 'pages' => 1, 'texte_lu' => false],
            ParseurPiece::metadonnees($pdf, 'application/pdf'),
        );
        self::assertSame(RegistreParseurs::TEXTE, ParseurPiece::famille('text/plain'));
        self::assertSame(RegistreParseurs::BUREAUTIQUE, ParseurPiece::famille('text/rtf'));
        self::assertNull(ParseurPiece::famille('text/inconnu'));
    }

    public function testVignetteDelegueALapercuSansEchecMuet(): void
    {
        $pdf = $this->corpus('apercu/documents/201-facture-an-5001.pdf');
        $dest = sys_get_temp_dir() . '/gedv2_vignette_' . bin2hex(random_bytes(4)) . '.png';
        $message = ParseurPiece::vignette($pdf, 'application/pdf', $dest);
        if ($message === null) {
            self::assertFileExists($dest, 'une vignette rendue doit exister');
            @unlink($dest);
        } else {
            self::assertStringStartsWith('aperçu indisponible', $message, 'un échec de vignette se nomme');
        }
        // Un fichier absent ne peut pas produire de vignette : message nommé, jamais une exception.
        self::assertSame(Outils::MSG_CONVERSION, ParseurPiece::vignette('C:/nulle-part/x.pdf', 'application/pdf', $dest));
    }
}

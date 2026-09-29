<?php

declare(strict_types=1);

namespace Ged\Tests\Apercu;

use Ged\Apercu\Miniature;
use Ged\Apercu\Outils;
use Ged\Apercu\Source;
use PHPUnit\Framework\TestCase;

/**
 * La miniature de première page est un artefact HORS requête : on la génère ici comme le fera
 * `tools/miniatures.php`, et elle doit être un PNG affichable et léger.
 */
final class MiniatureTest extends TestCase
{
    private static string $corpus;
    private string $travail;

    public static function setUpBeforeClass(): void
    {
        self::$corpus = dirname(__DIR__, 2) . '/corpus/apercu/documents';
        if (!is_file(self::$corpus . '/204-contrat-maintenance.pdf.meta.json')) {
            self::fail('Corpus absent ou non généré : lancez `php tests/corpus/apercu/generer.php`.');
        }
    }

    protected function setUp(): void
    {
        $this->travail = sys_get_temp_dir() . '/gedv2-vignette-' . bin2hex(random_bytes(4));
        mkdir($this->travail, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->travail . '/*') ?: []);
        @rmdir($this->travail);
    }

    public function testLeDossierDesVignettesEstCacheACoteDesDocuments(): void
    {
        $source = new Source(self::$corpus);

        $dossier = Miniature::dossier($source);
        self::assertStringEndsWith('/.apercu', str_replace('\\', '/', $dossier));

        $piece = new \Ged\Apercu\Piece(204, self::$corpus . '/204-contrat-maintenance.pdf', 'x', 'pdf', 'pdf', 3);
        self::assertSame($dossier . '/204.vignette.png', Miniature::cheminVignette($piece, $dossier));
    }

    public function testGenereUneVignetteDepuisUnPdf(): void
    {
        if (Outils::pdftoppm() === null && Outils::ghostscript() === null) {
            self::markTestSkipped('Ni pdftoppm ni Ghostscript sur ce poste : le message nommé est testé ailleurs.');
        }

        $piece = (new Source(self::$corpus))->parId(204);
        self::assertNotNull($piece);
        $dest = $this->travail . '/204.png';

        $raison = (new Miniature())->generer($piece, $dest);

        self::assertNull($raison, 'génération attendue : ' . (string) $raison);
        self::assertFileExists($dest);
        $taille = getimagesize($dest);
        self::assertNotFalse($taille);
        self::assertSame(IMAGETYPE_PNG, $taille[2]);
        self::assertLessThanOrEqual(420, $taille[0], 'la vignette reste légère');
    }

    public function testGenereUneVignetteDepuisUneImage(): void
    {
        $piece = (new Source(self::$corpus))->parId(206);
        self::assertNotNull($piece, 'pièce image attendue dans le corpus');
        $dest = $this->travail . '/206.png';

        $raison = (new Miniature())->generer($piece, $dest);

        self::assertNull($raison, 'génération attendue : ' . (string) $raison);
        self::assertFileExists($dest);
        self::assertNotFalse(getimagesize($dest));
    }
}

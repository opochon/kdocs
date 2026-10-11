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

    /**
     * Poste sans poppler (cas du juge) : le rendu retombe sur Ghostscript. Ce repli figeait `-r150`,
     * donc une A4 sortait à 1240 px de large — au-delà de la limite de 420 px. On force le repli en
     * simulant l'absence de pdftoppm, sinon un poste pourvu de poppler masque le défaut.
     */
    public function testLaVignetteDuRepliGhostscriptRespecteLaTaille(): void
    {
        if (Outils::ghostscript() === null) {
            self::markTestSkipped('Ghostscript absent : le repli de rendu n’est pas exerçable.');
        }

        $piece = (new Source(self::$corpus))->parId(204);
        self::assertNotNull($piece);
        $dest = $this->travail . '/204-repli.png';

        $raison = $this->avecGhostscriptSeul(static fn (): ?string => (new Miniature())->generer($piece, $dest));

        self::assertNull($raison, 'génération attendue : ' . (string) $raison);
        self::assertFileExists($dest);
        $taille = getimagesize($dest);
        self::assertNotFalse($taille);
        self::assertSame(IMAGETYPE_PNG, $taille[2]);
        self::assertLessThanOrEqual(420, $taille[0], 'le repli reste léger en largeur');
        self::assertLessThanOrEqual(420, $taille[1], 'le repli reste léger en hauteur');
    }

    /**
     * `-dPDFFitPage` plaquait la page sur le format par défaut du device : une page paysage sortait
     * redressée en portrait. Le repli doit conserver l'orientation du MediaBox.
     */
    public function testLeRepliGhostscriptConserveLORientationDUnePagePaysage(): void
    {
        if (Outils::ghostscript() === null) {
            self::markTestSkipped('Ghostscript absent : le repli de rendu n’est pas exerçable.');
        }

        $pdf = $this->travail . '/paysage.pdf';
        $this->ecrirePdfPaysage($pdf);
        $dest = $this->travail . '/paysage.png';

        $raison = $this->avecGhostscriptSeul(static fn (): ?string => Outils::pdfVersPng($pdf, $dest, 400));

        self::assertNull($raison, 'génération attendue : ' . (string) $raison);
        $taille = getimagesize($dest);
        self::assertNotFalse($taille);
        self::assertLessThanOrEqual(420, $taille[0], 'le repli reste léger en largeur');
        self::assertLessThanOrEqual(420, $taille[1], 'le repli reste léger en hauteur');
        self::assertGreaterThan($taille[1], $taille[0], 'une page paysage reste paysage');
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

    /**
     * Exécute un rendu en forçant le repli Ghostscript, comme sur un poste sans poppler, puis
     * restaure la détection d'outils pour ne pas polluer les autres tests.
     */
    private function avecGhostscriptSeul(callable $rendu): mixed
    {
        $cache = new \ReflectionProperty(Outils::class, 'cache');
        $avant = $cache->getValue();
        $cache->setValue(null, ['pdftoppm' => null]);
        try {
            return $rendu();
        } finally {
            $cache->setValue(null, $avant);
        }
    }

    /** Écrit un PDF paysage synthétique (MediaBox 842 × 595 points), sans toucher au corpus. */
    private function ecrirePdfPaysage(string $fichier): void
    {
        $contenu = "BT /F1 24 Tf 60 500 Td (PLAN FICTIF - PAYSAGE) Tj ET\n";
        $objets = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595]'
                . ' /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            4 => '<< /Length ' . strlen($contenu) . " >>\nstream\n" . $contenu . 'endstream',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objets as $numero => $corps) {
            $offsets[$numero] = strlen($pdf);
            $pdf .= "$numero 0 obj\n$corps\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objets) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer' . "\n<< /Size " . (count($objets) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
        file_put_contents($fichier, $pdf);
    }
}

<?php

declare(strict_types=1);

namespace Ged\Tests\Ingestion;

use Ged\Ingestion\EditeurPdf;
use PHPUnit\Framework\TestCase;

/** Oracle V2-12a : les gestes changent les vrais octets PDF, jamais seulement l'aperçu. */
final class EditeurPdfTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/gedv2-edition-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0775, true);
        $script = "from pypdf import PdfWriter\nw=PdfWriter()\n[w.add_blank_page(width=595,height=842) for _ in range(6)]\nw.write(open(r'" . str_replace('\\', '\\\\', $this->tmp . '/six.pdf') . "','wb'))\n";
        $this->python($script);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $fichier) {
            @unlink($fichier);
        }
        @rmdir($this->tmp);
    }

    public function testTournerScinderFusionnerEtRetirerReecriventDesPdfValides(): void
    {
        $source = $this->tmp . '/six.pdf';
        $tourne = $this->tmp . '/tourne.pdf';
        $gauche = $this->tmp . '/gauche.pdf';
        $droite = $this->tmp . '/droite.pdf';
        $fusion = $this->tmp . '/fusion.pdf';
        $retire = $this->tmp . '/retire.pdf';
        $blanche = $this->tmp . '/blanche.pdf';

        EditeurPdf::tourner($source, $tourne, 2, 90);
        self::assertSame(6, EditeurPdf::compterPages($tourne));
        self::assertSame(90, EditeurPdf::rotation($tourne, 2));

        EditeurPdf::scinder($tourne, $gauche, $droite, 3);
        self::assertSame(3, EditeurPdf::compterPages($gauche));
        self::assertSame(3, EditeurPdf::compterPages($droite));

        EditeurPdf::fusionner([$gauche, $droite], $fusion);
        self::assertSame(6, EditeurPdf::compterPages($fusion));

        EditeurPdf::retirer($fusion, $retire, $blanche, 4);
        self::assertSame(5, EditeurPdf::compterPages($retire));
        self::assertSame(1, EditeurPdf::compterPages($blanche));
    }

    private function python(string $script): void
    {
        $resultat = proc_open(['python', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($resultat);
        $erreur = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($resultat), $erreur);
    }
}

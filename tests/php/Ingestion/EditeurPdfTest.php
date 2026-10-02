<?php

declare(strict_types=1);

namespace Ged\Tests\Ingestion;

use Ged\Config;
use Ged\Ingestion\EditeurPdf;
use PHPUnit\Framework\TestCase;

/** Oracle V2-12a : les gestes changent les vrais octets PDF, jamais seulement l'aperçu. */
final class EditeurPdfTest extends TestCase
{
    private string $tmp;
    private EditeurPdf $pdf;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/gedv2-edition-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0775, true);
        $this->pdf = EditeurPdf::depuisConfig(new Config(dirname(__DIR__, 3)));
        $script = "import fitz\ndoc=fitz.open()\n[doc.new_page(width=595,height=842) for _ in range(6)]\ndoc.save(r'" . str_replace('\\', '\\\\', $this->tmp . "/six.pdf") . "')\n";
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

        $this->pdf->executer(['action' => 'turn', 'source' => $source, 'output' => $tourne, 'page' => 2, 'degrees' => 90]);
        self::assertSame(6, $this->pages($tourne));
        self::assertSame(90, $this->rotation($tourne, 2));

        $this->pdf->executer(['action' => 'split', 'source' => $tourne, 'left' => $gauche, 'right' => $droite, 'after' => 3]);
        self::assertSame(3, $this->pages($gauche));
        self::assertSame(3, $this->pages($droite));

        $this->pdf->executer(['action' => 'merge', 'sources' => [$gauche, $droite], 'output' => $fusion]);
        self::assertSame(6, $this->pages($fusion));

        $this->pdf->executer(['action' => 'remove', 'source' => $fusion, 'output' => $retire, 'removed' => $blanche, 'page' => 4]);
        self::assertSame(5, $this->pages($retire));
        self::assertSame(1, $this->pages($blanche));
    }

    /** Le script complet est chargé par le Python CONFIGURÉ : tout import absent le fait échouer. */
    public function testLeMoteurNImporteQueDesModulesDuPythonConfigure(): void
    {
        self::assertTrue($this->pdf->diagnostic()['disponible']);
        $this->pdf->executer(['action' => 'count', 'source' => $this->tmp . '/six.pdf']);
    }

    public function testUneTraceDeModuleAbsentNeSortJamaisDuMessageUtilisateur(): void
    {
        $erreur = new \RuntimeException("Traceback (most recent call last):\nModuleNotFoundError: No module named 'fitz'");

        self::assertSame(EditeurPdf::MOTEUR_ABSENT, EditeurPdf::messageUtilisateur($erreur));
    }

    public function testDiagnosticNExporteJamaisUneErreurTechnique(): void
    {
        $diagnostic = (new EditeurPdf($this->tmp . '/python-introuvable'))->diagnostic();

        self::assertFalse($diagnostic['disponible']);
        self::assertSame(EditeurPdf::MOTEUR_ABSENT, $diagnostic['message']);
    }

    public function testInstallateurRefuseExplicitementUnMoteurPdfAbsent(): void
    {
        $processus = proc_open([PHP_BINARY, dirname(__DIR__, 3) . '/tools/installer.php'], [
            1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, null, ['CMD4_PYTHON' => $this->tmp . '/python-introuvable']);
        self::assertIsResource($processus);
        $sortie = stream_get_contents($pipes[1]);
        $erreur = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(1, proc_close($processus));
        self::assertSame('', $sortie);
        self::assertStringContainsString(EditeurPdf::MOTEUR_ABSENT, $erreur);
        self::assertStringContainsString('PyMuPDF dans CMD4_PYTHON', $erreur);
        self::assertStringNotContainsString('Traceback', $erreur);
    }

    private function pages(string $fichier): int
    {
        return (int) ($this->pdf->executer(['action' => 'count', 'source' => $fichier])['pages'] ?? 0);
    }

    private function rotation(string $fichier, int $page): int
    {
        return (int) ($this->pdf->executer(['action' => 'rotation', 'source' => $fichier, 'page' => $page])['rotation'] ?? -1);
    }

    private function python(string $script): void
    {
        $config = new Config(dirname(__DIR__, 3));
        $resultat = proc_open([$config->get('CMD4_PYTHON'), '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($resultat);
        $erreur = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($resultat), $erreur);
    }
}

<?php

declare(strict_types=1);

namespace Ged\Tests\Ingestion;

use Ged\Ingestion\CopieArchivage;
use PHPUnit\Framework\TestCase;

/**
 * `php tools/tache.php pdfa` : la tâche de fond qui rattrape le fonds déjà présent. Elle doit être bornée,
 * reprenable et IDEMPOTENTE : un second appel ne refait aucune copie déjà juste (0 doublon).
 */
final class TachePdfATest extends TestCase
{
    use SupportPdfA;

    protected function setUp(): void
    {
        $this->setUpPdfA();
    }

    protected function tearDown(): void
    {
        $this->tearDownPdfA();
    }

    public function testLaTachePdfaProduitLesCopiesPuisNeRefaitRien(): void
    {
        $this->ghostscriptPdfA();
        $id = $this->deposerPdfA('rattrapage.pdf', $this->pdfContenu('TACHE PDFA 1'));
        $this->deposerPdfA('note.txt', "non applicable\n");

        $cles = ['STORAGE_ROOT', 'INGESTION_TRAVAIL', 'DB_NAME'];
        $avant = array_combine($cles, array_map(static fn (string $c): string|false => getenv($c), $cles));
        putenv('STORAGE_ROOT=' . $this->fondsPdfA());
        putenv('INGESTION_TRAVAIL=' . $this->bacPdfA() . '/travail-cli');
        try {
            [$code, $sortie, $erreur] = $this->tache('--lot=500', '--tours=5');
            self::assertSame(0, $code, $erreur . $sortie);
            self::assertStringContainsString('"tache":"pdfa"', str_replace(' ', '', $sortie));
            $copie = CopieArchivage::cheminCopie($this->absoluPdfA($id));
            self::assertFileExists($copie);

            $empreinte = (string) hash_file('sha256', $copie);
            [$code2, $sortie2, $erreur2] = $this->tache('--lot=500', '--tours=5');
            self::assertSame(0, $code2, $erreur2 . $sortie2);
            self::assertSame($empreinte, (string) hash_file('sha256', $copie), 'la seconde passe ne refait pas la copie');
            self::assertCount(1, glob(dirname($copie) . '/*') ?: [], 'zéro doublon dans .pdfa/');
        } finally {
            foreach ($avant as $cle => $valeur) {
                $valeur === false ? putenv($cle) : putenv("$cle=$valeur");
            }
        }
    }

    /** @return array{0:int, 1:string, 2:string} */
    private function tache(string ...$options): array
    {
        $commande = array_merge([PHP_BINARY, dirname(__DIR__, 3) . '/tools/tache.php', 'pdfa'], $options);
        $processus = proc_open($commande, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuyaux);
        self::assertIsResource($processus);
        $sortie = (string) stream_get_contents($tuyaux[1]);
        $erreur = (string) stream_get_contents($tuyaux[2]);
        fclose($tuyaux[1]);
        fclose($tuyaux[2]);

        return [proc_close($processus), $sortie, $erreur];
    }

    private function pdfContenu(string $marqueur): string
    {
        $fichier = $this->bacPdfA() . '/depots/' . bin2hex(random_bytes(4)) . '.pdf';
        $this->pdfTextePdfA($fichier, $marqueur, 1);

        return (string) file_get_contents($fichier);
    }
}

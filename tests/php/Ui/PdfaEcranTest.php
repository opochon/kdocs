<?php

declare(strict_types=1);

namespace Ged\Tests\Ui;

use Ged\Ingestion\CopieArchivage;
use Ged\Ingestion\RattrapagePdfA;
use Ged\Kernel;
use Ged\Tests\Ingestion\SupportPdfA;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * L'écran montre l'état RÉEL de la copie d'archivage PDF/A : la fiche document dit « PDF/A 2b » et propose
 * le téléchargement, l'écran Application montre l'avancement du rattrapage. On assène sur le HTML rendu par
 * la route réelle (ce que l'utilisateur voit), jamais sur le seul état du service.
 */
final class PdfaEcranTest extends TestCase
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

    public function testLaFicheDocumentMontreLaCopiePdfAEtPermetDeLaTelecharger(): void
    {
        $this->ghostscriptPdfA();
        $id = $this->deposerPdfA('facture.pdf', $this->pdfContenu('FICHE AVEC COPIE PDFA'));
        CopieArchivage::depuis($this->configPdfA())->pour($this->absoluPdfA($id), $this->relatifPdfA($id), $id);

        [$code, $html] = $this->rendre('/documents/' . $id);

        self::assertSame(200, $code, $html);
        self::assertStringContainsString('data-testid="pdfa-etat"', $html);
        self::assertStringContainsString('data-testid="pdfa-statut-ok"', $html);
        self::assertStringContainsString('data-testid="pdfa-telecharger"', $html);
        self::assertStringContainsString('/documents/' . $id . '/pdfa', $html, 'le lien de téléchargement vise la copie');
    }

    public function testLaFicheDocumentNommeUnEchecDeCopie(): void
    {
        $this->ghostscriptPdfA();
        $corrompu = $this->bacPdfA() . '/depots/casse.pdf';
        $this->pdfCorrompuPdfA($corrompu);
        $id = $this->deposerPdfA('casse.pdf', (string) file_get_contents($corrompu));
        CopieArchivage::depuis($this->configPdfA())->pour($this->absoluPdfA($id), $this->relatifPdfA($id), $id);

        [$code, $html] = $this->rendre('/documents/' . $id);

        self::assertSame(200, $code, $html);
        self::assertStringContainsString('data-testid="pdfa-statut-echec"', $html);
        self::assertStringContainsString('data-testid="pdfa-raison"', $html);
    }

    public function testLEcranApplicationMontreLeRattrapageTermine(): void
    {
        $this->ghostscriptPdfA();
        $this->deposerPdfA('un.pdf', $this->pdfContenu('RATTRAPAGE ECRAN UN'));
        $this->deposerPdfA('note.txt', "non applicable\n");
        $job = new RattrapagePdfA($this->configPdfA());
        $lance = $job->lancer('administrateur');
        $etat = $job->avancer((int) $lance['id'], 500);
        self::assertSame('termine', $etat['etat']);

        [$code, $html] = $this->rendre('/application');

        self::assertSame(200, $code, $html);
        self::assertStringContainsString('data-testid="pdfa-rattrapage"', $html);
        self::assertStringContainsString('data-testid="pdfa-terminee"', $html);
        self::assertStringContainsString('data-testid="pdfa-progression"', $html);
        self::assertMatchesRegularExpression('/data-testid="pdfa-progression"[^>]*>\s*1\s*\/\s*1/s', $html,
            'l’avancement affiché porte son compte (1 sur 1)');
    }

    /** @return array{0:int, 1:string} */
    private function rendre(string $chemin): array
    {
        $reponse = Kernel::creer(dirname(__DIR__, 3))->handle(
            (new ServerRequestFactory())->createServerRequest('GET', $chemin));

        return [$reponse->getStatusCode(), (string) $reponse->getBody()];
    }

    private function pdfContenu(string $marqueur): string
    {
        $fichier = $this->bacPdfA() . '/depots/' . bin2hex(random_bytes(4)) . '.pdf';
        $this->pdfTextePdfA($fichier, $marqueur, 1);

        return (string) file_get_contents($fichier);
    }
}

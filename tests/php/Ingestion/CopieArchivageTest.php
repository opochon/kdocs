<?php

declare(strict_types=1);

namespace Ged\Tests\Ingestion;

use Ged\Archivage\Garde;
use Ged\Archivage\HorlogeFigee;
use Ged\Archivage\Retention;
use Ged\Archivage\Scelleur;
use Ged\Archivage\TsaSimulee;
use Ged\Archivage\Verificateur;
use Ged\Ingestion\ControlePdfA;
use Ged\Ingestion\CopieArchivage;
use Ged\Ingestion\RattrapagePdfA;
use PHPUnit\Framework\TestCase;

/**
 * Oracle du lot ged-pdfa-1 : copie d'archivage PDF/A-2b à côté de l'original, sans jamais modifier la pièce
 * qui fait foi. Corpus 100 % synthétique (PDF texte, PDF scanné, image JPEG, PDF chiffré, PDF corrompu).
 */
final class CopieArchivageTest extends TestCase
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

    public function testUnPdfTexteDonneUneCopiePdfA2bAvecXmpOutputIntentEtTexteConserve(): void
    {
        $this->ghostscriptPdfA();
        $id = $this->deposerPdfA('facture.pdf', $this->pdfTexteContenu('FACTURE SYNTHETIQUE 5001'));
        $absolu = $this->absoluPdfA($id);
        $empreinteAvant = (string) hash_file('sha256', $absolu);

        $bloc = CopieArchivage::depuis($this->configPdfA())->pour($absolu, $this->relatifPdfA($id), $id);

        self::assertSame('ok', $bloc['statut']);
        self::assertSame('2b', $bloc['niveau']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $bloc['empreinte_sha256']);
        $copie = CopieArchivage::cheminCopie($absolu);
        self::assertFileExists($copie, 'la copie d’archivage vit à côté de l’original');
        self::assertSame($empreinteAvant, (string) hash_file('sha256', $absolu), 'l’original n’est jamais modifié');
        self::assertNotSame($empreinteAvant, (string) hash_file('sha256', $copie));

        $controle = ControlePdfA::controler($copie);
        self::assertTrue($controle['ok'], 'contrôle partiel : ' . $controle['raison']);
        self::assertSame('partiel', $controle['portee']);
        self::assertTrue($controle['points']['xmp_pdfa_part2']);
        self::assertTrue($controle['points']['xmp_conformance_b']);
        self::assertTrue($controle['points']['output_intent_srgb']);
        self::assertTrue($controle['points']['sans_chiffrement']);
        self::assertTrue($controle['points']['polices_incorporees']);
        self::assertStringContainsString('FACTURE SYNTHETIQUE 5001', $this->texteExtraitPdfA($copie), 'la couche texte est conservée');

        $meta = json_decode((string) file_get_contents($absolu . '.meta.json'), true);
        self::assertIsArray($meta);
        self::assertSame('ok', $meta['pdfa']['statut'] ?? null);
        self::assertSame($bloc['chemin'], $meta['pdfa']['chemin'] ?? null, 'le .meta.json porte le chemin de la copie');
        self::assertStringContainsString('.pdfa/', (string) $meta['pdfa']['chemin']);
        self::assertSame('2b', $meta['pdfa']['niveau'] ?? null);
    }

    public function testUneImageJpegDonneUneCopiePdfA2bDUnePageSansPolice(): void
    {
        $this->ghostscriptPdfA();
        $id = $this->deposerPdfA('recu.jpg', $this->imageJpegContenu(), ['titre' => 'Recu fictif']);
        $absolu = $this->absoluPdfA($id);

        $bloc = CopieArchivage::depuis($this->configPdfA())->pour($absolu, $this->relatifPdfA($id), $id);

        self::assertSame('ok', $bloc['statut']);
        $copie = CopieArchivage::cheminCopie($absolu);
        self::assertFileExists($copie);
        self::assertSame('pdf', strtolower(pathinfo($copie, PATHINFO_EXTENSION)));
        $controle = ControlePdfA::controler($copie);
        self::assertTrue($controle['ok'], $controle['raison']);
        self::assertTrue($controle['points']['polices_incorporees'], 'aucune police déclarée n’est pas un échec');
    }

    public function testUnPdfScanneSansTexteDonneUneCopiePdfAValide(): void
    {
        $this->ghostscriptPdfA();
        $scan = $this->bacPdfA() . '/depots/scan.pdf';
        $this->pdfScannePdfA($scan);
        $id = $this->deposerPdfA('scan.pdf', (string) file_get_contents($scan));
        $absolu = $this->absoluPdfA($id);

        $bloc = CopieArchivage::depuis($this->configPdfA())->pour($absolu, $this->relatifPdfA($id), $id);

        self::assertSame('ok', $bloc['statut']);
        $copie = CopieArchivage::cheminCopie($absolu);
        self::assertFileExists($copie);
        self::assertTrue(ControlePdfA::controler($copie)['ok']);
    }

    public function testUnPdfCorrompuEchoueProprementSansPerdreLeDocumentNiLaisserDeCopiePartielle(): void
    {
        $this->ghostscriptPdfA();
        $corrompu = $this->bacPdfA() . '/depots/casse.pdf';
        $this->pdfCorrompuPdfA($corrompu);
        $id = $this->deposerPdfA('casse.pdf', (string) file_get_contents($corrompu));
        $absolu = $this->absoluPdfA($id);

        $bloc = CopieArchivage::depuis($this->configPdfA())->pour($absolu, $this->relatifPdfA($id), $id);

        self::assertSame('echec', $bloc['statut']);
        self::assertNotSame('', (string) $bloc['raison']);
        self::assertFileExists($absolu, 'le document reste ingéré malgré l’échec');
        self::assertFileDoesNotExist(CopieArchivage::cheminCopie($absolu), 'aucune copie partielle');
        $meta = json_decode((string) file_get_contents($absolu . '.meta.json'), true);
        self::assertSame('echec', $meta['pdfa']['statut'] ?? null);
        self::assertNotSame('', (string) ($meta['pdfa']['raison'] ?? ''));
    }

    public function testUnPdfChiffreEchoueProprementAvecUneRaisonNommee(): void
    {
        $this->ghostscriptPdfA();
        $chiffre = $this->bacPdfA() . '/depots/chiffre.pdf';
        $this->pdfChiffrePdfA($chiffre, 'source');
        $id = $this->deposerPdfA('chiffre.pdf', (string) file_get_contents($chiffre));
        $absolu = $this->absoluPdfA($id);

        $bloc = CopieArchivage::depuis($this->configPdfA())->pour($absolu, $this->relatifPdfA($id), $id);

        self::assertSame('echec', $bloc['statut']);
        self::assertStringContainsStringIgnoringCase('chiffr', (string) $bloc['raison']);
        self::assertFileExists($absolu);
        self::assertFileDoesNotExist(CopieArchivage::cheminCopie($absolu));
    }

    public function testDeuxPassesDonnentLeMemeResultatZeroDoublonEtMetaInchangee(): void
    {
        $this->ghostscriptPdfA();
        $id = $this->deposerPdfA('double.pdf', $this->pdfTexteContenu('IDEMPOTENCE 42'));
        $absolu = $this->absoluPdfA($id);
        $service = CopieArchivage::depuis($this->configPdfA());
        $relatif = $this->relatifPdfA($id);

        $premier = $service->pour($absolu, $relatif, $id);
        $metaPremiere = (string) file_get_contents($absolu . '.meta.json');
        $copie = CopieArchivage::cheminCopie($absolu);
        $empreinteCopie = (string) hash_file('sha256', $copie);
        $mtime = (int) filemtime($copie);

        $second = $service->pour($absolu, $relatif, $id);

        self::assertSame($premier, $second, 'deux passes rendent exactement le même état');
        self::assertSame($metaPremiere, (string) file_get_contents($absolu . '.meta.json'), 'aucune réécriture inutile');
        self::assertSame($empreinteCopie, (string) hash_file('sha256', $copie), 'la copie n’est pas refaite');
        self::assertSame($mtime, (int) filemtime($copie));
        $copies = glob(dirname($absolu) . '/.pdfa/*') ?: [];
        self::assertCount(1, $copies, 'zéro doublon dans .pdfa/');
    }

    public function testLaCopieDUndocumentScelleEstPermiseEtNeCassePasLeSceau(): void
    {
        $this->ghostscriptPdfA();
        $id = $this->deposerPdfA('scelle.pdf', $this->pdfTexteContenu('DOCUMENT SCELLE 7'));
        $absolu = $this->absoluPdfA($id);
        $horloge = new HorlogeFigee('2026-10-11 08:00:00');
        $scelleur = new Scelleur($this->depotPdfA(), new TsaSimulee($horloge), $horloge, new Retention($this->fondsPdfA(), $horloge));
        $scelleur->sceller($id, 'juge');

        $metaAvant = (string) file_get_contents($absolu . '.meta.json');

        $bloc = CopieArchivage::depuis($this->configPdfA())->pour($absolu, $this->relatifPdfA($id), $id);

        self::assertSame('ok', $bloc['statut'], 'la copie d’un document scellé est permise');
        self::assertFileExists(CopieArchivage::cheminCopie($absolu));
        self::assertSame($metaAvant, (string) file_get_contents($absolu . '.meta.json'), 'le .meta.json scellé n’est pas réécrit');
        self::assertNotNull(Garde::estScelle($absolu, $id));
        $verification = (new Verificateur($this->fondsPdfA()))->verifierFonds();
        self::assertTrue($verification['ok'], 'le scellement reste valide : ' . implode(' ; ', $verification['ecarts']));
        // L'état est lisible depuis le flanc à côté (`.pdfa/<fichier>.pdfa.json`), pas depuis la meta scellée.
        $etat = CopieArchivage::depuis($this->configPdfA())->etat($absolu, $id);
        self::assertSame('ok', $etat['statut']);
        self::assertTrue($etat['telechargeable']);
    }

    public function testUnFormatNonApplicableNeProduitPasDeCopieEtNeDitPasEchec(): void
    {
        $id = $this->deposerPdfA('notes.txt', "texte brut synthetique\n", ['titre' => 'Note fictive']);
        $absolu = $this->absoluPdfA($id);

        $bloc = CopieArchivage::depuis($this->configPdfA())->pour($absolu, $this->relatifPdfA($id), $id);

        self::assertSame('non_applicable', $bloc['statut']);
        self::assertFileDoesNotExist(CopieArchivage::cheminCopie($absolu));
    }

    public function testLeRattrapageEstIdempotentReprenableEtCompteLEcart(): void
    {
        $this->ghostscriptPdfA();
        $this->deposerPdfA('un.pdf', $this->pdfTexteContenu('RATTRAPAGE UN'));
        $this->deposerPdfA('deux.pdf', $this->pdfTexteContenu('RATTRAPAGE DEUX'));
        $this->deposerPdfA('trois.jpg', $this->imageJpegContenu());
        $this->deposerPdfA('quatre.txt', "non applicable\n");

        $service = new RattrapagePdfA($this->configPdfA());
        $lance = $service->lancer('administrateur');
        self::assertSame(3, $lance['total'], 'seuls les PDF et images sont au périmètre');
        self::assertSame(0, $lance['traite']);

        $etat = $service->avancer((int) $lance['id'], 2);
        self::assertSame('en_cours', $etat['etat'], 'la première tranche ne finit pas tout');
        self::assertSame(2, $etat['traite']);
        $etat = $service->avancer((int) $lance['id'], 500);
        self::assertSame('termine', $etat['etat']);
        self::assertSame(3, $etat['traite']);

        $premieres = $this->copiesPdfA();
        self::assertCount(3, $premieres, 'trois copies, aucune pour le .txt');

        // Deuxième passe complète : même résultat, zéro doublon, mêmes empreintes.
        $relance = $service->lancer('administrateur');
        $etat2 = $service->avancer((int) $relance['id'], 500);
        self::assertSame('termine', $etat2['etat']);
        self::assertSame(3, $etat2['traite']);
        self::assertSame($premieres, $this->copiesPdfA(), 'aucune copie changée ni doublée');
        self::assertSame(0, $etat2['ecart']);
    }

    public function testLeRattrapageCompteLesDocumentsDisparusEnCoursDeRoute(): void
    {
        $this->ghostscriptPdfA();
        $id = $this->deposerPdfA('disparu.pdf', $this->pdfTexteContenu('DISPARU 1'));
        $garder = $this->deposerPdfA('garde.pdf', $this->pdfTexteContenu('GARDE 2'));
        $service = new RattrapagePdfA($this->configPdfA());
        $lance = $service->lancer('administrateur');
        self::assertSame(2, $lance['total']);
        // Le document disparaît du fonds entre le lancement et la tranche : l'écart est compté, jamais tu.
        unlink($this->absoluPdfA($id));
        unlink($this->absoluPdfA($id) . '.meta.json');
        $etat = $service->avancer((int) $lance['id'], 500);
        self::assertSame('termine', $etat['etat']);
        self::assertSame(1, $etat['ecart']);
        self::assertFileExists(CopieArchivage::cheminCopie($this->absoluPdfA($garder)));
    }

    /**
     * Le budget de temps par page est mesuré (et borné largement) : une régression grossière le fait rougir.
     * Mesure réelle consignée dans docs/lots/ged-pdfa-1.md.
     */
    public function testLeBudgetDeConversionParPageResteMesureEtBorne(): void
    {
        $this->ghostscriptPdfA();
        $id = $this->deposerPdfA('budget.pdf', $this->pdfTexteContenu('BUDGET PAGE', 5));
        $absolu = $this->absoluPdfA($id);
        $debut = microtime(true);
        $bloc = CopieArchivage::depuis($this->configPdfA())->pour($absolu, $this->relatifPdfA($id), $id);
        $secondes = microtime(true) - $debut;
        self::assertSame('ok', $bloc['statut']);
        self::assertLessThan(30.0, $secondes, 'budget : moins de 30 s pour 5 pages (soit < 6 s/page)');
    }

    public function testLEtatDUneCopieSansDocumentResteneutreSansInvention(): void
    {
        $service = CopieArchivage::depuis($this->configPdfA());

        $absent = $service->etat($this->fondsPdfA() . '/inconnu.pdf');

        self::assertSame('absent', $absent['statut']);
        self::assertSame('document introuvable', $absent['raison']);
        self::assertSame('', $absent['reprise']);
        self::assertFalse($absent['telechargeable']);
        self::assertFalse($service->aReprendre($this->fondsPdfA() . '/inconnu.pdf'));
    }

    private function pdfTexteContenu(string $marqueur): string
    {
        $fichier = $this->bacPdfA() . '/depots/' . bin2hex(random_bytes(4)) . '.pdf';
        $this->pdfTextePdfA($fichier, $marqueur, 1);

        return (string) file_get_contents($fichier);
    }

    private function imageJpegContenu(): string
    {
        $fichier = $this->bacPdfA() . '/depots/' . bin2hex(random_bytes(4)) . '.jpg';
        $this->imageJpegPdfA($fichier);

        return (string) file_get_contents($fichier);
    }

    /** @return list<string> empreintes des copies PDF/A du fonds éphémère, triées */
    private function copiesPdfA(): array
    {
        $copies = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->fondsPdfA(), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && str_contains(str_replace('\\', '/', $f->getPathname()), '/.pdfa/') && str_ends_with($f->getFilename(), '.pdf')) {
                $copies[] = (string) hash_file('sha256', $f->getPathname());
            }
        }
        sort($copies);

        return $copies;
    }
}

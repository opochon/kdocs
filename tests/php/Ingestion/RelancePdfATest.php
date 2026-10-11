<?php

declare(strict_types=1);

namespace Ged\Tests\Ingestion;

use Ged\Ingestion\ConversionPdfA;
use Ged\Ingestion\CopieArchivage;
use Ged\Ingestion\RattrapagePdfA;
use PHPUnit\Framework\TestCase;

/**
 * Oracle du lot pdfa-rattrapage-1 : une copie d'archivage PDF/A en échec pour une CAUSE D'ENVIRONNEMENT
 * (ici Ghostscript « absent ») doit pouvoir être refaite après réparation, sans jamais toucher à l'original.
 * Un échec DÉFINITIF (PDF corrompu, chiffré) n'est pas relancé par le produit. Le rattrapage est idempotent
 * et son compteur de reprises ne ment pas.
 *
 * Corpus 100 % synthétique, fonds éphémère, base gedv2_test (SupportPdfA).
 */
final class RelancePdfATest extends TestCase
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

    public function testUnEchecEnvironnementReprendSurRelanceExpliciteEtGardeLOriginalIntact(): void
    {
        $gs = $this->ghostscriptPdfA();
        $id = $this->deposerPdfA('relance.pdf', $this->pdfContenu('RELANCE ENVIRONNEMENT'));
        $absolu = $this->absoluPdfA($id);
        $relatif = $this->relatifPdfA($id);
        $empreinteAvant = (string) hash_file('sha256', $absolu);

        // Ghostscript « absent » : la cause est d'environnement, donc reprenable, et l'échec est nommé.
        $absent = new CopieArchivage($this->configPdfA(), new ConversionPdfA(null));
        $echec = $absent->pour($absolu, $relatif, $id);
        self::assertSame('echec', $echec['statut']);
        self::assertSame('auto', $echec['reprise'] ?? null, 'un outil absent est une cause reprenable');
        self::assertStringContainsString('Ghostscript absent', (string) $echec['raison']);
        self::assertFileDoesNotExist(CopieArchivage::cheminCopie($absolu), 'aucune copie partielle');
        self::assertSame($empreinteAvant, (string) hash_file('sha256', $absolu), 'l’original reste intact');

        // L'outil est « installé » : sans relance explicite, pour() ne refait rien (le constat du juge).
        $installe = new CopieArchivage($this->configPdfA(), new ConversionPdfA($gs));
        self::assertSame('echec', $installe->pour($absolu, $relatif, $id)['statut'], 'sans relance, l’échec persiste');
        self::assertFileDoesNotExist(CopieArchivage::cheminCopie($absolu));

        // Relance explicite : la copie est produite, l'original n'est jamais touché.
        $ok = $installe->pour($absolu, $relatif, $id, true);
        self::assertSame('ok', $ok['statut']);
        self::assertFileExists(CopieArchivage::cheminCopie($absolu), 'la relance produit la copie');
        self::assertSame($empreinteAvant, (string) hash_file('sha256', $absolu), 'l’original est inchangé après relance');
        $meta = json_decode((string) file_get_contents($absolu . '.meta.json'), true);
        self::assertSame('ok', $meta['pdfa']['statut'] ?? null, 'le .meta.json reflète la copie retrouvée');
    }

    public function testUnEchecDefinitifNEstPasRelanceParLeRattrapage(): void
    {
        $this->ghostscriptPdfA();
        $corrompu = $this->bacPdfA() . '/depots/casse.pdf';
        $this->pdfCorrompuPdfA($corrompu);
        $id = $this->deposerPdfA('casse.pdf', (string) file_get_contents($corrompu));
        $absolu = $this->absoluPdfA($id);

        $bloc = CopieArchivage::depuis($this->configPdfA())->pour($absolu, $this->relatifPdfA($id), $id);
        self::assertSame('echec', $bloc['statut']);
        self::assertSame('jamais', $bloc['reprise'] ?? null, 'un PDF corrompu n’est pas une cause reprenable');

        $job = new RattrapagePdfA($this->configPdfA(), ConversionPdfA::depuis());
        $lance = $job->lancer('administrateur');
        $etat = $job->avancer((int) $lance['id'], 500);

        self::assertSame('termine', $etat['etat']);
        self::assertSame(0, $etat['reprises'], 'aucune relance automatique d’un échec définitif');
        self::assertFileDoesNotExist(CopieArchivage::cheminCopie($absolu), 'toujours aucune copie');
        self::assertFileExists($absolu, 'le document reste ingéré');
        $meta = json_decode((string) file_get_contents($absolu . '.meta.json'), true);
        self::assertSame('echec', $meta['pdfa']['statut'] ?? null);
        self::assertNotSame('', (string) ($meta['pdfa']['raison'] ?? ''));
    }

    public function testLeRattrapageReprendUnEchecEnvironnementPuisResteIdempotent(): void
    {
        $gs = $this->ghostscriptPdfA();
        $id = $this->deposerPdfA('rattrapage.pdf', $this->pdfContenu('RATTRAPAGE REPRENABLE'));
        $absolu = $this->absoluPdfA($id);
        $relatif = $this->relatifPdfA($id);
        $empreinteAvant = (string) hash_file('sha256', $absolu);

        (new CopieArchivage($this->configPdfA(), new ConversionPdfA(null)))->pour($absolu, $relatif, $id);
        self::assertFileDoesNotExist(CopieArchivage::cheminCopie($absolu));

        // Ghostscript installé : le rattrapage reprend l'échec d'environnement de lui-même.
        $job = new RattrapagePdfA($this->configPdfA(), new ConversionPdfA($gs));
        $lance = $job->lancer('administrateur');
        $etat = $job->avancer((int) $lance['id'], 500);
        self::assertSame('termine', $etat['etat']);
        self::assertSame(1, $etat['reprises'], 'le rattrapage compte la relance effectuée');
        self::assertSame(0, $etat['ecart']);
        $copie = CopieArchivage::cheminCopie($absolu);
        self::assertFileExists($copie);
        self::assertSame($empreinteAvant, (string) hash_file('sha256', $absolu), 'l’original reste intact');
        self::assertSame('ok', json_decode((string) file_get_contents($absolu . '.meta.json'), true)['pdfa']['statut'] ?? null);

        // Deuxième passe : plus rien à reprendre, copie identique (idempotence, zéro doublon).
        $empreinteCopie = (string) hash_file('sha256', $copie);
        $relance = $job->lancer('administrateur');
        $etat2 = $job->avancer((int) $relance['id'], 500);
        self::assertSame('termine', $etat2['etat']);
        self::assertSame(0, $etat2['reprises'], 'la seconde passe ne reprend rien');
        self::assertSame($empreinteCopie, (string) hash_file('sha256', $copie), 'la copie n’est pas refaite');
        self::assertCount(1, glob(dirname($copie) . '/*') ?: [], 'zéro doublon dans .pdfa/');
    }

    private function pdfContenu(string $marqueur): string
    {
        $fichier = $this->bacPdfA() . '/depots/' . bin2hex(random_bytes(4)) . '.pdf';
        $this->pdfTextePdfA($fichier, $marqueur, 1);

        return (string) file_get_contents($fichier);
    }
}

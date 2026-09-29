<?php

declare(strict_types=1);

namespace Ged\Tests\Socle;

use Ged\Socle\DepotFichiers;
use PHPUnit\Framework\TestCase;

/**
 * Oracle V2-01 §6 : aucun DELETE sur une donnée utilisateur. La corbeille est un dossier `.corbeille/`
 * sous la racine + un drapeau sur la ligne ; la restauration remet le document à sa place, octet pour octet.
 */
final class CorbeilleTest extends TestCase
{
    use SupportCorpus;

    private DepotFichiers $depot;

    protected function setUp(): void
    {
        $this->setUpCorpus();
        $this->depot = new DepotFichiers($this->config);
    }

    protected function tearDown(): void
    {
        $this->tearDownCorpus();
    }

    public function testLaCorbeilleDeplaceDrapeauteEtNeffaceRien(): void
    {
        $id = $this->depot->deposer($this->pieceADeposer('pv.pdf', 'contenu original'), [
            'titre' => 'PV conseil', 'type' => 'pv', 'emetteur' => 'Atelier X', 'date' => '2026-09-01',
        ]);
        $rel = '2026/pv/Atelier_X/PV_conseil.pdf';

        $this->depot->miseALaCorbeille($id, 'olivia');

        self::assertFileDoesNotExist($this->racine . '/fonds/' . $rel, 'le document quitte le fonds');
        self::assertFileExists($this->racine . '/fonds/.corbeille/' . $rel, '…pour la corbeille, même arborescence');
        self::assertSame('contenu original', file_get_contents($this->racine . '/fonds/.corbeille/' . $rel),
            'le contenu est intact : déplacé, jamais effacé');
        self::assertFileExists($this->racine . '/fonds/.corbeille/' . $rel . '.meta.json', 'les métadonnées suivent');
        self::assertDirectoryExists($this->racine . '/fonds/.corbeille/2026/pv/Atelier_X/.versions/PV_conseil.pdf',
            'le dossier .versions suit le document');

        $ligne = $this->pdo->query('SELECT supprime_le, supprime_par, chemin_relatif FROM documents WHERE id = ' . $id)->fetch();
        self::assertNotNull($ligne['supprime_le'], 'drapeau corbeille posé');
        self::assertSame('olivia', $ligne['supprime_par']);
        self::assertSame($rel, $ligne['chemin_relatif'], 'la ligne garde SA place dans le fonds : elle y reviendra');
    }

    public function testUnDocumentALaCorbeilleDisparaitDesListesSansQuitterLindex(): void
    {
        $garde = $this->depot->deposer($this->pieceADeposer('a.pdf', 'a'), ['titre' => 'Gardé']);
        $sorti = $this->depot->deposer($this->pieceADeposer('b.pdf', 'b'), ['titre' => 'Sorti']);
        $this->depot->miseALaCorbeille($sorti, 'olivia');

        $chemins = array_column($this->depot->lister(), 'chemin_relatif');
        self::assertNotContains('2026/A_classer/Sorti.pdf', $chemins, 'la corbeille n’est pas le fonds');
        self::assertContains('2026/A_classer/Gardé.pdf', $chemins);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn(),
            'la ligne du document à la corbeille EXISTE toujours');
        self::assertNotNull($this->depot->fiche($garde));
    }

    public function testLaRestaurationRemetLeDocumentOctetPourOctet(): void
    {
        $id = $this->depot->deposer($this->pieceADeposer('contrat.pdf', 'contrat contenu'), [
            'titre' => 'Contrat', 'type' => 'contrat', 'date' => '2026-09-02',
        ]);
        $empreinteAvant = $this->pdo->query('SELECT empreinte FROM documents WHERE id = ' . $id)->fetchColumn();
        $this->depot->miseALaCorbeille($id, 'olivia');
        $this->depot->restaurer($id);

        self::assertFileExists($this->racine . '/fonds/2026/contrat/Contrat.pdf', 'de retour à sa place');
        self::assertFileDoesNotExist($this->racine . '/fonds/.corbeille/2026/contrat/Contrat.pdf');
        self::assertSame('contrat contenu', file_get_contents($this->racine . '/fonds/2026/contrat/Contrat.pdf'));
        $ligne = $this->pdo->query('SELECT empreinte, supprime_le FROM documents WHERE id = ' . $id)->fetch();
        self::assertSame($empreinteAvant, $ligne['empreinte']);
        self::assertNull($ligne['supprime_le'], 'drapeau levé');
        self::assertSame(['v001_' . substr(hash('sha256', 'contrat contenu'), 0, 8) . '.pdf'],
            $this->versions('2026/contrat/Contrat.pdf'), 'les versions sont restées');
        self::assertCount(1, $this->depot->lister(), 'le document revient dans les listes');
    }

    public function testCorbeilleSurUnDocumentInconnuEstUneErreurNommee(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('corbeille');
        $this->depot->miseALaCorbeille(999, 'olivia');
    }

    public function testAucuneSuppressionSqlSurLesDonneesUtilisateur(): void
    {
        // Garde statique, idée de GEDv1 (NoHardDeleteTest) : le code de production ne contient aucun
        // DELETE sur les données, et TRUNCATE n'existe que dans le reconstructeur (qui reconstruit tout).
        $interdits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src',
            \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->getExtension() !== 'php') {
                continue;
            }
            $contenu = (string) file_get_contents($f->getPathname());
            if (preg_match('/DELETE\s+FROM/i', $contenu)) {
                $interdits[] = 'DELETE dans ' . $f->getPathname();
            }
            if (preg_match('/TRUNCATE/i', $contenu) && basename($f->getPathname()) !== 'Reconstructeur.php') {
                $interdits[] = 'TRUNCATE hors reconstructeur : ' . $f->getPathname();
            }
        }
        self::assertSame([], $interdits, 'zéro suppression SQL sur les données utilisateur');
    }
}

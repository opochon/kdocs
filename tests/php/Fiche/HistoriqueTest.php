<?php

declare(strict_types=1);

namespace Ged\Tests\Fiche;

use Ged\Fiche\Historique;
use Ged\Socle\DepotFichiers;
use PHPUnit\Framework\TestCase;

/**
 * Oracle de l'historique d'un objet (équivalent GEDv2 de `GET /objects/{type}/{objectid}/history`) :
 * versions archivées ET déplacements réels, du plus récent au plus ancien, lus du fonds ; aucune date
 * inventée et une base sans ligne ne fait jamais échouer la lecture.
 */
final class HistoriqueTest extends TestCase
{
    use SupportFiche;

    protected function setUp(): void
    {
        $this->setUpCorpus();
    }

    protected function tearDown(): void
    {
        $this->tearDownCorpus();
    }

    public function testLHistoriqueReunitVersionsEtDeplacementsDuPlusRecentAuPlusAncien(): void
    {
        $depot = new DepotFichiers($this->config);
        $id = $this->deposerFiche('contrat.txt', 'Contrat synthétique', 'version une');
        $depot->remplacerContenu($id, $this->pieceADeposer('contrat-v2.txt', 'version deux'), 'alice@exemple.test');
        $depot->mettreAJour($id, ['type' => 'Contrat'], 'alice@exemple.test');

        $historique = (new Historique($this->config))->pour($id);

        self::assertNotEmpty($historique, 'l’historique doit porter les gestes réels du document');
        $quois = implode(' | ', array_column($historique, 'quoi'));
        self::assertStringContainsString('Version v', $quois, 'chaque version archivée doit être listée');
        self::assertStringContainsString('Déplacé', $quois, 'le changement de rangement doit être tracé');
        self::assertContains('version', array_column($historique, 'nature'));
        self::assertContains('deplacement', array_column($historique, 'nature'));

        $quands = array_column($historique, 'quand');
        $attendu = $quands;
        rsort($attendu);
        self::assertSame($attendu, $quands, 'le plus récent doit venir en tête');
    }

    public function testUnIdentifiantSansLigneRendUneListeVideSansEchouer(): void
    {
        self::assertSame([], (new Historique($this->config))->pour(999999));
        self::assertSame([], (new Historique($this->config))->pour(0));
    }
}

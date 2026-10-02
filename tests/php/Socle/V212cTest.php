<?php

declare(strict_types=1);

namespace Ged\Tests\Socle;

use Ged\Socle\DepotFichiers;
use Ged\Socle\EcranBibliotheque;
use Ged\Socle\Vues;
use Ged\Ui\ATraiter;
use PHPUnit\Framework\TestCase;

/** Oracles V2-12c : les quatre gestes rétablis restent versionnés, filtrants et bornés. */
final class V212cTest extends TestCase
{
    use SupportCorpus;

    protected function setUp(): void { $this->setUpCorpus(); }
    protected function tearDown(): void { $this->tearDownCorpus(); }

    public function testRestaurerUneVersionCreeUneNouvelleVersionSansEffacerLHistoire(): void
    {
        $depot = new DepotFichiers($this->config);
        $id = $depot->deposer($this->pieceADeposer('v1.pdf', 'version un'), [
            'titre' => 'Pièce versions', 'type' => 'Facture', 'date' => '2026-10-02', 'statut' => 'valide',
        ]);
        $depot->remplacerContenu($id, $this->pieceADeposer('v2.pdf', 'version deux'), 'recette');

        $numero = $depot->restaurerVersion($id, 1, 'recette');
        $relatif = (string) $depot->fiche($id)['chemin_relatif'];

        self::assertSame(3, $numero);
        self::assertSame('version un', file_get_contents($this->racine . '/fonds/' . $relatif));
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM document_versions WHERE document_id = ' . $id)->fetchColumn());
        self::assertSame('version deux', file_get_contents(dirname($this->racine . '/fonds/' . $relatif) . '/.versions/' . basename($relatif) . '/v002_' . substr(hash('sha256', 'version deux'), 0, 8) . '.pdf'));
    }

    public function testUnDossierFiltreSesSousDossiersEtGardeLeCompteurExact(): void
    {
        $depot = new DepotFichiers($this->config);
        foreach ([
            ['a.pdf', 'A', '2026/Factures/Suisse'], ['b.pdf', 'B', '2026/Factures/France'], ['c.pdf', 'C', '2026/Contrats'],
        ] as [$fichier, $titre, $dossier]) {
            $depot->deposer($this->pieceADeposer($fichier, $titre), ['titre' => $titre, 'dossier' => $dossier, 'statut' => 'valide']);
        }

        $donnees = (new EcranBibliotheque($this->config))->donnees(['dossier' => '2026/Factures'], 'csrf');

        self::assertSame(2, $donnees['totalFiltre']);
        self::assertSame(['B', 'A'], array_column($donnees['docs'], 'titre'));
        self::assertSame('2026/Factures', $donnees['dossierActif']);
    }

    public function testLesFiltresDeRechercheDeviennentUneVueDeBibliotheque(): void
    {
        $id = (new Vues($this->config))->enregistrerRecherche('Factures de recherche', [
            'q' => 'énergie', 'type' => 'Facture', 'emetteur' => 'Atelier Fictif SA', 'date_debut' => '2026-01-01',
        ], 'recette');
        $vue = (new Vues($this->config))->lire($id);

        self::assertSame('Facture', $vue['filtres']['type']);
        self::assertSame('Atelier Fictif SA', $vue['filtres']['emetteur']);
        self::assertSame('2026-01-01', $vue['filtres']['de']);
        self::assertSame('énergie', $vue['recherche']['q']);
    }

    public function testValidationDeLotPasseParLaValidationUnitaireEtNAbandonnePasLesIncompletes(): void
    {
        $depot = new DepotFichiers($this->config);
        $complete = $depot->deposer($this->pieceADeposer('complete.pdf', 'complète'), [
            'titre' => 'Complète', 'type' => 'Facture', 'emetteur' => 'Maison Exemple SA', 'date' => '2026-10-02', 'statut' => 'a_controler',
        ]);
        $incomplete = $depot->deposer($this->pieceADeposer('incomplete.pdf', 'incomplète'), [
            'titre' => 'Incomplète', 'type' => 'Facture', 'emetteur' => '', 'statut' => 'a_controler',
        ]);
        $bilan = (new ATraiter($this->config, $this->pdo, $depot))->validerPropositions([$complete, $incomplete], 'recette');

        self::assertSame([$complete], $bilan['valides']);
        self::assertSame('valide', $depot->fiche($complete)['statut']);
        self::assertSame('a_controler', $depot->fiche($incomplete)['statut']);
        self::assertStringContainsString('émetteur', $bilan['refuses'][$incomplete]);
    }
}

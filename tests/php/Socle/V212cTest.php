<?php

declare(strict_types=1);

namespace Ged\Tests\Socle;

use Ged\Socle\DepotFichiers;
use Ged\Socle\EcranBibliotheque;
use Ged\Socle\Vues;
use Ged\Recherche\IndexPleinTexte;
use Ged\Recherche\Requete as RequeteRecherche;
use Ged\Recherche\Schema as SchemaRecherche;
use Ged\Recherche\Service as ServiceRecherche;
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

    public function testUneVueIssueDeRechercheAfficheExactementLesMemesDocumentsEtLeMemeCompteur(): void
    {
        $depot = new DepotFichiers($this->config);
        foreach ([
            ['facture-energie.txt', 'Facture énergie solaire', 'Facture', 'énergie solaire pour atelier fictif'],
            ['contrat-energie.txt', 'Contrat énergie solaire', 'Contrat', 'énergie solaire pour atelier fictif'],
            ['facture-eau.txt', 'Facture eau courante', 'Facture', 'eau courante pour atelier fictif'],
        ] as [$fichier, $titre, $type, $texte]) {
            $depot->deposer($this->pieceADeposer($fichier, $texte), [
                'titre' => $titre, 'type' => $type, 'emetteur' => 'Atelier Fictif SA',
                'date' => '2026-10-02', 'texte' => $texte, 'statut' => 'valide',
            ]);
        }
        SchemaRecherche::installer($this->pdo);
        $index = IndexPleinTexte::depuis($this->config, $this->pdo);
        $index->synchroniser(null); // le job hors requête alimente l'index de la recherche réelle

        $criteres = ['q' => 'énergie', 'type' => 'Facture', 'emetteur' => 'Atelier Fictif SA'];
        $recherche = (new ServiceRecherche($this->pdo, $index))->rechercher(RequeteRecherche::depuisParametres($criteres));
        $id = (new Vues($this->config))->enregistrerRecherche('Factures de recherche', [
            ...$criteres, 'date_debut' => '2026-01-01',
        ], 'recette');
        $vue = (new Vues($this->config))->lire($id);
        $bibliotheque = (new EcranBibliotheque($this->config))->donnees(['vue' => $id], 'csrf');

        self::assertSame('Facture', $vue['filtres']['type']);
        self::assertSame('Atelier Fictif SA', $vue['filtres']['emetteur']);
        self::assertSame('2026-01-01', $vue['filtres']['de']);
        self::assertSame('énergie', $vue['recherche']['q']);
        self::assertSame(array_column($recherche->trouvailles, 'id'), array_column($bibliotheque['docs'], 'id'));
        self::assertSame($recherche->total, $bibliotheque['totalFiltre']);
        self::assertSame($recherche->total, current(array_filter($bibliotheque['vuesVolet'],
            static fn (array $v): bool => $v['id'] === $id))['compte']);
    }

    public function testUneVueDeBibliothequeSansTexteGardeSonFiltreHabituel(): void
    {
        $depot = new DepotFichiers($this->config);
        $facture = $depot->deposer($this->pieceADeposer('vue-facture.txt', 'facture'), [
            'titre' => 'Facture sans recherche', 'type' => 'Facture', 'statut' => 'valide',
        ]);
        $depot->deposer($this->pieceADeposer('vue-contrat.txt', 'contrat'), [
            'titre' => 'Contrat sans recherche', 'type' => 'Contrat', 'statut' => 'valide',
        ]);
        $id = (new Vues($this->config))->enregistrer('Factures seulement', ['type' => 'Facture'], 'recette');

        $bibliotheque = (new EcranBibliotheque($this->config))->donnees(['vue' => $id], 'csrf');

        self::assertSame([$facture], array_column($bibliotheque['docs'], 'id'));
        self::assertSame(1, $bibliotheque['totalFiltre']);
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

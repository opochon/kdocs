<?php

declare(strict_types=1);

namespace Ged\Tests\Recherche;

use Ged\Recherche\Requete;
use Ged\Recherche\Schema;
use PHPUnit\Framework\TestCase;

/** Le moteur : filtres au même périmètre, repli LIKE (fragment, terme court, index retiré), erreur nommée. */
final class ServiceTest extends TestCase
{
    use SupportRecherche;

    protected function setUp(): void
    {
        $this->setUpRecherche();
        $this->deposerCorpus($this->corpusOracle());
    }

    protected function tearDown(): void
    {
        $this->tearDownRecherche();
    }

    public function testFiltresTexteEtMontantPartagentLeMemePerimetre(): void
    {
        $resultat = $this->moteur()->rechercher(Requete::depuisParametres(['q' => 'facture', 'montant_min' => '1000']));

        self::assertNull($resultat->erreur?->cause);
        self::assertSame(1, $resultat->total, 'le total applique le filtre montant, pas seulement la liste');
        self::assertSame('Facture AN-5001', $resultat->trouvailles[0]->titre);
    }

    public function testFiltreSeulSansTexteListeLeFondsFiltre(): void
    {
        $resultat = $this->moteur()->rechercher(Requete::depuisParametres(['q' => '', 'type' => 'Assurance']));

        self::assertSame('liste', $resultat->mode);
        self::assertSame(1, $resultat->total);
        self::assertSame('Police RC avenant', $resultat->trouvailles[0]->titre);
    }

    public function testSansTexteNiFiltreLeFondsEstListeDuPlusRecent(): void
    {
        $resultat = $this->moteur()->rechercher(new Requete(''));

        self::assertSame(6, $resultat->total);
        self::assertSame('Facture énergie', $resultat->trouvailles[0]->titre, 'la plus récente d’abord');
    }

    public function testFragmentInterneBasculeSurLeRepliLike(): void
    {
        $resultat = $this->moteur()->rechercher(new Requete('ntrat'));

        self::assertSame('like', $resultat->mode);
        self::assertSame(
            ['Contrat de maintenance CM-2026-04', 'Facture AN-5001'],
            array_map(static fn ($t): string => $t->titre, array_slice($resultat->trouvailles, 0, 2)),
        );
    }

    public function testUnTermeDeMoinsDeQuatreLettresPasseParLike(): void
    {
        $resultat = $this->moteur()->rechercher(new Requete('RH'));

        self::assertSame('like', $resultat->mode, 'MyISAM n’indexe pas moins de quatre lettres');
        self::assertSame('Attestation de travail', $resultat->trouvailles[0]->titre);
    }

    public function testIndexFulltextRetireActiveLeRepliLikeSansMigration(): void
    {
        $moteur = $this->moteur();
        $moteur->rechercher(new Requete('maintenance'));
        $this->pdo->exec('ALTER TABLE ' . Schema::TABLE . ' DROP INDEX ' . Schema::INDEX_PLEIN_TEXTE);

        $resultat = $moteur->rechercher(new Requete('maintenance'));

        self::assertNull($resultat->erreur?->cause);
        self::assertSame('like', $resultat->mode, 'un écran ne répare jamais le schéma');
        self::assertSame('Contrat de maintenance CM-2026-04', $resultat->trouvailles[0]->titre);
    }

    public function testUnFragmentResteCherchableQuandLIndexEstIndisponible(): void
    {
        $moteur = $this->moteur(false);   // pas d'auto-installation : on veut voir le repli
        $moteur->rechercher(new Requete('maintenance'));   // chauffe l'index
        $this->pdo->exec('ALTER TABLE ' . Schema::TABLE . ' DROP INDEX ' . Schema::INDEX_PLEIN_TEXTE);

        $resultat = $moteur->rechercher(new Requete('maintenance'));

        self::assertNull($resultat->erreur?->cause);
        self::assertSame('like', $resultat->mode);
        self::assertNotEmpty($resultat->trouvailles);
    }

    public function testUneErreurSqlEstNommee(): void
    {
        $this->pdo->exec('DROP TABLE ' . Schema::TABLE);   // table du secteur uniquement

        $resultat = $this->moteur()->rechercher(new Requete('maintenance'));

        self::assertNotNull($resultat->erreur);
        self::assertSame('recherche plein texte', $resultat->erreur->etape);
        self::assertNotSame('', $resultat->erreur->cause);
        self::assertNotSame('', $resultat->erreur->reference);
    }
}

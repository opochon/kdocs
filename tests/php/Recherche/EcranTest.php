<?php

declare(strict_types=1);

namespace Ged\Tests\Recherche;

use Ged\Recherche\Ecran;
use Ged\Recherche\ErreurSql;
use Ged\Recherche\Moteur;
use Ged\Recherche\Requete;
use Ged\Recherche\Resultat;
use Ged\Ui\Maquette;
use Ged\Ui\Vue;
use PHPUnit\Framework\TestCase;

/**
 * L'écran de recherche sur les vrais résultats : le gabarit reçoit des documents du fonds, surligne le
 * terme, nomme une erreur de base (jamais une liste vide muette) et échappe la saisie.
 */
final class EcranTest extends TestCase
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

    public function testLesVraisResultatsArriventDansLeGabarit(): void
    {
        $donnees = (new Ecran($this->moteur(), $this->pdo))->donnees(['q' => 'maintenance']);
        $html = $this->rendre($donnees);

        self::assertNull($donnees['erreur']);
        self::assertSame('Contrat de maintenance CM-2026-04', $donnees['resultats'][0]['titre']);
        self::assertNotEmpty($donnees['resultats'][0]['extraits']);
        self::assertStringContainsString('data-testid="resultat-', $html);
        self::assertStringContainsString('<mark>maintenance</mark>', $html);
        self::assertStringNotContainsString('data-testid="recherche-vide"', $html);
    }

    public function testUneErreurDeBaseEstNommeeALEcran(): void
    {
        $donnees = (new Ecran(new MoteurEnPanne()))->donnees(['q' => 'maintenance']);
        $html = $this->rendre($donnees);

        self::assertStringContainsString('data-testid="recherche-erreur"', $html);
        self::assertStringContainsString('recherche plein texte', $html);
        self::assertStringContainsString('Schéma de recherche absent', $html);
        self::assertStringContainsString('ref-1a2b3c4d5e', $html);
        self::assertStringNotContainsString('data-testid="recherche-vide"', $html, 'une panne n’est pas une absence');
    }

    public function testUneRechercheSansResultatSeDit(): void
    {
        $donnees = (new Ecran($this->moteur(), $this->pdo))->donnees(['q' => 'zzzintrouvable']);
        $html = $this->rendre($donnees);

        self::assertStringContainsString('data-testid="recherche-vide"', $html);
        self::assertStringNotContainsString('data-testid="recherche-erreur"', $html);
    }

    public function testLaSaisieEstEchappeeDansLeGabarit(): void
    {
        $donnees = (new Ecran(new MoteurEnPanne()))->donnees(['q' => '<script>x</script>']);
        $html = $this->rendre($donnees);

        self::assertStringNotContainsString('<script>x</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testLeLienSemblablesMeneALaListeEtPasALaFiche(): void
    {
        $donnees = (new Ecran($this->moteur(), $this->pdo))->donnees(['q' => 'maintenance']);
        $html = $this->rendre($donnees);
        $id = (int) $donnees['resultats'][0]['id'];

        self::assertStringContainsString('href="/recherche?semblable=' . $id . '"', $html, 'le lien mène à la liste de semblables');
        self::assertStringNotContainsString('href="/bibliotheque?doc=' . $id . '"', $html, 'le lien n’ouvre plus la simple fiche');
    }

    public function testLesSemblablesSeRendentDansLaListeSansLeDocumentSource(): void
    {
        $deposes = $this->deposerCorpus($this->corpusOracle());
        $id = (int) $deposes['contrat-maintenance']['id'];
        $donnees = (new Ecran(new MoteurEnPanne(), $this->pdo))->donnees(['semblable' => (string) $id]);
        $html = $this->rendre($donnees);

        self::assertNull($donnees['erreur']);
        self::assertSame($id, $donnees['semblable']['id']);
        self::assertSame('semblable', $donnees['mode']);
        self::assertStringContainsString('data-testid="recherche-semblables"', $html);
        self::assertStringContainsString('data-testid="semblables-source"', $html);
        self::assertStringNotContainsString('data-testid="resultat-' . $id . '"', $html, 'le document source est exclu');
        self::assertStringNotContainsString('data-testid="filtres"', $html, 'les filtres de recherche n’ont pas de sens ici');
    }

    public function testUneSourceInconnueSeDitIntrouvableSansErreurDeBase(): void
    {
        $donnees = (new Ecran($this->moteur(), $this->pdo))->donnees(['semblable' => '999999']);
        $html = $this->rendre($donnees);

        self::assertNull($donnees['erreur']);
        self::assertNull($donnees['semblable']);
        self::assertStringContainsString('data-testid="semblables-introuvable"', $html);
        self::assertStringNotContainsString('data-testid="recherche-erreur"', $html);
    }

    /** @param array<string, mixed> $donnees */
    private function rendre(array $donnees): string
    {
        $vue = new Vue(dirname(__DIR__, 3) . '/templates');

        return $vue->html('ecrans/recherche.twig', ['ecran' => 'recherche', 'bilan' => Maquette::bilan()] + $donnees);
    }
}

/** Moteur en panne injecté : prouve que l'écran nomme l'erreur au lieu d'afficher une liste vide muette. */
final class MoteurEnPanne implements Moteur
{
    public function rechercher(Requete $requete): Resultat
    {
        return Resultat::enErreur(new ErreurSql('recherche plein texte', 'Schéma de recherche absent ou indisponible : exécutez tools/installer.php.', 'ref-1a2b3c4d5e'), 0.1);
    }
}

<?php

declare(strict_types=1);

namespace Ged\Tests\Edition;

use Ged\Edition\Ouvertures;
use Ged\Edition\Secours;
use Ged\Socle\EcranBibliotheque;
use Ged\Ui\Ecrans;
use Ged\Ui\Vue;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Oracle d'interface du lot ged-edition-serveur (F13) : ce que l'utilisateur VOIT.
 *
 * Le bouton « Ouvrir dans Word » (lien `kdocs://ouvrir/<id>`) n'apparaît qu'à qui peut modifier le
 * document ; « ouvert par X sur POSTE depuis HH:MM » s'affiche dans la fiche et la Bibliothèque ; la
 * fiche liste les copies de secours et ne propose « Récupérer » qu'à qui a le droit de modifier.
 */
final class EcranEditionTest extends TestCase
{
    use SupportEdition;

    protected function setUp(): void
    {
        $this->setUpEdition();
    }

    protected function tearDown(): void
    {
        $this->tearDownEdition();
    }

    public function testLeBoutonOuvrirDansWordNEstMontreQuAQuiPeutModifier(): void
    {
        $html = $this->fiche(['documents:lire', 'documents:modifier']);
        self::assertStringContainsString('data-testid="action-ouvrir-poste"', $html);
        self::assertStringContainsString('kdocs://ouvrir/' . $this->document, $html);
        self::assertStringContainsString('Ouvrir dans Word', $html, 'l’extension .docx annonce Word');

        $lecteur = $this->fiche(['documents:lire']);
        self::assertStringNotContainsString('action-ouvrir-poste', $lecteur, 'sans droit de modification, aucun bouton d’ouverture');
    }

    public function testLaFicheEtLaBibliothequeAffichentOuvertParSurLePoste(): void
    {
        (new Ouvertures($this->pdo))->ouvrir($this->document, $this->identifiant, 'POSTE-03');

        $html = $this->fiche(['documents:lire', 'documents:modifier']);
        self::assertStringContainsString('data-testid="document-ouverture"', $html);
        self::assertStringContainsString('Ouvert par ' . $this->identifiant . ' sur POSTE-03 depuis ', $html);

        $donnees = (new EcranBibliotheque($this->config(), $this->compteId, false, ['documents:lire', 'documents:modifier']))
            ->donnees(['doc' => (string) $this->document], 'csrf');
        $htmlBiblio = (string) (new Vue(dirname(__DIR__, 3) . '/templates'))
            ->rendre(new Response(), 'ecrans/bibliotheque.twig', $donnees)->getBody();
        self::assertStringContainsString('data-testid="ouvert-' . $this->document . '"', $htmlBiblio);
        self::assertStringContainsString('Ouvert par ' . $this->identifiant . ' sur POSTE-03 depuis ', $htmlBiblio);
        self::assertStringContainsString('data-testid="apercu-ouvrir-poste"', $htmlBiblio);
    }

    public function testLaFicheListeLesCopiesDeSecoursEtProposeDeRecuperer(): void
    {
        (new Secours($this->config()))->deposer($this->document, 'POSTE-03', $this->piece('copie.docx', 'secours-' . $this->sel));

        $html = $this->fiche(['documents:lire', 'documents:modifier']);
        self::assertStringContainsString('data-testid="secours-copie"', $html);
        self::assertStringContainsString('POSTE-03', $html);
        self::assertStringContainsString('data-testid="secours-recuperer"', $html);

        // Un lecteur voit la copie mais ne peut pas la récupérer.
        $lecteur = $this->fiche(['documents:lire']);
        self::assertStringContainsString('data-testid="secours-copie"', $lecteur);
        self::assertStringNotContainsString('secours-recuperer', $lecteur);
    }

    /** @param list<string> $permissions */
    private function fiche(array $permissions): string
    {
        $requete = (new ServerRequestFactory())->createServerRequest('GET', '/documents/' . $this->document)
            ->withAttribute('ged.session', ['utilisateur' => $this->identifiant, 'compte' => $this->compteId, 'permissions' => $permissions]);

        return (string) (new Ecrans(new Vue(dirname(__DIR__, 3) . '/templates')))
            ->document($requete, new Response(), ['id' => (string) $this->document])->getBody();
    }
}

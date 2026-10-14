<?php

declare(strict_types=1);

namespace Ged\Tests\Fiche;

use Ged\Fiche\Nom;
use Ged\Fiche\Verrou;
use Ged\Kernel;
use Ged\Socle\DepotFichiers;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Contrat HTTP de la fiche & du rendu (famille M-Files nº 5) : les routes titre / nom de fichier /
 * verrou sont déclarées, mutent le fonds, redirigent (PRG) et une erreur se NOMME (étape, cause), jamais
 * un 500 muet. La fiche porte les actions avec leur `data-testid` et l'état du verrou.
 */
final class ModuleTest extends TestCase
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

    /** @param array<string, mixed> $corps @return array{0:int, 1:string} */
    private function poster(string $chemin, array $corps): array
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        $rep = $app->handle((new ServerRequestFactory())->createServerRequest('POST', $chemin)->withParsedBody($corps));

        return [$rep->getStatusCode(), $rep->getHeaderLine('Location')];
    }

    public function testRenommerLeTitreDeLObjetParLaRoute(): void
    {
        $id = $this->deposerFiche('facture.txt', 'Facture 2026', 'contenu');

        [$code, $location] = $this->poster('/documents/' . $id . '/titre', ['titre' => 'Facture 2026 corrigée']);

        self::assertSame(303, $code);
        self::assertStringContainsString('/documents/' . $id, $location);
        self::assertStringContainsString('fiche_succes', $location);
        self::assertSame('Facture 2026 corrigée', (new DepotFichiers($this->config))->fiche($id)['meta']['titre']);
    }

    public function testRenommerLeNomDuFichierParLaRoute(): void
    {
        $id = $this->deposerFiche('facture.txt', 'Facture 2026', 'contenu');

        [$code] = $this->poster('/documents/' . $id . '/nom-fichier', ['nom' => 'Facture 2026 v2.txt']);

        self::assertSame(303, $code);
        self::assertSame('Facture_2026_v2.txt', (new Nom($this->config))->lire($id)['nom']);
    }

    public function testVerrouillerPuisLibererParLaRoute(): void
    {
        $id = $this->deposerFiche('facture.txt', 'Facture 2026', 'contenu');
        $verrou = new Verrou($this->config);

        [$code] = $this->poster('/documents/' . $id . '/verrou', ['action' => 'poser']);
        self::assertSame(303, $code);
        self::assertTrue($verrou->etat($id)['verrouille']);

        [$code] = $this->poster('/documents/' . $id . '/verrou', ['action' => 'retirer']);
        self::assertSame(303, $code);
        self::assertFalse($verrou->etat($id)['verrouille']);
    }

    public function testUneErreurDeFicheSeNommeJamais500(): void
    {
        $id = $this->deposerFiche('facture.txt', 'Facture 2026', 'contenu');

        [$code] = $this->poster('/documents/' . $id . '/titre', ['titre' => '   ']);

        self::assertSame(422, $code);
    }

    public function testLaFichePorteLesActionsDeRenduEtLEtatDuVerrou(): void
    {
        $id = $this->deposerFiche('facture.txt', 'Facture 2026', 'contenu');

        $html = $this->page('/documents/' . $id);

        foreach (['fiche-rendu', 'titre-objet-form', 'titre-objet-champ', 'nom-fichier-form',
            'nom-fichier-champ', 'verrou-etat', 'verrou-poser'] as $testid) {
            self::assertStringContainsString('data-testid="' . $testid . '"', $html, "l’action $testid doit exister");
        }
        self::assertStringContainsString('data-verrouille="0"', $html);
        self::assertStringContainsString('Facture_2026.txt', $html, 'le nom du fichier courant doit être affiché');
    }

    private function page(string $chemin): string
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        $rep = $app->handle((new ServerRequestFactory())->createServerRequest('GET', $chemin));

        self::assertSame(200, $rep->getStatusCode(), (string) $rep->getBody());

        return (string) $rep->getBody();
    }
}

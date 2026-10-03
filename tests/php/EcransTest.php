<?php

declare(strict_types=1);

namespace Ged\Tests;

use Ged\Kernel;
use Ged\Ui\Ecrans;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/** Les six écrans répondent, portent leur data-testid, et n'échappent jamais une donnée par erreur. */
final class EcransTest extends TestCase
{
    private function get(string $chemin): array
    {
        $app = Kernel::creer(dirname(__DIR__, 2));
        $rep = $app->handle((new ServerRequestFactory())->createServerRequest('GET', $chemin));

        return [$rep->getStatusCode(), (string) $rep->getBody()];
    }

    /** @return array<string, array{string, string}> */
    public static function ecrans(): array
    {
        return [
            'à traiter' => ['/a-traiter', 'ecran-a-traiter'],
            'bibliothèque' => ['/bibliotheque', 'ecran-bibliotheque'],
            'recherche' => ['/recherche?q=maintenance', 'ecran-recherche'],
            'fiche' => ['/documents/204', 'ecran-document'],
            'workflows' => ['/workflows', 'ecran-workflows'],
            'application' => ['/application', 'ecran-application'],
        ];
    }

    #[DataProvider('ecrans')]
    public function testEcranRepondEtSeDesigne(string $chemin, string $testid): void
    {
        [$code, $html] = $this->get($chemin);
        self::assertSame(200, $code, $html);
        self::assertStringContainsString('data-testid="' . $testid . '"', $html);
        self::assertStringContainsString('data-testid="menu"', $html);
    }

    public function testBilanIngestionNeMontreJamaisLeDossier(): void
    {
        [, $html] = $this->get('/a-traiter');
        self::assertStringContainsString('data-testid="bilan-ingestion"', $html);
        self::assertStringNotContainsStringIgnoringCase('consume', $html);
        self::assertStringNotContainsStringIgnoringCase('storage/', $html);
    }

    public function testApplicationDiagnostiqueLeMoteurDeRetouche(): void
    {
        [, $html] = $this->get('/application');
        self::assertStringContainsString('data-testid="reglage-editeur-pdf"', $html);
        self::assertStringContainsString('PyMuPDF', $html);
    }

    public function testPageInconnueEstNommee(): void
    {
        [$code, $html] = $this->get('/nulle-part');
        self::assertSame(404, $code);
        self::assertStringContainsString('data-testid="erreur-reference"', $html);
        self::assertStringContainsString('GET /nulle-part', $html);
    }

    public function testRechercheEchappeLaSaisie(): void
    {
        [, $html] = $this->get('/recherche?q=' . rawurlencode('<script>x</script>'));
        self::assertStringNotContainsString('<script>x</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testSurlignageSansHtml(): void
    {
        $s = Ecrans::surligner('Contrat de Maintenance annuelle', 'maintenance');
        self::assertSame([['t' => 'Contrat de ', 'hit' => false], ['t' => 'Maintenance', 'hit' => true], ['t' => ' annuelle', 'hit' => false]], $s);
    }

    public function testFicheInconnueAvecSessionSynthetiqueSansCompteNeRendPas500(): void
    {
        $app = Kernel::creer(dirname(__DIR__, 2));
        $requete = (new ServerRequestFactory())->createServerRequest('GET', '/documents/999999999')
            ->withAttribute('ged.session', ['utilisateur' => 'contrat-http@gedv2.test', 'compte' => null,
                'permissions' => ['documents:lire'], 'csrf' => 'csrf-synthetique', 'cree_le' => 0, 'expire_le' => PHP_INT_MAX]);
        $reponse = $app->handle($requete);

        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getBody());
    }
}

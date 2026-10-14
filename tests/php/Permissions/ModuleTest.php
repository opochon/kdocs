<?php

declare(strict_types=1);

namespace Ged\Tests\Permissions;

use Ged\Kernel;
use Ged\Permissions\Regles;
use Ged\Securite\DroitsRoutes;
use Ged\Socle\Nomenclature;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Oracle des routes et de l'écran du secteur Permissions : chaque route déclare sa permission, la
 * création passe par le formulaire réel, une règle invalide est refusée nommément et la bascule ne
 * supprime jamais la règle.
 */
final class ModuleTest extends TestCase
{
    use SupportPermissions;

    protected function setUp(): void
    {
        $this->setUpPermissions();
        (new Nomenclature($this->config))->assurer();
    }

    protected function tearDown(): void
    {
        $this->tearDownPermissions();
    }

    public function testChaqueRoutePermissionDeclareSaPermission(): void
    {
        foreach ([
            ['GET', '/permissions'],
            ['POST', '/permissions/regles'],
            ['POST', '/permissions/regles/12/basculer'],
        ] as [$methode, $chemin]) {
            self::assertSame('application:administrer', DroitsRoutes::permissionPour($methode, $chemin), "permission : $methode $chemin");
        }
    }

    public function testLAdministrateurCreePuisDesactiveUneRegleParLEcran(): void
    {
        [$code, , $location] = $this->requete('POST', '/permissions/regles', [
            'nom' => 'Factures comptables', 'classe' => 'Facture', 'voir' => '1', 'groupe' => (string) $this->creerGroupe('comptables', 'comptable'),
        ]);
        self::assertSame(303, $code);
        self::assertSame('/permissions', $location);

        $regles = new Regles($this->config);
        $toutes = $regles->toutes();
        self::assertCount(1, $toutes);
        self::assertSame('Facture', $toutes[0]['conditions']['classe']);
        self::assertSame([['type' => 'groupe', 'valeur' => (string) $toutes[0]['cibles'][0]['valeur']]], $toutes[0]['cibles']);

        [$code, $html] = $this->requete('GET', '/permissions');
        self::assertSame(200, $code);
        self::assertStringContainsString('ecran-permissions', $html);
        self::assertStringContainsString('Factures comptables', $html);

        $id = (int) $toutes[0]['id'];
        [$code] = $this->requete('POST', '/permissions/regles/' . $id . '/basculer', ['actif' => '0']);
        self::assertSame(303, $code);
        self::assertFalse((new Regles($this->config))->parId($id)['actif']);
        self::assertCount(1, (new Regles($this->config))->toutes(), 'la règle désactivée reste relisible, jamais supprimée');
    }

    public function testUneRegleSansCibleEstRefuseeNommement(): void
    {
        [$code, $html] = $this->requete('POST', '/permissions/regles', ['nom' => 'Sans cible', 'classe' => 'Facture', 'voir' => '1']);

        self::assertSame(400, $code);
        self::assertStringContainsString('cible', $html);
        self::assertSame([], (new Regles($this->config))->toutes());
    }

    /** @param array<string, mixed> $corps @return array{0:int, 1:string, 2:string} */
    private function requete(string $methode, string $chemin, array $corps = []): array
    {
        $requete = (new ServerRequestFactory())->createServerRequest($methode, $chemin);
        if ($corps !== []) {
            $requete = $requete->withParsedBody($corps);
        }
        $reponse = Kernel::creer(dirname(__DIR__, 3))->handle($requete);

        return [$reponse->getStatusCode(), (string) $reponse->getBody(), $reponse->getHeaderLine('Location')];
    }
}

<?php

declare(strict_types=1);

namespace Ged\Tests\Ui;

use Ged\Ui\Rail;
use PHPUnit\Framework\TestCase;

/**
 * Oracle de découvrabilité (constat du lot ged-personas, §2) : la source unique des entrées de menu
 * (`Ged\Ui\Rail`) porte « Partagés avec moi », et la section Administration n'apparaît QU'aux rôles qui
 * ont `application:administrer` (ou la permission '*'). Aucun écran d'administration n'est un lien mort
 * pour les autres.
 */
final class RailMenuTest extends TestCase
{
    private string $racine;

    protected function setUp(): void
    {
        $this->racine = dirname(__DIR__, 3);
    }

    public function testLeMenuPortePartagesAvecMoiEtPasDadministrationSansLeDroit(): void
    {
        $rail = Rail::depuisRacine($this->racine, null, false, ['documents:lire'])->donnees();

        $ids = array_column($rail['principales'], 'id');
        self::assertContains('partages', $ids, '« Partagés avec moi » est atteignable par le menu');
        self::assertNotContains('metadonnees', $ids, 'les écrans d’administration ne sont pas des entrées principales');
        self::assertSame([], $rail['sections'], 'aucune section Administration sans le droit');
    }

    public function testLaSectionAdministrationApparaitAvecLaPermissionEtListeLesEcransSansLienMort(): void
    {
        $rail = Rail::depuisRacine($this->racine, null, false, ['application:administrer'])->donnees();

        self::assertCount(1, $rail['sections']);
        self::assertSame('Administration', $rail['sections'][0]['titre']);
        $urls = array_column($rail['sections'][0]['entrees'], 'url');
        foreach (['/metadonnees', '/administration/comptes', '/administration/groupes', '/administration/roles'] as $attendu) {
            self::assertContains($attendu, $urls, "l’écran $attendu est atteignable par le menu");
        }
    }

    public function testUnAdministrateurVoitLaSectionMemeSansPermissionNommee(): void
    {
        $rail = Rail::depuisRacine($this->racine, null, true, ['*'])->donnees();

        self::assertCount(1, $rail['sections'], 'la permission * ouvre la section Administration');
    }
}

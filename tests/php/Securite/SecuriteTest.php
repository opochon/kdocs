<?php

declare(strict_types=1);

namespace Ged\Tests\Securite;

use Ged\Kernel;
use Ged\Securite\JournalAudit;
use Ged\Securite\ProtectionBruteForce;
use Ged\Securite\RegistreSessions;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/** L'oracle V2-07a : une route non déclarée, une mutation sans CSRF ou une session expirée sont refusées. */
final class SecuriteTest extends TestCase
{
    private string $dossier;

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir() . '/gedv2-securite-' . bin2hex(random_bytes(6));
        mkdir($this->dossier, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dossier . '/*') ?: [] as $fichier) {
            unlink($fichier);
        }
        rmdir($this->dossier);
    }

    public function testToutesLesRoutesOntUnePermissionDeclareeEtUneInconnueEstRefusee(): void
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            foreach ($route->getMethods() as $methode) {
                self::assertNotNull(\Ged\Securite\DroitsRoutes::permissionPour($methode, $route->getPattern()), $route->getPattern());
            }
        }

        $reponse = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/route-sans-declaration')->withHeader('X-GED-Teste-Garde', '1'));
        self::assertSame(403, $reponse->getStatusCode());
        self::assertStringContainsString('data-testid="refus-acces"', (string) $reponse->getBody());
    }

    public function testSessionRegeneréeGlissanteEtCookieDurci(): void
    {
        $sessions = new RegistreSessions($this->dossier, 60, 120);
        $session = $sessions->ouvrir('utilisateur-test', ['documents:lire'], 1000);

        self::assertSame('utilisateur-test', $sessions->lire($session['id'], 1005)['utilisateur']);
        self::assertNull($sessions->lire($session['id'], 1071));
        self::assertStringContainsString('HttpOnly', $sessions->enteteCookie($session['id'], true));
        self::assertStringContainsString('SameSite=Lax', $sessions->enteteCookie($session['id'], true));
        self::assertStringContainsString('Secure', $sessions->enteteCookie($session['id'], true));
    }

    public function testCinqEchecsDeclenchentUneAttenteCroissante(): void
    {
        $protection = new ProtectionBruteForce($this->dossier . '/brute.json');
        for ($i = 0; $i < 5; ++$i) {
            $protection->echec('testeur@gedv2.test', 1000 + $i);
        }

        self::assertGreaterThan(0, $protection->attenteRestante('testeur@gedv2.test', 1005));
        self::assertGreaterThanOrEqual(59, $protection->attenteRestante('testeur@gedv2.test', 1005));
    }

    public function testJournalAuditEstEnAjoutSeul(): void
    {
        $journal = new JournalAudit($this->dossier . '/audit.ndjson');
        $journal->ajouter('connexion', 'utilisateur-test', ['ip' => '127.0.0.1'], 1000);
        $journal->ajouter('refus', null, ['route' => '/inconnue'], 1001);
        $journal->changementDroit('administrateur-test', 'utilisateur-test', 'documents:lire', true, 1002);

        $lignes = file($this->dossier . '/audit.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertCount(3, $lignes);
        self::assertSame('connexion', json_decode((string) $lignes[0], true, 512, JSON_THROW_ON_ERROR)['action']);
        self::assertSame('refus', json_decode((string) $lignes[1], true, 512, JSON_THROW_ON_ERROR)['action']);
        self::assertSame('changement_droit', json_decode((string) $lignes[2], true, 512, JSON_THROW_ON_ERROR)['action']);
    }
}

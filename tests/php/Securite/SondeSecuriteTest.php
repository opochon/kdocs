<?php

declare(strict_types=1);

namespace Ged\Tests\Securite;

use Ged\Securite\DroitsDossiers;
use Ged\Securite\GardeCentrale;
use Ged\Securite\JournalAudit;
use Ged\Securite\RegistreSessions;
use Ged\Ui\Vue;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/** Oracle V2-07c : les protections vérifiées par la sonde sont effectives côté serveur. */
final class SondeSecuriteTest extends TestCase
{
    private string $dossier;

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir() . '/gedv2-sonde-' . bin2hex(random_bytes(6));
        mkdir($this->dossier, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->supprimerDossier($this->dossier);
    }

    public function testJetonDeConnexionNePeutPasEtreRejoue(): void
    {
        $sessions = new RegistreSessions($this->dossier . '/sessions');
        $jeton = $sessions->ouvrirJetonConnexion();

        self::assertTrue($sessions->consommerJetonConnexion($jeton));
        self::assertFalse($sessions->consommerJetonConnexion($jeton));
    }

    public function testJetonCsrfDeSessionNePeutPasEtreRejoue(): void
    {
        $sessions = new RegistreSessions($this->dossier . '/sessions');
        $session = $sessions->ouvrir('sonde@gedv2.test', ['documents:lire']);

        self::assertNotNull($sessions->consommerCsrf($session['id'], $session['csrf']));
        self::assertNull($sessions->consommerCsrf($session['id'], $session['csrf']));
    }

    public function testToutesLesReponsesPortentLesEntetesDeDefense(): void
    {
        $config = new \Ged\Config(dirname(__DIR__, 3));
        $garde = new GardeCentrale(
            new RegistreSessions($this->dossier . '/sessions'),
            new JournalAudit($this->dossier . '/audit.ndjson'),
            new Vue(dirname(__DIR__, 3) . '/templates'),
            new DroitsDossiers(\Ged\Base::pdo($config)),
        );
        $requete = (new ServerRequestFactory())->createServerRequest('GET', '/connexion');
        $gestionnaire = new class implements RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): ResponseInterface
            {
                return new \Slim\Psr7\Response();
            }
        };

        $reponse = $garde->process($requete, $gestionnaire);
        self::assertSame('nosniff', $reponse->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $reponse->getHeaderLine('X-Frame-Options'));
        self::assertMatchesRegularExpression("#script-src 'self' 'nonce-[A-Za-z0-9+/]+'#", $reponse->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString("style-src 'self' 'unsafe-inline'", $reponse->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString("frame-ancestors 'none'", $reponse->getHeaderLine('Content-Security-Policy'));
        self::assertSame('strict-origin-when-cross-origin', $reponse->getHeaderLine('Referrer-Policy'));
    }

    private function supprimerDossier(string $dossier): void
    {
        foreach (glob($dossier . '/*') ?: [] as $element) {
            if (is_dir($element)) {
                $this->supprimerDossier($element);
            } else {
                unlink($element);
            }
        }
        rmdir($dossier);
    }
}

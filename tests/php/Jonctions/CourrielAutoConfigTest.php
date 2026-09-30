<?php

declare(strict_types=1);

namespace Ged\Tests\Jonctions;

use Ged\Jonctions\Appairage;
use Ged\Jonctions\Mailer;
use PHPUnit\Framework\TestCase;
use ZBateson\MailMimeParser\Message;

/**
 * Oracle V2-08c — courriel d'auto-configuration (section 1 v2, point 3).
 *
 * Le transport « fichier » écrit le `.eml` dans un dossier de test : jamais d'envoi réel en test. On relit
 * le message avec le même lecteur que la GED (zbateson) et on vérifie le contrat : en-tête X-GED-Appairage,
 * objet, texte humain, pièce ged-appairage.json {version, ged_url, ged_nom, compte, code, verification,
 * expire_le}.
 */
final class CourrielAutoConfigTest extends TestCase
{
    use SupportJonctions;

    protected function setUp(): void
    {
        $this->setUpJonctions();
    }

    protected function tearDown(): void
    {
        $this->tearDownJonctions();
    }

    public function testLeCourrielDAutoConfigurationSuitLeContrat(): void
    {
        $mailer = $this->mailer();
        $adresse = 'auto-' . $this->sel . '@exemple.test';
        $compte = 'compte-' . $this->sel . '@exemple.test';
        $code = bin2hex(random_bytes(32));
        $verification = 'K7M-4QX';
        $expireLe = date(DATE_ATOM, time() + Appairage::DUREE_APPAIRAGE_S);

        $mailer->envoyerAppairage($adresse, $compte, $code, $verification, $expireLe);

        $eml = $this->dernierMail();
        $message = Message::from($eml, false);

        self::assertSame('v1', $message->getHeaderValue('X-GED-Appairage'), 'en-tête de reconnaissance du courriel');
        self::assertSame(Mailer::OBJET_APPAIRAGE, $message->getSubject());
        self::assertSame($adresse, $message->getHeaderValue('to'), 'le courriel part à l’adresse du profil');

        // Texte humain : on y retrouve le nom de la GED, son URL et le code de vérification à comparer.
        $texte = (string) $message->getTextContent();
        self::assertStringContainsString('ClearMyMails', $texte);
        self::assertStringContainsString($mailer->gedNom(), $texte);
        self::assertStringContainsString($mailer->gedUrl(), $texte);
        self::assertStringContainsString($verification, $texte);

        // Pièce machine : exactement les clés du contrat, dans l'ordre.
        $piece = $this->pieceJson($eml, 'ged-appairage.json');
        self::assertSame(['version', 'ged_url', 'ged_nom', 'compte', 'code', 'verification', 'expire_le'], array_keys($piece));
        self::assertSame(1, $piece['version']);
        self::assertSame($mailer->gedUrl(), $piece['ged_url']);
        self::assertSame($mailer->gedNom(), $piece['ged_nom']);
        self::assertSame($compte, $piece['compte']);
        self::assertSame($code, $piece['code']);
        self::assertSame($verification, $piece['verification']);
        self::assertSame($expireLe, $piece['expire_le']);
    }

    public function testLeCourrielDeVerificationPorteLeLienVersLaGed(): void
    {
        $adresse = 'verif-' . $this->sel . '@exemple.test';
        $this->profil()->definirAdresse(null, 'verif-' . $this->sel . '@exemple.test', $adresse);

        $message = Message::from($this->dernierMail(), false);
        self::assertSame($adresse, $message->getHeaderValue('to'));
        self::assertStringContainsString('/profil/mail/verifier?jeton=', (string) $message->getTextContent());
    }
}

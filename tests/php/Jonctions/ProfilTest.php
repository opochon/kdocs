<?php

declare(strict_types=1);

namespace Ged\Tests\Jonctions;

use Ged\Jonctions\ErreurApi;
use Ged\Jonctions\Profil;
use PHPUnit\Framework\TestCase;

/**
 * Oracle V2-08c — adresse mail du profil (section 1 v2, point 1).
 *
 * L'adresse n'est utilisable pour relier la GED qu'une fois VÉRIFIÉE par un lien envoyé à cette adresse.
 * Le lien est à usage unique et expire.
 */
final class ProfilTest extends TestCase
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

    public function testLAdresseEstVerifieeParUnLienEnvoyeParCourriel(): void
    {
        $identifiant = 'profil-' . $this->sel . '@exemple.test';
        $adresse = 'boite-' . $this->sel . '@exemple.test';
        $profil = $this->profil();

        self::assertFalse($profil->estVerifiee(null, $identifiant), 'aucune adresse tant qu’elle n’est pas saisie');

        $profil->definirAdresse(null, $identifiant, $adresse);
        $etat = $profil->etat(null, $identifiant);
        self::assertSame($adresse, $etat['adresse']);
        self::assertFalse($etat['verifiee'], 'une adresse saisie n’est pas encore vérifiée');

        $eml = $this->dernierMail();
        self::assertStringContainsString($adresse, $eml, 'le lien part bien à l’adresse du profil');
        $jeton = $this->lienDe($eml, '#/profil/mail/verifier\?jeton=([A-Za-z0-9_-]+)#');

        self::assertSame($adresse, $profil->verifier($jeton));
        self::assertTrue($profil->estVerifiee(null, $identifiant));

        // Le lien est à usage unique.
        try {
            $profil->verifier($jeton);
            self::fail('un lien déjà utilisé devait être refusé');
        } catch (ErreurApi $e) {
            self::assertSame(400, $e->statut);
            self::assertStringContainsString('déjà utilisé', $e->cause);
        }
    }

    public function testUneAdresseInvalideEstRefuseeEtUnLienInconnuAussi(): void
    {
        try {
            $this->profil()->definirAdresse(null, 'inv-' . $this->sel, 'pas-une-adresse');
            self::fail('une adresse invalide devait être refusée');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('adresse', $e->getMessage());
        }

        try {
            $this->profil()->verifier('jeton-inexistant');
            self::fail('un lien inconnu devait être refusé');
        } catch (ErreurApi $e) {
            self::assertSame(400, $e->statut);
            self::assertStringContainsString('inconnu', $e->cause);
        }
    }

    public function testUnLienDeVerificationExpireApresUneHeure(): void
    {
        $identifiant = 'exp-' . $this->sel . '@exemple.test';
        $profil = $this->profil();
        $profil->definirAdresse(null, $identifiant, 'exp-' . $this->sel . '@exemple.test', time() - Profil::DUREE_VERIFICATION_S - 60);
        $jeton = $this->lienDe($this->dernierMail(), '#/profil/mail/verifier\?jeton=([A-Za-z0-9_-]+)#');

        try {
            $profil->verifier($jeton);
            self::fail('un lien expiré devait être refusé');
        } catch (ErreurApi $e) {
            self::assertSame(400, $e->statut);
            self::assertStringContainsString('expiré', $e->cause);
        }
    }
}

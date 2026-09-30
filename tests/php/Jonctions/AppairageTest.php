<?php

declare(strict_types=1);

namespace Ged\Tests\Jonctions;

use Ged\Jonctions\Appairage;
use Ged\Jonctions\Dossiers;
use Ged\Jonctions\ErreurApi;
use Ged\Jonctions\Jetons;
use Ged\Securite\DroitsDossiers;
use PHPUnit\Framework\TestCase;

/**
 * Oracle V2-08b — appairage (section 1 du contrat CMM).
 *
 * Un code à usage unique, valable 5 minutes, s'échange contre un jeton LIÉ au compte (haché, révocable).
 * Un code inconnu, déjà utilisé ou expiré est refusé 400 et nommé.
 */
final class AppairageTest extends TestCase
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

    public function testUnCodeNeSertQuUneFoisEtDonneUnJetonLieAuCompte(): void
    {
        $app = $this->service();
        $retour = 'https://cmm.exemple.test/retour';
        $url = $app->ouvrir('cmm', 'poste-' . $this->sel, $retour, 'alea', 42, 'utilisateur@exemple.test');

        self::assertStringStartsWith($retour, $url, 'le retour CMM est respecté');
        $code = $this->codeDe($url);
        self::assertNotSame('', $code);

        $resultat = $app->echanger($code);
        self::assertSame('utilisateur@exemple.test', $resultat['compte']);
        self::assertSame('GED de test', $resultat['ged_nom']);
        self::assertNull($resultat['expire_le'], 'un jeton d’appairage ne porte pas d’échéance');
        self::assertNotSame('', (string) $resultat['jeton']);

        $ligne = (new Jetons($this->pdo))->resoudre((string) $resultat['jeton']);
        self::assertNotNull($ligne);
        self::assertSame(42, $ligne['compte_id'], 'le jeton est lié au compte');
        self::assertSame('utilisateur@exemple.test', $ligne['compte_identifiant']);
        self::assertSame('poste-' . $this->sel, $ligne['appareil']);

        // Le code est à usage unique : il est refusé au second échange, nommé.
        try {
            $app->echanger($code);
            self::fail('un code déjà utilisé devait être refusé');
        } catch (ErreurApi $e) {
            self::assertSame(400, $e->statut);
            self::assertStringContainsString('déjà utilisé', $e->cause);
        }
    }

    public function testUnCodeInconnuOuExpireEstRefuseEtNomme(): void
    {
        $app = $this->service();

        try {
            $app->echanger('code-inexistant');
            self::fail('un code inconnu devait être refusé');
        } catch (ErreurApi $e) {
            self::assertSame(400, $e->statut);
            self::assertStringContainsString('inconnu', $e->cause);
        }

        // Ouvert il y a plus de 5 minutes : expiré.
        $url = $app->ouvrir('cmm', 'poste', 'https://cmm.exemple.test/retour', 'alea', 7, 'u@exemple.test', time() - 400);
        try {
            $app->echanger($this->codeDe($url));
            self::fail('un code expiré devait être refusé');
        } catch (ErreurApi $e) {
            self::assertSame(400, $e->statut);
            self::assertStringContainsString('expiré', $e->cause);
        }
    }

    public function testLeJetonAppairagePorteLesDroitsDuCompte(): void
    {
        $d = $this->compteEtDossiers();
        $app = $this->service();
        $jeton = $this->jetonAppairage($d['compte'], $d['identifiant']);
        $ligne = (new Jetons($this->pdo))->resoudre($jeton);
        self::assertNotNull($ligne);

        $moi = $app->moi($ligne);
        self::assertSame($d['identifiant'], $moi['compte']);
        self::assertTrue($moi['droits']['ingestion'], 'le jeton d’appairage porte la portée depot');
        self::assertSame(2, $moi['dossiers_ecriture'], 'Clients et son sous-dossier hérité');

        $dossiers = (new Dossiers($this->pdo, new DroitsDossiers($this->pdo)))->lister($d['compte'], '');
        self::assertSame([$d['ecriture']], array_column($dossiers, 'chemin'), 'le dossier refusé n’est pas listé');
        self::assertTrue($dossiers[0]['enfants']);
    }

    private function service(): Appairage
    {
        return new Appairage(
            new Jetons($this->pdo),
            $this->pdo,
            new Dossiers($this->pdo, new DroitsDossiers($this->pdo)),
            'GED de test',
        );
    }

    private function codeDe(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parametres);

        return (string) ($parametres['code'] ?? '');
    }
}

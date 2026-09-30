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
 * Oracle V2-08c — appairage (section 1 v2 du contrat CMM) : la liaison part de la GED.
 *
 * La GED crée un appairage EN ATTENTE 24 h : un code d'échange à usage unique (aléa 256 bits, seule son
 * empreinte est gardée) et un code de vérification court affiché à l'écran. Le courriel d'auto-configuration
 * porte les deux. L'échange exige {code, verification, poste} ; un code inconnu, expiré, rejoué ou un code
 * de vérification faux est refusé 400, nommé.
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

    public function testUnAppairagePartDeLaGedEtNeSechangeQuUneFois(): void
    {
        $identifiant = 'cmm-' . $this->sel . '@exemple.test';
        $adresse = 'utilisateur-' . $this->sel . '@exemple.test';
        $this->profilVerifie(null, $identifiant, $adresse);

        $app = $this->service();
        $attente = $app->ouvrir(null, $identifiant);

        self::assertSame($adresse, $attente['adresse'], 'le courriel part de l’adresse VÉRIFIÉE du profil');
        self::assertMatchesRegularExpression('/^[2-9A-HJ-NP-Z]{3}-[2-9A-HJ-NP-Z]{3}$/', $attente['verification'],
            'code de vérification court, sans caractère ambigu');

        // Le courriel d'auto-configuration porte le code d'échange et le code de vérification.
        $piece = $this->pieceJson($this->mailContenant('X-GED-Appairage'), 'ged-appairage.json');
        self::assertSame(1, $piece['version']);
        self::assertSame($identifiant, $piece['compte']);
        self::assertSame($attente['verification'], $piece['verification'], 'le .eml porte le code affiché à l’écran');
        self::assertSame(64, strlen((string) $piece['code']), 'code d’échange = 256 bits');

        // Mauvais code de vérification : 400 nommé, et le code N'EST PAS consommé (une faute de frappe se rattrape).
        try {
            $app->echanger((string) $piece['code'], 'AAA-BBB', 'poste-' . $this->sel);
            self::fail('un mauvais code de vérification devait être refusé');
        } catch (ErreurApi $e) {
            self::assertSame(400, $e->statut);
            self::assertStringContainsString('vérification', $e->cause);
        }

        // Échange légitime : jeton LIÉ au compte et à l'appareil que CMM annonce.
        $resultat = $app->echanger((string) $piece['code'], (string) $piece['verification'], 'poste-' . $this->sel);
        self::assertSame($identifiant, $resultat['compte']);
        self::assertSame('GED de test', $resultat['ged_nom']);
        self::assertNull($resultat['expire_le'], 'un jeton d’appairage ne porte pas d’échéance');
        self::assertNotSame('', (string) $resultat['jeton']);

        $ligne = (new Jetons($this->pdo))->resoudre((string) $resultat['jeton']);
        self::assertNotNull($ligne);
        self::assertSame('poste-' . $this->sel, $ligne['appareil'], 'le poste vient de l’échange CMM');

        // Usage unique : le même code est refusé au second échange, nommé.
        try {
            $app->echanger((string) $piece['code'], (string) $piece['verification'], 'poste-' . $this->sel);
            self::fail('un code déjà utilisé devait être refusé');
        } catch (ErreurApi $e) {
            self::assertSame(400, $e->statut);
            self::assertStringContainsString('déjà utilisé', $e->cause);
        }

        self::assertSame([], $app->enAttente(null, $identifiant), 'un appairage échangé n’est plus en attente');
    }

    public function testUnAppairageExpireApres24h(): void
    {
        $identifiant = 'cmm-exp-' . $this->sel . '@exemple.test';
        $this->profilVerifie(null, $identifiant, 'exp-' . $this->sel . '@exemple.test');

        $app = $this->service();
        $app->ouvrir(null, $identifiant, time() - Appairage::DUREE_APPAIRAGE_S - 60);
        $piece = $this->pieceJson($this->mailContenant('X-GED-Appairage'), 'ged-appairage.json');

        try {
            $app->echanger((string) $piece['code'], (string) $piece['verification'], 'poste');
            self::fail('un appairage de plus de 24 h devait être refusé');
        } catch (ErreurApi $e) {
            self::assertSame(400, $e->statut);
            self::assertStringContainsString('expiré', $e->cause);
        }
    }

    public function testUnCodeInconnuEstRefuseEtUnProfilNonVerifieNePeutPasRelier(): void
    {
        $app = $this->service();

        try {
            $app->echanger('code-inexistant', 'AAA-BBB', 'poste');
            self::fail('un code inconnu devait être refusé');
        } catch (ErreurApi $e) {
            self::assertSame(400, $e->statut);
            self::assertStringContainsString('inconnu', $e->cause);
        }

        // Une adresse simplement saisie mais non vérifiée n'autorise pas la liaison.
        $identifiant = 'cmm-nv-' . $this->sel . '@exemple.test';
        $this->profil()->definirAdresse(null, $identifiant, 'nv-' . $this->sel . '@exemple.test');
        try {
            $app->ouvrir(null, $identifiant);
            self::fail('un profil sans adresse vérifiée ne devait pas pouvoir relier');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('vérifi', $e->getMessage());
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
}

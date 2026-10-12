<?php

declare(strict_types=1);

namespace Ged\Tests\Ingestion\Courrier;

use Ged\Config;
use Ged\Ingestion\Courrier\Coffre;
use Ged\Ingestion\Courrier\Comptes;
use Ged\Ingestion\Courrier\Compte;
use Ged\Ingestion\Courrier\ErreurCourrier;
use PHPUnit\Framework\TestCase;

/**
 * Comptes et secrets : le mot de passe d'un compte est CHIFFRÉ au repos, jamais écrit en clair, jamais rendu
 * par l'écran. Test explicite de non-fuite : le fichier de compte, la représentation d'écran et le JSON d'API
 * ne contiennent jamais le clair.
 */
final class CoffreTest extends TestCase
{
    private string $dossier;

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir() . '/gedv2-coffre-' . bin2hex(random_bytes(4));
        mkdir($this->dossier, 0775, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dossier)) {
            return;
        }
        foreach (glob($this->dossier . '/*') ?: [] as $fichier) {
            unlink($fichier);
        }
        @unlink($this->dossier . '/.secret.key');
        rmdir($this->dossier);
        putenv('GED_SECRET_KEY');
    }

    public function testChiffreEtDechiffreAvecLaMemeCle(): void
    {
        $coffre = Coffre::depuisCleBinaire(random_bytes(32));
        $scelle = $coffre->chiffrer('mot-de-passe-imap');

        self::assertStringStartsWith(Coffre::VERSION . ':', $scelle);
        self::assertStringNotContainsString('mot-de-passe-imap', $scelle);
        self::assertSame('mot-de-passe-imap', $coffre->dechiffrer($scelle));
    }

    public function testUneAutreCleRefuseLeDechiffrement(): void
    {
        $scelle = Coffre::depuisCleBinaire(random_bytes(32))->chiffrer('secret');
        $autre = Coffre::depuisCleBinaire(random_bytes(32));

        $this->expectException(ErreurCourrier::class);
        $autre->dechiffrer($scelle);
    }

    public function testUnSecretAltereEstRefuse(): void
    {
        $coffre = Coffre::depuisCleBinaire(random_bytes(32));
        $scelle = $coffre->chiffrer('secret');
        $altere = substr($scelle, 0, -4) . 'AAAA';

        $this->expectException(ErreurCourrier::class);
        $coffre->dechiffrer($altere);
    }

    public function testLaCleVientDeLaConfigurationOuDUneCleLocale(): void
    {
        $cle = base64_encode(random_bytes(32));
        putenv('GED_SECRET_KEY=' . $cle);
        $parEnv = Coffre::depuisConfig(new Config(dirname(__DIR__, 4)), $this->dossier);
        self::assertSame('x', $parEnv->dechiffrer($parEnv->chiffrer('x')));

        putenv('GED_SECRET_KEY');
        $local = Coffre::depuisConfig(new Config(dirname(__DIR__, 4)), $this->dossier);
        self::assertFileExists($this->dossier . '/.secret.key');
        self::assertSame('y', $local->dechiffrer($local->chiffrer('y')));
    }

    public function testUneCleDEnvironnementInvalideEstRefusee(): void
    {
        putenv('GED_SECRET_KEY=' . base64_encode('trop-court'));

        $this->expectException(ErreurCourrier::class);
        Coffre::depuisConfig(new Config(dirname(__DIR__, 4)), $this->dossier);
    }

    public function testLeMotDePasseNEstJamaisEnClairDansLeFichierNiALEcran(): void
    {
        $coffre = Coffre::depuisCleBinaire(random_bytes(32));
        $comptes = new Comptes($coffre, $this->dossier);
        $clair = 'mot-de-passe-très-privé-2026';
        $id = $comptes->creer([
            'nom' => 'Boîte fournisseurs', 'serveur' => 'imap.exemple.test', 'port' => 993,
            'securite' => Compte::SECURITE_SSL, 'utilisateur' => 'ged@exemple.test', 'mot_de_passe' => $clair,
        ]);

        // 1) Le fichier de compte ne contient QUE le chiffré.
        $brut = (string) file_get_contents($this->dossier . '/comptes.json');
        self::assertStringNotContainsString($clair, $brut);
        self::assertStringContainsString(Coffre::VERSION . ':', $brut);

        // 2) La fiche d'écran / d'API porte le masque, jamais le clair ni le chiffré.
        $compte = $comptes->parId($id);
        self::assertNotNull($compte);
        $ecran = json_encode($compte->pourEcran(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($clair, $ecran);
        self::assertStringNotContainsString($compte->secretChiffre, $ecran);
        self::assertSame(Compte::MASQUE, $compte->pourEcran()['password']);

        // 3) Le coffre seul sait rendre le clair.
        self::assertSame($clair, $coffre->dechiffrer($compte->secretChiffre));

        // 4) Un mot de passe laissé au masque conserve le secret existant.
        $comptes->mettreAJour($id, ['nom' => 'Boîte fournisseurs', 'mot_de_passe' => Compte::MASQUE]);
        $relu = $comptes->parId($id);
        self::assertNotNull($relu);
        self::assertSame($clair, $coffre->dechiffrer($relu->secretChiffre));
    }
}

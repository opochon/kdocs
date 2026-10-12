<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Config;
use Ged\Kernel;
use Ged\Securite\Authentification;
use Ged\Securite\ConnexionController;
use Ged\Securite\JournalAudit;
use Ged\Securite\ProtectionBruteForce;
use Ged\Securite\RegistreSessions;
use Ged\Securite\Totp;
use Ged\Tests\Jonctions\SupportJonctions;
use Ged\Ui\Vue;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Famille « double authentification TOTP » du lot ged-parite-api-4.
 *
 * Vecteurs extraits en LECTURE SEULE de `test_api_profile.py` et `test_api_permissions.py`
 * (Paperless-ngx v2.20.5) : enrôlement (`GET`), activation (`POST`), désactivation (`DELETE`, 404 si
 * absente), jeton de profil, et retrait du TOTP d'un compte par un administrateur. Le service GEDv2
 * implémente RFC 6238 (HMAC-SHA1, 6 chiffres, pas de 30 s) sans django-otp ; le secret n'est rendu qu'à
 * l'enrôlement et les codes de secours qu'à l'activation.
 */
final class ApiQuatreTotpTest extends TestCase
{
    use SupportJonctions;

    protected function setUp(): void
    {
        $this->setUpJonctions();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM comptes WHERE identifiant LIKE 'api4-%'");
        $this->pdo->exec('DELETE FROM securite_totp WHERE compte_id NOT IN (SELECT id FROM comptes)');
        $this->tearDownJonctions();
    }

    public function testRejoueLesVecteursTotp(): void
    {
        $donnees = Vecteurs::charger('api-4-totp');
        $app = Kernel::creer(dirname(__DIR__, 3));
        $admin = $this->compteAdministrateurApi4();
        $jetonAdmin = $this->jetonDocuments($admin['compte'], $admin['identifiant'], 'documents_ecriture');
        $contributeur = $this->compteEtDossiers();
        $jetonContributeur = $this->jetonDocuments($contributeur['compte'], $contributeur['identifiant'], 'documents_ecriture');
        $cible = $this->compteSimpleApi4('cible');

        $totp = new Totp($this->pdo);
        $passes = 0;
        $echecs = [];
        foreach ($donnees['vecteurs'] as $vecteur) {
            $id = (string) ($vecteur['id'] ?? '?');
            [$methode, $chemin, $corps, $jeton] = $this->traduire($vecteur, $id, $jetonAdmin, $jetonContributeur, $totp, $cible['compte']);
            [$statut] = $this->appel($app, $methode, $chemin, $jeton, $corps);
            $attendu = (int) ($vecteur['sortie']['statut'] ?? 0);
            if ($statut === $attendu) {
                ++$passes;
            } else {
                $echecs[] = sprintf('%s (%s) : attendu %d, obtenu %d', $id,
                    (string) ($vecteur['reference'] ?? ''), $attendu, $statut);
            }
        }

        Vecteurs::ecrireApi('4-totp', [
            'version' => 1,
            'reference' => $donnees['reference'] ?? [],
            'categorie' => '4-totp',
            'total' => count($donnees['vecteurs']),
            'passes' => $passes,
            'ecarts_assumes' => 0,
            'ecarts' => $echecs,
            'non_rejouables' => count($donnees['non_rejouables'] ?? []),
        ]);
        self::assertSame([], $echecs, "vecteurs TOTP en écart :\n" . implode("\n", $echecs));

        // Non-accès : l'enrôlement sans jeton refuse et se nomme.
        self::assertSame(401, $this->appel($app, 'GET', '/api/v1/profil/totp')[0]);
    }

    /** `account_login` : l'écran de connexion GEDv2 rend le formulaire (route GET /connexion). */
    public function testLEcranDeConnexionEstDisponible(): void
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        $reponse = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/connexion'));
        self::assertSame(200, $reponse->getStatusCode());
        self::assertStringContainsString('data-testid="formulaire-connexion"', (string) $reponse->getBody());
    }

    /** La porte MFA de la connexion (`mfa_authenticate`) exige un code quand le TOTP est actif. */
    public function testLaConnexionExigeLeCodeTotp(): void
    {
        $racine = dirname(__DIR__, 3);
        $dossier = sys_get_temp_dir() . '/gedv2-mfa-' . bin2hex(random_bytes(5));
        mkdir($dossier, 0700, true);
        $compte = $this->compteSimpleApi4('mfa');
        $totp = new Totp($this->pdo);
        $secret = $totp->genererSecret();
        $totp->activer($compte['compte'], $secret);

        $sessions = new RegistreSessions($dossier . '/sessions');
        $controleur = new ConnexionController(
            new Authentification(new Config($racine), $this->pdo),
            $sessions,
            new ProtectionBruteForce($dossier . '/brute-force.json'),
            new JournalAudit($dossier . '/audit.ndjson'),
            new Vue($racine . '/templates'),
            $totp,
        );

        // Sans code : refus nommé 403.
        [$statut, $corps] = $this->tenterConnexion($controleur, $sessions, $compte['identifiant'], '');
        self::assertSame(403, $statut);
        self::assertStringContainsString('code de vérification à deux facteurs requis', $corps);

        // Code faux : refus nommé 403.
        [$statut, $corps] = $this->tenterConnexion($controleur, $sessions, $compte['identifiant'], '000000');
        self::assertSame(403, $statut);
        self::assertStringContainsString('code de vérification à deux facteurs incorrect', $corps);

        // Code valide : session ouverte (303 vers /a-traiter).
        $code = $totp->code($secret, intdiv(time(), Totp::PERIODE));
        [$statut] = $this->tenterConnexion($controleur, $sessions, $compte['identifiant'], $code);
        self::assertSame(303, $statut);

        foreach (glob($dossier . '/*') ?: [] as $element) {
            @unlink($element);
        }
        @rmdir($dossier);
    }

    /** Oracle RFC 6238 (Annexe B, SHA-1) : le code GEDv2 est le vrai TOTP, pas un aléa. */
    public function testLeCodeTotpSuitRfc6238(): void
    {
        $totp = new Totp($this->pdo);
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // ASCII « 12345678901234567890 » en base32
        self::assertSame('287082', $totp->code($secret, 1));       // T = 59 s
        self::assertSame('081804', $totp->code($secret, 37037036)); // T = 1111111109 s
        self::assertTrue($totp->verifier($secret, '287082', 0, 59));
        self::assertFalse($totp->verifier($secret, '000000', 0, 59));
    }

    /** @return array{0:int, 1:string} */
    private function tenterConnexion(ConnexionController $controleur, RegistreSessions $sessions, string $identifiant, string $code): array
    {
        $csrf = $sessions->ouvrirJetonConnexion();
        $requete = (new ServerRequestFactory())->createServerRequest('POST', '/connexion')
            ->withParsedBody([
                'identifiant' => $identifiant, 'mot_de_passe' => 'motdepassefort123', 'csrf' => $csrf, 'code' => $code,
            ])
            ->withCookieParams(['gedv2_csrf' => $csrf]);
        $reponse = $controleur->connecter($requete, new Response());

        return [$reponse->getStatusCode(), (string) $reponse->getBody()];
    }

    /** @param array<string, mixed> $vecteur @return array{0:string, 1:string, 2:array<string, mixed>|null, 3:?string} */
    private function traduire(
        array $vecteur,
        string $id,
        string $jetonAdmin,
        string $jetonContributeur,
        Totp $totp,
        int $compteCible,
    ): array {
        $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
        $chemin = (string) ($entree['chemin'] ?? '');
        $methode = (string) ($entree['methode'] ?? 'GET');

        if ($chemin === '/api/profile/totp/') {
            if ($methode === 'GET') {
                return ['GET', '/api/v1/profil/totp', null, $jetonAdmin];
            }
            if ($methode === 'DELETE') {
                return ['DELETE', '/api/v1/profil/totp', null, $jetonAdmin];
            }
            // Activation : la réussite suppose un secret et un code réels (Paperless mocke la validation).
            $secret = $totp->genererSecret();
            $code = $totp->code($secret, intdiv(time(), Totp::PERIODE));

            return ['POST', '/api/v1/profil/totp', ['secret' => $secret, 'code' => $code], $jetonAdmin];
        }
        if ($chemin === '/api/profile/generate_auth_token/') {
            return ['POST', '/api/v1/jetons', ['application' => 'api4', 'portee' => 'documents_lecture'],
                str_contains($id, 'not_logged_in') ? null : $jetonAdmin];
        }
        if ($chemin === '/api/profile/') {
            return ['GET', '/api/v1/profil', null, null];
        }
        if (str_starts_with($chemin, '/api/users/') && str_ends_with($chemin, '/deactivate_totp/')) {
            // Le premier retrait (009) suppose un TOTP actif ; le second (010) doit trouver 404 : on ne
            // le réactive donc pas. Le 403 (011) est un refus de droit, sans état particulier.
            if (str_ends_with($id, '009')) {
                $totp->activer($compteCible, $totp->genererSecret());
            }

            return ['POST', '/api/v1/comptes/' . $compteCible . '/deactivate_totp', null,
                str_ends_with($id, '011') ? $jetonContributeur : $jetonAdmin];
        }

        return [$methode, $chemin, null, $jetonAdmin];
    }

    /** @return array{compte:int, identifiant:string} */
    private function compteAdministrateurApi4(): array
    {
        return $this->compteApi4('admin', 'administrateur');
    }

    /** @return array{compte:int, identifiant:string} */
    private function compteSimpleApi4(string $prefixe): array
    {
        return $this->compteApi4($prefixe, 'utilisateur');
    }

    /** @return array{compte:int, identifiant:string} */
    private function compteApi4(string $prefixe, string $role): array
    {
        $identifiant = 'api4-' . $prefixe . '-' . bin2hex(random_bytes(4)) . '@exemple.test';
        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute([$identifiant, password_hash('motdepassefort123', PASSWORD_DEFAULT), $role]);

        return ['compte' => (int) $this->pdo->lastInsertId(), 'identifiant' => $identifiant];
    }
}

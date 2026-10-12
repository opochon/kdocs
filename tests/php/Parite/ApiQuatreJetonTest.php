<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Kernel;
use Ged\Securite\Totp;
use Ged\Tests\Jonctions\SupportJonctions;
use PHPUnit\Framework\TestCase;

/**
 * Famille « jeton d'API » du lot ged-parite-api-4.
 *
 * Vecteurs extraits en LECTURE SEULE de `test_api_permissions.py` (Paperless-ngx v2.20.5) :
 * `POST /api/token/` échange identifiant + mot de passe contre un jeton, et exige un code MFA quand la
 * double authentification est active. Côté GEDv2, `POST /api/v1/token` réutilise `Authentification` et
 * `Jetons` : la valeur du jeton n'est rendue qu'à la création, jamais relue.
 */
final class ApiQuatreJetonTest extends TestCase
{
    use SupportJonctions;

    private const MOT_DE_PASSE = 'motdepassefort123';

    protected function setUp(): void
    {
        $this->setUpJonctions();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM comptes WHERE identifiant LIKE 'api4-%'");
        try {
            $this->pdo->exec('DELETE FROM securite_totp WHERE compte_id NOT IN (SELECT id FROM comptes)');
        } catch (\PDOException) {
        }
        $this->tearDownJonctions();
    }

    public function testRejoueLesVecteursDuJetonEtDuCodeMfa(): void
    {
        $donnees = Vecteurs::charger('api-4-jeton');
        $app = Kernel::creer(dirname(__DIR__, 3));
        $compte = $this->compteApi4();
        $totp = new Totp($this->pdo);

        $passes = 0;
        $echecs = [];
        foreach ($donnees['vecteurs'] as $vecteur) {
            $id = (string) ($vecteur['id'] ?? '?');
            [$corps] = $this->traduire($id, $compte['identifiant'], $totp, $compte['compte']);
            [$statut, $reponse] = $this->appel($app, 'POST', '/api/v1/token', null, $corps);
            $attendu = (int) ($vecteur['sortie']['statut'] ?? 0);
            if ($statut === $attendu) {
                ++$passes;
            } else {
                $echecs[] = sprintf('%s (%s) : attendu %d, obtenu %d', $id,
                    (string) ($vecteur['reference'] ?? ''), $attendu, $statut);
            }
            if ($attendu === 200 && $statut === 200) {
                self::assertArrayHasKey('token', $reponse, 'un jeton est rendu à l’obtention');
                self::assertNotSame('', (string) $reponse['token']);
            }
        }

        Vecteurs::ecrireApi('4-jeton', [
            'version' => 1,
            'reference' => $donnees['reference'] ?? [],
            'categorie' => '4-jeton',
            'total' => count($donnees['vecteurs']),
            'passes' => $passes,
            'ecarts_assumes' => 0,
            'ecarts' => $echecs,
            'non_rejouables' => count($donnees['non_rejouables'] ?? []),
        ]);
        self::assertSame([], $echecs, "vecteurs de jeton en écart :\n" . implode("\n", $echecs));

        // Non-accès : de mauvais identifiants ne délivrent aucun jeton et le refus se nomme.
        [$statut, $corps] = $this->appel($app, 'POST', '/api/v1/token', null,
            ['username' => $compte['identifiant'], 'password' => 'mauvais']);
        self::assertSame(400, $statut);
        self::assertArrayNotHasKey('token', $corps);
        self::assertSame('identifiant ou mot de passe incorrect', $corps['erreur']['cause'] ?? null);
    }

    /**
     * @return array{0:array<string, mixed>}
     */
    private function traduire(string $id, string $identifiant, Totp $totp, int $compteId): array
    {
        $base = ['username' => $identifiant, 'password' => self::MOT_DE_PASSE];

        if (str_ends_with($id, '002')) {
            // La MFA est activée entre le premier et le second appel (comme dans le test Paperless).
            $totp->activer($compteId, $totp->genererSecret());
        }
        if (str_ends_with($id, '004')) {
            $etat = $totp->etat($compteId);
            $secret = $etat === null ? $totp->genererSecret() : $etat['secret'];
            $base['code'] = $totp->code($secret, intdiv(time(), Totp::PERIODE));
        }
        if (str_ends_with($id, '003')) {
            $base['code'] = '123456';
        }

        return [$base];
    }

    /** @return array{compte:int, identifiant:string} */
    private function compteApi4(): array
    {
        $identifiant = 'api4-jeton-' . bin2hex(random_bytes(4)) . '@exemple.test';
        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute([$identifiant, password_hash(self::MOT_DE_PASSE, PASSWORD_DEFAULT), 'utilisateur']);

        return ['compte' => (int) $this->pdo->lastInsertId(), 'identifiant' => $identifiant];
    }
}

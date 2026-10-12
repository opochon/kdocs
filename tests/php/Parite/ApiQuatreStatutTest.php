<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Kernel;
use Ged\Tests\Jonctions\SupportJonctions;
use PHPUnit\Framework\TestCase;

/**
 * Famille « état système et version installée » du lot ged-parite-api-4.
 *
 * Vecteurs extraits en LECTURE SEULE de `test_api_status.py` et `test_api_remote_version.py`
 * (Paperless-ngx v2.20.5) : `GET /api/status/` (200 administrateur, 401 anonyme, 403 non-admin) et
 * `GET /api/remote_version/` (200). Côté GEDv2, `GET /api/v1/statut` rend l'état réel (base, stockage,
 * suivi `ingestion_travaux`) et `GET /api/v1/version` la version installée, sans appel réseau.
 */
final class ApiQuatreStatutTest extends TestCase
{
    use SupportJonctions;

    protected function setUp(): void
    {
        $this->setUpJonctions();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM comptes WHERE identifiant LIKE 'api4-%'");
        $this->tearDownJonctions();
    }

    public function testRejoueLesVecteursDeStatutEtDeVersion(): void
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        $admin = $this->compteApi4('admin', 'administrateur');
        $jetonAdmin = $this->jetonDocuments($admin['compte'], $admin['identifiant'], 'documents_lecture');
        $contributeur = $this->compteEtDossiers();
        $jetonContributeur = $this->jetonDocuments($contributeur['compte'], $contributeur['identifiant'], 'documents_lecture');

        $total = 0;
        $passes = 0;
        $echecs = [];
        foreach ([['api-4-statut', $jetonAdmin, $jetonContributeur], ['api-4-version', $jetonAdmin, $jetonContributeur]] as [$famille, $admin2, $lecteur]) {
            $donnees = Vecteurs::charger($famille);
            foreach ($donnees['vecteurs'] as $vecteur) {
                ++$total;
                $id = (string) ($vecteur['id'] ?? '?');
                [$methode, $chemin, $jeton] = $this->traduire($id, $admin2, $lecteur);
                [$statut] = $this->appel($app, $methode, $chemin, $jeton);
                $attendu = (int) ($vecteur['sortie']['statut'] ?? 0);
                if ($statut === $attendu) {
                    ++$passes;
                } else {
                    $echecs[] = sprintf('%s (%s) : attendu %d, obtenu %d', $id,
                        (string) ($vecteur['reference'] ?? ''), $attendu, $statut);
                }
            }
        }

        Vecteurs::ecrireApi('4-statut', [
            'version' => 1,
            'reference' => [],
            'categorie' => '4-statut',
            'total' => $total,
            'passes' => $passes,
            'ecarts_assumes' => 0,
            'ecarts' => $echecs,
            'non_rejouables' => 0,
        ]);
        self::assertSame([], $echecs, "vecteurs de statut/version en écart :\n" . implode("\n", $echecs));

        // L'état système rend des valeurs réelles et nommées, jamais un 500 muet.
        [$statut, $etat] = $this->appel($app, 'GET', '/api/v1/statut', $jetonAdmin);
        self::assertSame(200, $statut);
        self::assertSame('OK', $etat['database']['statut'] ?? null);
        self::assertSame('gedv2_test', $etat['database']['base'] ?? null);
        self::assertSame('ingestion_travaux', $etat['tasks']['file'] ?? null);

        // La version est rendue sans appel réseau, avec la cause nommée.
        [$statut, $version] = $this->appel($app, 'GET', '/api/v1/version', $jetonAdmin);
        self::assertSame(200, $statut);
        self::assertArrayHasKey('version', $version);
        self::assertFalse($version['remote_checked']);

        // Non-accès : sans jeton, l'état système refuse et se nomme.
        self::assertSame(401, $this->appel($app, 'GET', '/api/v1/statut')[0]);
    }

    /** @return array{0:string, 1:string, 2:?string} */
    private function traduire(string $id, string $jetonAdmin, string $jetonContributeur): array
    {
        if (str_starts_with($id, 'version:')) {
            return ['GET', '/api/v1/version', $jetonAdmin];
        }
        if (str_contains($id, 'insufficient_permissions:002')) {
            return ['GET', '/api/v1/statut', null];
        }
        if (str_contains($id, 'insufficient_permissions:003')) {
            return ['GET', '/api/v1/statut', $jetonContributeur];
        }

        return ['GET', '/api/v1/statut', $jetonAdmin];
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

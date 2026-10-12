<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Kernel;
use Ged\Tests\Jonctions\SupportJonctions;
use PHPUnit\Framework\TestCase;

/**
 * Famille « administration » du lot ged-parite-api-3.
 *
 * Vecteurs extraits en LECTURE SEULE de test_api_permissions.py (Paperless-ngx v2.20.5) : UserViewSet et
 * GroupViewSet (list/create/retrieve/update/partial_update). Côté GEDv2, les comptes vivent dans `comptes`
 * et les groupes dans `securite_groupes` ; les verbes passent par `/api/v1/comptes` et `/api/v1/groupes`,
 * réservés au rôle administrateur.
 */
final class ApiTroisAdministrationTest extends TestCase
{
    use SupportJonctions;

    protected function setUp(): void
    {
        $this->setUpJonctions();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM comptes WHERE identifiant LIKE 'api3-%'");
        $this->pdo->exec("DELETE FROM securite_groupes WHERE nom LIKE 'api3-%'");
        $this->tearDownJonctions();
    }

    public function testRejoueLesVecteursDAdministration(): void
    {
        $donnees = Vecteurs::charger('api-3-administration');
        $app = Kernel::creer(dirname(__DIR__, 3));
        $admin = $this->compteAdministrateur();
        $jeton = $this->jetonDocuments($admin['compte'], $admin['identifiant'], 'documents_ecriture');

        $passes = 0;
        $echecs = [];
        foreach ($donnees['vecteurs'] as $vecteur) {
            $id = (string) ($vecteur['id'] ?? '?');
            [$methode, $chemin, $corps] = $this->traduire($id, is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : []);
            [$statut] = $this->appel($app, $methode, $chemin, $jeton, $corps);
            $attendu = (int) ($vecteur['sortie']['statut'] ?? 0);
            if ($statut === $attendu) {
                ++$passes;
            } else {
                $echecs[] = sprintf('%s (%s) : attendu %d, obtenu %d', $id,
                    (string) ($vecteur['reference'] ?? ''), $attendu, $statut);
            }
        }

        Vecteurs::ecrireApi('3-administration', [
            'version' => 1,
            'reference' => $donnees['reference'] ?? [],
            'categorie' => '3-administration',
            'total' => count($donnees['vecteurs']),
            'passes' => $passes,
            'ecarts' => $echecs,
            'non_rejouables' => count($donnees['non_rejouables'] ?? []),
        ]);
        self::assertSame([], $echecs, "vecteurs d'administration en écart :\n" . implode("\n", $echecs));
    }

    public function testLesVerbesDesComptesEtGroupesEtLeRefusDeNonAcces(): void
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        $admin = $this->compteAdministrateur();
        $jeton = $this->jetonDocuments($admin['compte'], $admin['identifiant'], 'documents_ecriture');
        $identifiant = 'api3-parcours-' . bin2hex(random_bytes(4)) . '@exemple.test';

        [$statut, $compte] = $this->appel($app, 'POST', '/api/v1/comptes', $jeton,
            ['username' => $identifiant, 'password' => 'motdepassefort123']);
        self::assertSame(201, $statut);
        $cid = (int) $compte['id'];
        self::assertSame(200, $this->appel($app, 'GET', "/api/v1/comptes/$cid", $jeton)[0]);
        self::assertSame(200, $this->appel($app, 'PATCH', "/api/v1/comptes/$cid", $jeton, ['password' => 'motdepassefort456'])[0]);
        self::assertSame(200, $this->appel($app, 'PUT', "/api/v1/comptes/$cid", $jeton, ['role' => 'utilisateur', 'password' => 'motdepassefort789'])[0]);

        [$statut, $groupe] = $this->appel($app, 'POST', '/api/v1/groupes', $jeton, ['name' => 'api3-Groupe parcours']);
        self::assertSame(201, $statut);
        $gid = (int) $groupe['id'];
        self::assertSame(200, $this->appel($app, 'GET', "/api/v1/groupes/$gid", $jeton)[0]);
        self::assertSame(200, $this->appel($app, 'PATCH', "/api/v1/groupes/$gid", $jeton, ['name' => 'api3-Groupe renommé'])[0]);
        self::assertSame(200, $this->appel($app, 'PUT', "/api/v1/groupes/$gid", $jeton, ['name' => 'api3-Groupe final', 'role' => 'utilisateur'])[0]);

        // Non-accès : sans jeton → 401 ; avec un jeton non administrateur → 403 nommé.
        self::assertSame(401, $this->appel($app, 'GET', '/api/v1/comptes')[0]);
        $simple = $this->compteEtDossiers();
        $jetonSimple = $this->jetonDocuments($simple['compte'], $simple['identifiant']);
        self::assertSame(403, $this->appel($app, 'GET', '/api/v1/comptes', $jetonSimple)[0]);
    }

    /**
     * @param array<string, mixed> $entree
     * @return array{0:string, 1:string, 2:array<string, mixed>}
     */
    private function traduire(string $id, array $entree): array
    {
        $chemin = (string) ($entree['chemin'] ?? '');
        $methode = (string) ($entree['methode'] ?? 'GET');
        if ($chemin === '/api/users/') {
            return $methode === 'GET' ? ['GET', '/api/v1/comptes', []]
                : ['POST', '/api/v1/comptes', ['username' => 'api3-' . substr(md5($id), 0, 8) . '@exemple.test', 'password' => 'motdepassefort123']];
        }
        if (str_starts_with($chemin, '/api/users/')) {
            return ['PATCH', '/api/v1/comptes/' . $this->dernierCompte(), ['password' => 'motdepassefort456']];
        }
        if ($chemin === '/api/groups/') {
            return $methode === 'GET' ? ['GET', '/api/v1/groupes', []]
                : ['POST', '/api/v1/groupes', ['name' => 'api3-Groupe ' . substr(md5($id), 0, 8)]];
        }
        if (str_starts_with($chemin, '/api/groups/')) {
            return ['PATCH', '/api/v1/groupes/' . $this->dernierGroupe(), ['name' => 'api3-Groupe renommé']];
        }

        return [$methode, $chemin, []];
    }

    private function dernierCompte(): int
    {
        $id = (int) $this->pdo->query("SELECT MAX(id) FROM comptes WHERE identifiant LIKE 'api3-%'")->fetchColumn();

        return $id > 0 ? $id : 1;
    }

    private function dernierGroupe(): int
    {
        $id = (int) $this->pdo->query("SELECT MAX(id) FROM securite_groupes WHERE nom LIKE 'api3-%'")->fetchColumn();

        return $id > 0 ? $id : 1;
    }

    /** @return array{compte:int, identifiant:string} */
    private function compteAdministrateur(): array
    {
        $identifiant = 'api3-admin-' . bin2hex(random_bytes(4)) . '@exemple.test';
        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute([$identifiant, password_hash('motdepassefort123', PASSWORD_DEFAULT), 'administrateur']);

        return ['compte' => (int) $this->pdo->lastInsertId(), 'identifiant' => $identifiant];
    }
}

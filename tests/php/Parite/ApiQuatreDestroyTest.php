<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Kernel;
use Ged\Tests\Jonctions\SupportJonctions;
use PHPUnit\Framework\TestCase;

/**
 * Famille « retrait d'un référentiel » du lot ged-parite-api-4.
 *
 * Vecteur transcrit du contrat DRF de `DocumentTypeViewSet.destroy` (Paperless-ngx v2.20.5 n'a pas de
 * test dédié) : DELETE rend 204. Côté GEDv2, `DELETE /api/v1/types/{id}` DÉSACTIVE le type (règle
 * « zéro suppression ») et rend 200 : le type reste lisible, il n'est plus proposé. Écart assumé,
 * mesuré. Les autres retraits de référentiels (étiquettes, champs, vues, comptes, groupes) supposent un
 * drapeau de retrait : laissés absents, chiffrés dans la note de lot.
 */
final class ApiQuatreDestroyTest extends TestCase
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

    public function testLeRetraitDUnTypeDesactiveSansSupprimer(): void
    {
        $donnees = Vecteurs::charger('api-4-destroy');
        $app = Kernel::creer(dirname(__DIR__, 3));
        $admin = $this->compteApi4();
        $jeton = $this->jetonDocuments($admin['compte'], $admin['identifiant'], 'documents_ecriture');

        [$creation, $type] = $this->appel($app, 'POST', '/api/v1/types', $jeton, ['name' => 'Type retirable']);
        self::assertSame(201, $creation);
        $id = (int) $type['id'];
        self::assertTrue($type['active']);

        $passes = 0;
        $assumes = 0;
        $echecs = [];
        foreach ($donnees['vecteurs'] as $vecteur) {
            $idVecteur = (string) ($vecteur['id'] ?? '?');
            [$statut, $corps] = $this->appel($app, 'DELETE', "/api/v1/types/$id", $jeton);
            $attendu = (int) ($vecteur['sortie']['statut'] ?? 0);
            if ($statut === $attendu) {
                ++$passes;
            } elseif ($statut === 200 && ($corps['active'] ?? null) === false) {
                ++$assumes; // 204 suppression (Paperless) → 200 désactivation (GEDv2), écart assumé
            } else {
                $echecs[] = sprintf('%s (%s) : attendu %d, obtenu %d', $idVecteur,
                    (string) ($vecteur['reference'] ?? ''), $attendu, $statut);
            }
        }

        Vecteurs::ecrireApi('4-destroy', [
            'version' => 1,
            'reference' => $donnees['reference'] ?? [],
            'categorie' => '4-destroy',
            'total' => count($donnees['vecteurs']),
            'passes' => $passes,
            'ecarts_assumes' => $assumes,
            'ecarts' => $echecs,
            'non_rejouables' => count($donnees['non_rejouables'] ?? []),
        ]);
        self::assertSame([], $echecs, "vecteurs de retrait en écart :\n" . implode("\n", $echecs));
        self::assertSame(1, $assumes, 'la désactivation remplace la suppression : écart assumé');

        // Le type reste LISIBLE (zéro suppression) mais inactif.
        [$lecture, $relu] = $this->appel($app, 'GET', "/api/v1/types/$id", $jeton);
        self::assertSame(200, $lecture);
        self::assertFalse($relu['active']);

        // Non-accès : sans jeton, le retrait refuse et se nomme.
        [$refus, $corpsRefus] = $this->appel($app, 'DELETE', "/api/v1/types/$id");
        self::assertSame(401, $refus);
        self::assertArrayHasKey('erreur', $corpsRefus);
    }

    /** @return array{compte:int, identifiant:string} */
    private function compteApi4(): array
    {
        $identifiant = 'api4-destroy-' . bin2hex(random_bytes(4)) . '@exemple.test';
        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute([$identifiant, password_hash('motdepassefort123', PASSWORD_DEFAULT), 'administrateur']);

        return ['compte' => (int) $this->pdo->lastInsertId(), 'identifiant' => $identifiant];
    }
}

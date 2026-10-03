<?php

declare(strict_types=1);

namespace Ged\Tests\Securite;

use Ged\Base;
use Ged\Config;
use Ged\Securite\DroitsDossiers;
use Ged\Securite\DroitsRoutes;
use Ged\Securite\Schema;
use Ged\Socle\Schema as SchemaSocle;
use PHPUnit\Framework\TestCase;

/** Oracle V2-13 : décision du 03-10, rôles fonctionnels et niveaux d'ACL. */
final class V213DroitsRolesTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $config = new Config(dirname(__DIR__, 3));
        Base::assurerBase($config);
        $this->pdo = Base::pdo($config);
        SchemaSocle::installer($this->pdo);
        Schema::installer($this->pdo);
    }

    public function testMatriceCanoniqueSuitLaDecisionEtCouvreLesRoutes(): void
    {
        $matrice = Schema::matriceRoles();

        self::assertSame(['utilisateur', 'manager', 'administrateur'], array_keys($matrice));
        self::assertContains('documents:deposer', $matrice['utilisateur']);
        self::assertContains('documents:partager-externe', $matrice['manager']);
        self::assertContains('archivage:sceller', $matrice['manager']);
        self::assertContains('application:administrer', $matrice['administrateur']);

        $couvertes = array_fill_keys(array_merge(...array_values($matrice)), true);
        foreach (DroitsRoutes::declarees() as $permission) {
            if (!DroitsRoutes::estPublique($permission) && $permission !== 'session:active') {
                self::assertArrayHasKey(DroitsRoutes::permissionCanonique($permission), $couvertes, $permission);
            }
        }
    }

    public function testNiveauxDeDossierSontCumulatifsEtLeRefusRestePrioritaire(): void
    {
        $droits = new DroitsDossiers($this->pdo);
        $suffixe = bin2hex(random_bytes(5));
        $compte = $this->creerCompte($suffixe, 'utilisateur');
        $groupe = $droits->creerGroupe('v213-' . $suffixe, 'utilisateur');
        $droits->ajouterCompteAuGroupe($compte, $groupe);
        $racine = 8501;
        $enfant = 8502;
        $droits->poserDossier($racine, null, 'v213-' . $suffixe);
        $droits->poserDossier($enfant, $racine, 'v213-' . $suffixe . '/enfant');
        $droits->poserRegle($racine, $groupe, 'deposer', true);

        self::assertTrue($droits->autorise($compte, $enfant, 'lire'));
        self::assertTrue($droits->autorise($compte, $enfant, 'deposer'));
        self::assertFalse($droits->autorise($compte, $enfant, 'modifier'));
        $droits->poserRegle($enfant, $groupe, 'lire', false);
        self::assertFalse($droits->autorise($compte, $enfant, 'lire'));
        self::assertFalse($droits->autorise($compte, $enfant, 'deposer'));
    }

    private function creerCompte(string $suffixe, string $role): int
    {
        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute(['v213-' . $suffixe . '@gedv2.test', password_hash('MotDePasse-V213!', PASSWORD_DEFAULT), $role]);

        return (int) $this->pdo->lastInsertId();
    }
}

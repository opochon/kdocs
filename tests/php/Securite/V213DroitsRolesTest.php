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

        self::assertSame([
            'utilisateur' => ['documents:lire', 'recherche:lire', 'documents:deposer'],
            'manager' => [
                'documents:lire', 'recherche:lire', 'documents:deposer', 'documents:traiter', 'documents:modifier',
                'documents:partager-externe', 'archivage:consulter', 'archivage:sceller', 'dossiers:gerer',
                'workflows:lire', 'workflow.gerer', 'workflow.executer', 'workflow.transition',
            ],
            'administrateur' => [
                '*', 'documents:lire', 'recherche:lire', 'documents:deposer', 'documents:traiter', 'documents:modifier',
                'documents:partager-externe', 'archivage:consulter', 'archivage:sceller', 'dossiers:gerer',
                'workflows:lire', 'workflow.gerer', 'workflow.executer', 'workflow.transition', 'application:administrer',
            ],
        ], $matrice);

        $couvertes = array_fill_keys(array_merge(...array_values($matrice)), true);
        foreach (DroitsRoutes::declarees() as $permission) {
            if (!DroitsRoutes::estPublique($permission) && $permission !== 'session:active') {
                self::assertArrayHasKey(DroitsRoutes::permissionCanonique($permission), $couvertes, $permission);
            }
        }
    }

    public function testMigrationDesRolesEtGroupesInitiauxEstIdempotente(): void
    {
        $suffixe = bin2hex(random_bytes(5));
        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute(['migration-' . $suffixe . '@gedv2.test', password_hash('MotDePasse-V213!', PASSWORD_DEFAULT), 'lecteur']);
        $compte = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO securite_groupes (nom, role_code) VALUES (?, ?)')->execute(['legacy-' . $suffixe, 'archiveur']);
        $groupe = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO securite_groupes_comptes (groupe_id, compte_id) VALUES (?, ?)')->execute([$groupe, $compte]);
        $this->pdo->prepare('INSERT INTO securite_dossiers_droits (dossier_id, groupe_id, action_code, autorise) VALUES (?, ?, ?, 1)')
            ->execute([8601, $groupe, 'archiver']);

        Schema::installer($this->pdo);
        Schema::installer($this->pdo);

        self::assertSame('utilisateur', $this->valeur('SELECT role FROM comptes WHERE id = ?', [$compte]));
        self::assertSame('manager', $this->valeur('SELECT role_code FROM securite_groupes WHERE id = ?', [$groupe]));
        self::assertSame('gerer', $this->valeur('SELECT action_code FROM securite_dossiers_droits WHERE dossier_id = 8601 AND groupe_id = ?', [$groupe]));
        self::assertSame(['Managers' => 'manager', 'Utilisateurs' => 'utilisateur'], $this->groupesInitiaux());
    }

    public function testMigrationConserveLesRolesHistoriquesEtJournaliseChaqueChangement(): void
    {
        $suffixe = bin2hex(random_bytes(5));
        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute(['audit-' . $suffixe . '@gedv2.test', password_hash('MotDePasse-V213!', PASSWORD_DEFAULT), 'contributeur']);
        $compte = (int) $this->pdo->lastInsertId();
        Schema::installer($this->pdo);

        self::assertSame('manager', $this->valeur('SELECT role FROM comptes WHERE id = ?', [$compte]));
        self::assertSame('0', $this->valeur('SELECT actif FROM securite_roles WHERE code = ?', ['contributeur']));
        self::assertSame('manager', $this->valeur('SELECT alias_vers FROM securite_roles WHERE code = ?', ['contributeur']));
        self::assertSame('migration', $this->valeur('SELECT auteur FROM securite_audit WHERE cible = ? ORDER BY id DESC LIMIT 1', ['compte:' . $compte]));
    }

    public function testMigrationArchiverVersGererRespecteRefusSansCollisionDeCle(): void
    {
        $suffixe = bin2hex(random_bytes(5));
        $droits = new DroitsDossiers($this->pdo);
        $groupe = $droits->creerGroupe('collision-' . $suffixe, 'manager');
        $droits->poserDossier(8701, null, 'collision-' . $suffixe);
        $this->pdo->prepare('INSERT INTO securite_dossiers_droits (dossier_id, groupe_id, action_code, autorise) VALUES (?, ?, ?, ?)')
            ->execute([8701, $groupe, 'archiver', 0]);
        $droits->poserRegle(8701, $groupe, 'gerer', true);

        Schema::installer($this->pdo);

        self::assertSame('0', $this->valeur('SELECT autorise FROM securite_dossiers_droits WHERE dossier_id = ? AND groupe_id = ? AND action_code = ?', [8701, $groupe, 'gerer']));
        self::assertSame('0', $this->valeur('SELECT autorise FROM securite_dossiers_droits WHERE dossier_id = ? AND groupe_id = ? AND action_code = ?', [8701, $groupe, 'archiver']));
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

    /** @param list<int|string> $parametres */
    private function valeur(string $sql, array $parametres): string
    {
        $requete = $this->pdo->prepare($sql);
        $requete->execute($parametres);

        return (string) $requete->fetchColumn();
    }

    /** @return array<string, string> */
    private function groupesInitiaux(): array
    {
        $lignes = $this->pdo->query("SELECT nom, role_code FROM securite_groupes WHERE nom IN ('Utilisateurs', 'Managers') ORDER BY nom")
            ->fetchAll(\PDO::FETCH_KEY_PAIR);

        return array_map('strval', $lignes);
    }
}

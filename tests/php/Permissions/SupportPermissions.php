<?php

declare(strict_types=1);

namespace Ged\Tests\Permissions;

use Ged\Base;
use Ged\Config;
use Ged\Socle\Registre;
use Ged\Socle\Schema as SchemaSocle;
use Ged\Workflows\Schema as SchemaWorkflows;
use Ged\Recherche\Schema as SchemaRecherche;
use Ged\Securite\Schema as SchemaSecurite;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Socle commun des oracles de la famille M-Files « permissions dynamiques » (lot
 * ged-mfiles-permissions-1) : base gedv2_test remise à neuf, racine documentaire éphémère hors du
 * dépôt (aucune donnée réelle, aucun corpus commité), registre du fonds vidé.
 */
trait SupportPermissions
{
    private PDO $pdo;
    private string $racine;      // racine documentaire du test (STORAGE_ROOT)
    private Config $config;
    private string|false $storageRootAvant;

    protected function setUpPermissions(): void
    {
        $this->config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $this->config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($this->config);
        $this->pdo = Base::pdo($this->config);
        $this->pdo->exec('DROP TABLE IF EXISTS document_collaboration, document_collaboration_evenements, '
            . 'document_notes, document_liens, document_partages, reconstruction_index_jobs, '
            . 'documents, document_versions, document_deplacements, comptes, installation, '
            . 'workflow_definitions, workflow_instances, workflow_journal, recherche_textes, '
            . 'securite_roles, securite_permissions, securite_roles_permissions, securite_groupes, '
            . 'securite_groupes_comptes, securite_dossiers, securite_dossiers_droits');
        SchemaSocle::installer($this->pdo);
        SchemaSecurite::installer($this->pdo);
        SchemaWorkflows::installer($this->pdo);
        SchemaRecherche::installer($this->pdo);

        $this->storageRootAvant = getenv('STORAGE_ROOT');
        $this->racine = sys_get_temp_dir() . '/gedv2-perm-' . bin2hex(random_bytes(4));
        mkdir($this->racine . '/depots', 0775, true);
        putenv('STORAGE_ROOT=' . $this->racine . '/fonds');
        Registre::oublier();
    }

    protected function tearDownPermissions(): void
    {
        $this->storageRootAvant === false ? putenv('STORAGE_ROOT')
            : putenv('STORAGE_ROOT=' . $this->storageRootAvant);
        $this->supprimerRacine($this->racine);
        Registre::oublier();
    }

    /** Remet le fonds et les données du lot à neuf entre deux vecteurs (mêmes tables que setUp). */
    protected function reinitialiserPermissions(): void
    {
        $this->pdo->exec('DELETE FROM documents');
        $this->pdo->exec('DELETE FROM securite_dossiers_droits');
        $this->pdo->exec('DELETE FROM securite_dossiers');
        $this->pdo->exec('DELETE FROM securite_groupes_comptes');
        $this->pdo->exec('DELETE FROM securite_groupes');
        $this->pdo->exec('DELETE FROM comptes');
        $this->pdo->exec('DELETE FROM workflow_instances');
        $this->pdo->exec('DELETE FROM workflow_definitions');
        $this->pdo->exec('DELETE FROM recherche_textes');
        $this->supprimerRacine($this->racine . '/fonds');
        Registre::oublier();
    }

    /** Un fichier source à déposer, hors de la racine documentaire (comme une pièce extraite d'un lot). */
    private function pieceADeposer(string $nom, string $contenu): string
    {
        $f = $this->racine . '/depots/' . $nom;
        file_put_contents($f, $contenu);

        return $f;
    }

    private function supprimerRacine(string $chemin): void
    {
        if (!is_dir($chemin)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($chemin, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($chemin);
    }

    /** Crée un groupe de sécurité réel en base et rend son identifiant. */
    private function creerGroupe(string $nom, string $role): int
    {
        $this->pdo->prepare('INSERT INTO securite_groupes (nom, role_code) VALUES (?, ?)')->execute([$nom, $role]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Crée un compte réel en base (mot de passe haché synthétique) et rend son identifiant. */
    private function creerCompte(string $identifiant, string $role): int
    {
        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute([$identifiant, password_hash('MotDePasse-Synthetique-2026!', PASSWORD_DEFAULT), $role]);

        return (int) $this->pdo->lastInsertId();
    }

    private function ajouterAuGroupe(int $compte, int $groupe): void
    {
        $this->pdo->prepare('INSERT IGNORE INTO securite_groupes_comptes (groupe_id, compte_id) VALUES (?, ?)')
            ->execute([$groupe, $compte]);
    }
}

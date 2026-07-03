<?php

declare(strict_types=1);

namespace Tests\Unit;

use KDocs\Services\FolderPermissionService;
use PHPUnit\Framework\TestCase;

/**
 * GAP-040 — ACL document fine : FolderPermissionService::can($user, $doc, $action)
 * avec héritage via parent_id des dossiers logiques.
 * Hermétique : SQLite en mémoire injecté (folder_permissions + logical_folders).
 */
class FolderPermissionTest extends TestCase
{
    private \PDO $db;
    private FolderPermissionService $service;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->db->exec('CREATE TABLE logical_folders (id INTEGER PRIMARY KEY, name TEXT, parent_id INTEGER)');
        $this->db->exec('CREATE TABLE folder_permissions (
            id INTEGER PRIMARY KEY,
            folder_id INTEGER NOT NULL,
            subject_type TEXT NOT NULL,
            subject_id INTEGER NOT NULL,
            can_read INTEGER NOT NULL DEFAULT 0,
            can_write INTEGER NOT NULL DEFAULT 0,
            can_delete INTEGER NOT NULL DEFAULT 0,
            created_at TEXT
        )');
        $this->db->exec('CREATE TABLE user_group_memberships (user_id INTEGER, group_id INTEGER)');

        // Arborescence : racine (1) → compta (2) → factures 2026 (3)
        $this->db->exec("INSERT INTO logical_folders (id, name, parent_id) VALUES
            (1, 'Racine', NULL),
            (2, 'Compta', 1),
            (3, 'Factures 2026', 2)");

        $this->service = new FolderPermissionService($this->db);
    }

    public function testPermissionExpliciteAccordee(): void
    {
        $this->db->exec("INSERT INTO folder_permissions (folder_id, subject_type, subject_id, can_read)
                         VALUES (3, 'user', 5, 1)");

        $this->assertTrue($this->service->can(['id' => 5], ['folder_id' => 3], 'read'));
    }

    public function testPermissionExpliciteRefusee(): void
    {
        $this->db->exec("INSERT INTO folder_permissions (folder_id, subject_type, subject_id, can_read)
                         VALUES (3, 'user', 5, 0)");

        $this->assertFalse($this->service->can(['id' => 5], ['folder_id' => 3], 'read'));
    }

    public function testHeritageDepuisDossierParentDeuxNiveaux(): void
    {
        // ACL posé sur la racine uniquement : refus de lecture pour l'user 5.
        $this->db->exec("INSERT INTO folder_permissions (folder_id, subject_type, subject_id, can_read)
                         VALUES (1, 'user', 5, 0)");

        $this->assertFalse(
            $this->service->can(['id' => 5], ['folder_id' => 3], 'read'),
            'le refus posé sur la racine doit être hérité deux niveaux plus bas'
        );
    }

    public function testAclLePlusProcheGagneSurLHeritage(): void
    {
        // Refus à la racine mais autorisation explicite sur le dossier du document.
        $this->db->exec("INSERT INTO folder_permissions (folder_id, subject_type, subject_id, can_read) VALUES
            (1, 'user', 5, 0),
            (3, 'user', 5, 1)");

        $this->assertTrue($this->service->can(['id' => 5], ['folder_id' => 3], 'read'));
    }

    public function testAdminBypass(): void
    {
        $this->db->exec("INSERT INTO folder_permissions (folder_id, subject_type, subject_id, can_read)
                         VALUES (3, 'user', 5, 0)");

        $this->assertTrue($this->service->can(['id' => 5, 'role' => 'admin'], ['folder_id' => 3], 'read'));
        $this->assertTrue($this->service->can(['id' => 5, 'is_admin' => 1], ['folder_id' => 3], 'read'));
    }

    public function testAucunAclSurLaChaineResteOuvert(): void
    {
        // Comportement legacy : sans ACL défini, l'accès reste autorisé.
        $this->assertTrue($this->service->can(['id' => 9], ['folder_id' => 3], 'read'));
    }

    public function testDocumentSansDossierResteOuvert(): void
    {
        $this->assertTrue($this->service->can(['id' => 9], ['folder_id' => null], 'read'));
    }

    public function testActionsWriteEtReadDistinctes(): void
    {
        $this->db->exec("INSERT INTO folder_permissions (folder_id, subject_type, subject_id, can_read, can_write)
                         VALUES (3, 'user', 5, 1, 0)");

        $this->assertTrue($this->service->can(['id' => 5], ['folder_id' => 3], 'read'));
        $this->assertFalse($this->service->can(['id' => 5], ['folder_id' => 3], 'write'));
    }

    public function testPermissionParGroupe(): void
    {
        $this->db->exec('INSERT INTO user_group_memberships (user_id, group_id) VALUES (5, 20)');
        $this->db->exec("INSERT INTO folder_permissions (folder_id, subject_type, subject_id, can_read)
                         VALUES (3, 'group', 20, 0)");

        $this->assertFalse($this->service->can(['id' => 5], ['folder_id' => 3], 'read'));
        // Un autre utilisateur hors groupe reste sur le comportement ouvert.
        $this->assertTrue($this->service->can(['id' => 6], ['folder_id' => 3], 'read'));
    }

    public function testTableAbsenteResteOuvert(): void
    {
        // Migration non appliquée : ne jamais bloquer (pattern LegalSealGuard).
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $service = new FolderPermissionService($db);

        $this->assertTrue($service->can(['id' => 5], ['folder_id' => 3], 'read'));
    }
}

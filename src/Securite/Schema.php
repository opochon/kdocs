<?php

declare(strict_types=1);

namespace Ged\Securite;

use PDO;

/** Schéma des ACL : les comptes restent la propriété du Socle, leurs droits vivent ici. */
final class Schema
{
    /**
     * Espace réservé aux dossiers d'ACL créés à la volée par le rattachement des documents rangés.
     * Les identifiants posés explicitement restent petits ; l'auto-incrément démarre au-dessus.
     */
    public const DOSSIER_ID_AUTO_MIN = 1000000;

    public static function installer(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS securite_roles (
              code VARCHAR(64) PRIMARY KEY,
              libelle VARCHAR(128) NOT NULL,
              actif TINYINT(1) NOT NULL DEFAULT 1,
              alias_vers VARCHAR(64) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        self::assurerColonnesRoles($pdo);
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS securite_permissions (
              code VARCHAR(64) PRIMARY KEY,
              libelle VARCHAR(128) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS securite_roles_permissions (
              role_code VARCHAR(64) NOT NULL,
              permission_code VARCHAR(64) NOT NULL,
              PRIMARY KEY (role_code, permission_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS securite_groupes (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              nom VARCHAR(128) NOT NULL,
              role_code VARCHAR(64) NOT NULL,
              cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uq_securite_groupe_nom (nom)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS securite_groupes_comptes (
              groupe_id INT UNSIGNED NOT NULL,
              compte_id INT UNSIGNED NOT NULL,
              cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (groupe_id, compte_id),
              KEY k_securite_groupe_compte (compte_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS securite_dossiers (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              parent_id INT UNSIGNED NULL,
              chemin_relatif VARCHAR(1024) NOT NULL,
              cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uq_securite_dossier_chemin (chemin_relatif(700)),
              KEY k_securite_dossier_parent (parent_id)
            ) ENGINE=InnoDB AUTO_INCREMENT=1000000 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        // Une table posée par une version antérieure n'a pas l'auto-incrément, et une table neuve doit
        // réserver un espace d'identifiants hors des ids explicites des dossiers créés par les tests ou
        // l'administration : le rattachement à la volée ne doit jamais écraser un dossier existant.
        self::assurerDossierIdAuto($pdo);
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS securite_dossiers_droits (
              dossier_id INT UNSIGNED NOT NULL,
              groupe_id INT UNSIGNED NOT NULL,
              action_code VARCHAR(16) NOT NULL,
              autorise TINYINT(1) NOT NULL,
              cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              maj_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (dossier_id, groupe_id, action_code),
              KEY k_securite_droit_groupe (groupe_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS securite_audit (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              action VARCHAR(64) NOT NULL,
              cible VARCHAR(128) NOT NULL,
              ancien VARCHAR(64) NULL,
              nouveau VARCHAR(64) NULL,
              auteur VARCHAR(64) NOT NULL,
              cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uq_securite_audit_migration (action, cible, ancien, nouveau, auteur),
              KEY k_securite_audit_cible (cible, cree_le)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        self::migrerRolesHistoriques($pdo);

        foreach ([
            'utilisateur' => 'Utilisateur', 'manager' => 'Manager', 'administrateur' => 'Administrateur',
            'aucun' => 'Aucun droit',
        ] as $code => $libelle) {
            $pdo->prepare('INSERT IGNORE INTO securite_roles (code, libelle) VALUES (?, ?)')->execute([$code, $libelle]);
        }
        foreach (self::libellesPermissions() as $code => $libelle) {
            $pdo->prepare('INSERT IGNORE INTO securite_permissions (code, libelle) VALUES (?, ?)')->execute([$code, $libelle]);
        }
        foreach (self::matriceRoles() as $role => $permissions) {
            foreach ($permissions as $permission) {
                $pdo->prepare('INSERT IGNORE INTO securite_roles_permissions (role_code, permission_code) VALUES (?, ?)')
                    ->execute([$role, $permission]);
            }
        }
        foreach (['Utilisateurs' => 'utilisateur', 'Managers' => 'manager'] as $nom => $role) {
            $pdo->prepare('INSERT IGNORE INTO securite_groupes (nom, role_code) VALUES (?, ?)')->execute([$nom, $role]);
        }
    }

    /** @return array<string, list<string>> Matrice de la décision du 2026-10-03. */
    public static function matriceRoles(): array
    {
        return [
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
        ];
    }

    /** @return array<string, string> */
    private static function libellesPermissions(): array
    {
        return [
            '*' => 'Toutes les permissions', 'documents:lire' => 'Lire les documents',
            'documents:deposer' => 'Déposer des documents', 'documents:traiter' => 'Traiter les documents',
            'documents:modifier' => 'Modifier les documents', 'documents:partager-externe' => 'Partager en externe',
            'recherche:lire' => 'Rechercher', 'workflows:lire' => 'Consulter les workflows',
            'workflow.gerer' => 'Gérer les workflows', 'workflow.executer' => 'Exécuter les workflows',
            'workflow.transition' => 'Faire avancer les workflows', 'archivage:consulter' => 'Consulter les archives',
            'archivage:sceller' => 'Sceller les archives', 'dossiers:gerer' => 'Gérer les droits des dossiers',
            'application:administrer' => 'Administrer l’application',
        ];
    }

    /** Migration conservatrice : les affectations changent de code, jamais de propriétaire ni de trace. */
    private static function migrerRolesHistoriques(PDO $pdo): void
    {
        foreach (['admin' => 'administrateur', 'lecteur' => 'utilisateur', 'contributeur' => 'manager', 'archiveur' => 'manager'] as $ancien => $nouveau) {
            self::journaliserMigration($pdo, 'compte', 'role', $ancien, $nouveau);
            self::journaliserMigration($pdo, 'groupe', 'role_code', $ancien, $nouveau);
            $pdo->prepare('UPDATE comptes SET role = ? WHERE role = ?')->execute([$nouveau, $ancien]);
            $pdo->prepare('UPDATE securite_groupes SET role_code = ? WHERE role_code = ?')->execute([$nouveau, $ancien]);
            $pdo->prepare('INSERT IGNORE INTO securite_roles (code, libelle, actif, alias_vers) VALUES (?, ?, 0, ?)')
                ->execute([$ancien, ucfirst($ancien) . ' (historique)', $nouveau]);
            $pdo->prepare('UPDATE securite_roles SET actif = 0, alias_vers = ? WHERE code = ?')->execute([$nouveau, $ancien]);
        }
        // Une règle `gerer` déjà présente et son historique `archiver` ont la même clé cible : le refus
        // explicite gagne, sinon l'accord. L'ancienne règle reste lisible comme trace de migration.
        $pdo->exec("UPDATE securite_dossiers_droits gerer JOIN securite_dossiers_droits archiver
            ON gerer.dossier_id = archiver.dossier_id AND gerer.groupe_id = archiver.groupe_id
            SET gerer.autorise = IF(gerer.autorise = 0 OR archiver.autorise = 0, 0, 1)
            WHERE gerer.action_code = 'gerer' AND archiver.action_code = 'archiver'");
        $pdo->exec("UPDATE securite_dossiers_droits archiver LEFT JOIN securite_dossiers_droits gerer
            ON gerer.dossier_id = archiver.dossier_id AND gerer.groupe_id = archiver.groupe_id AND gerer.action_code = 'gerer'
            SET archiver.action_code = 'gerer' WHERE archiver.action_code = 'archiver' AND gerer.dossier_id IS NULL");
    }

    private static function assurerColonnesRoles(PDO $pdo): void
    {
        foreach (['actif' => 'TINYINT(1) NOT NULL DEFAULT 1', 'alias_vers' => 'VARCHAR(64) NULL'] as $colonne => $definition) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute(['securite_roles', $colonne]);
            if ((int) $stmt->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE securite_roles ADD COLUMN $colonne $definition");
            }
        }
    }

    private static function journaliserMigration(PDO $pdo, string $type, string $champ, string $ancien, string $nouveau): void
    {
        $source = $type === 'compte' ? 'comptes' : 'securite_groupes';
        $identifiant = $type === 'compte' ? 'id' : 'id';
        $stmt = $pdo->prepare("SELECT $identifiant FROM $source WHERE $champ = ?");
        $stmt->execute([$ancien]);
        $audit = $pdo->prepare('INSERT IGNORE INTO securite_audit (action, cible, ancien, nouveau, auteur) VALUES (?, ?, ?, ?, ?)');
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $audit->execute(['changement_role', $type . ':' . $id, $ancien, $nouveau, 'migration']);
        }
    }

    /**
     * Pose l'auto-incrément sur `securite_dossiers.id` s'il manque (table créée avant le rattachement
     * à la volée), puis réserve un espace d'identifiants d'ACL au-dessus de {@see DOSSIER_ID_AUTO_MIN}.
     *
     * Cet espace est volontairement haut : les dossiers posés explicitement (administration, tests)
     * utilisent des identifiants petits, et le rattachement automatique ne doit jamais retomber sur
     * l'un d'eux — un identifiant pris serait réécrit en silence. Idempotent : rejouer ne change rien.
     */
    public static function assurerDossierIdAuto(PDO $pdo): void
    {
        $extra = $pdo->query("SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = 'securite_dossiers' AND COLUMN_NAME = 'id'")->fetchColumn();
        if (!is_string($extra) || stripos($extra, 'auto_increment') === false) {
            $pdo->exec('ALTER TABLE securite_dossiers MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT');
        }

        $suivant = (int) $pdo->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = 'securite_dossiers'")->fetchColumn();
        if ($suivant < self::DOSSIER_ID_AUTO_MIN) {
            $pdo->exec('ALTER TABLE securite_dossiers AUTO_INCREMENT = ' . self::DOSSIER_ID_AUTO_MIN);
        }
    }
}

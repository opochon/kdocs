<?php

declare(strict_types=1);

namespace Ged\Securite;

use PDO;

/** Schéma des ACL : les comptes restent la propriété du Socle, leurs droits vivent ici. */
final class Schema
{
    public static function installer(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS securite_roles (
              code VARCHAR(64) PRIMARY KEY,
              libelle VARCHAR(128) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
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
              id INT UNSIGNED PRIMARY KEY,
              parent_id INT UNSIGNED NULL,
              chemin_relatif VARCHAR(1024) NOT NULL,
              cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uq_securite_dossier_chemin (chemin_relatif(700)),
              KEY k_securite_dossier_parent (parent_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
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

        foreach ([
            'admin' => 'Administrateur', 'lecteur' => 'Lecteur', 'contributeur' => 'Contributeur',
            'archiveur' => 'Archiveur', 'aucun' => 'Aucun droit',
        ] as $code => $libelle) {
            $pdo->prepare('INSERT IGNORE INTO securite_roles (code, libelle) VALUES (?, ?)')->execute([$code, $libelle]);
        }
        foreach ([
            '*' => 'Toutes les permissions', 'documents:lire' => 'Lire les documents',
            'documents:modifier' => 'Modifier les documents', 'documents:archiver' => 'Archiver les documents',
            'recherche:lire' => 'Rechercher', 'workflows:lire' => 'Consulter les workflows',
            'application:administrer' => 'Administrer l’application',
        ] as $code => $libelle) {
            $pdo->prepare('INSERT IGNORE INTO securite_permissions (code, libelle) VALUES (?, ?)')->execute([$code, $libelle]);
        }
        foreach ([
            ['admin', '*'], ['lecteur', 'documents:lire'], ['lecteur', 'recherche:lire'],
            ['contributeur', 'documents:lire'], ['contributeur', 'documents:modifier'],
            ['archiveur', 'documents:lire'], ['archiveur', 'documents:archiver'],
        ] as [$role, $permission]) {
            $pdo->prepare('INSERT IGNORE INTO securite_roles_permissions (role_code, permission_code) VALUES (?, ?)')
                ->execute([$role, $permission]);
        }
    }
}

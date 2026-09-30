<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use PDO;

/**
 * Tables du secteur Jonctions (API d'entrée, appairage). Idempotent : rejouable sur une base existante.
 * Aucune donnée utilisateur n'y est supprimée : un jeton révoqué reste comme trace.
 */
final class Schema
{
    public static function installer(PDO $pdo): void
    {
        // Un jeton par application ou par appareil appairé. Seule l'empreinte sha256 est stockée : le
        // jeton en clair n'existe qu'à sa création, côté appelant. Un jeton d'appairage est lié au COMPTE
        // (`compte_id` / `compte_identifiant`) : c'est l'identité sous laquelle la GED applique ses droits.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS jonctions_jetons (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              application VARCHAR(100) NOT NULL,
              empreinte CHAR(64) NOT NULL COMMENT 'sha256 du jeton ; jamais le jeton en clair',
              portee VARCHAR(20) NOT NULL DEFAULT 'depot' COMMENT 'depot | *',
              compte_id INT UNSIGNED NULL COMMENT 'compte du secteur Droits ; null pour un jeton d’application',
              compte_identifiant VARCHAR(255) NULL COMMENT 'identifiant du compte (compte synthétique de test inclus)',
              appareil VARCHAR(255) NULL COMMENT 'poste appairé ; null pour un jeton d’application',
              cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              derniere_utilisation DATETIME NULL,
              revoque_le DATETIME NULL COMMENT 'révocation = trace, jamais une suppression',
              UNIQUE KEY uq_jonctions_jeton_empreinte (empreinte),
              KEY k_jonctions_jeton_application (application),
              KEY k_jonctions_jeton_compte (compte_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        // Colonnes ajoutées par V2-08b : la table V2-08a n'avait pas de lien de compte.
        self::assurerColonne($pdo, 'compte_id', 'INT UNSIGNED NULL COMMENT \'compte du secteur Droits ; null pour un jeton d’application\'');
        self::assurerColonne($pdo, 'compte_identifiant', 'VARCHAR(255) NULL COMMENT \'identifiant du compte (compte synthétique de test inclus)\'');
        self::assurerColonne($pdo, 'appareil', 'VARCHAR(255) NULL COMMENT \'poste appairé ; null pour un jeton d’application\'');
        self::assurerIndex($pdo, 'k_jonctions_jeton_compte', 'compte_id');

        // Profil d'un utilisateur : adresse mail (utilisable seulement une fois VÉRIFIÉE par un lien
        // envoyé à cette adresse) et, au plus, UN appairage en attente de validation par CMM.
        // Budget de tables serré (plafond V2-04) : ces deux objets partagent volontairement la table,
        // leurs cycles de vie sont liés au même compte. Un nouvel appairage remplace le précédent.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS jonctions_profils (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              compte_id INT UNSIGNED NULL COMMENT 'compte du secteur Droits ; null pour le compte synthétique de test',
              identifiant VARCHAR(255) NOT NULL COMMENT 'identifiant de connexion, clé du profil',
              adresse VARCHAR(255) NULL COMMENT 'adresse mail saisie par l’utilisateur',
              verifie_le DATETIME NULL COMMENT 'null = adresse non vérifiée',
              verif_empreinte CHAR(64) NULL COMMENT 'sha256 du jeton du lien de vérification ; jamais en clair',
              verif_expire_le DATETIME NULL,
              verif_utilise_le DATETIME NULL COMMENT 'usage unique : renseigné à la vérification',
              appairage_application VARCHAR(100) NULL,
              appairage_code CHAR(64) NULL COMMENT 'sha256 du code d’échange 256 bits ; jamais en clair',
              appairage_verification VARCHAR(8) NULL COMMENT 'code court affiché à l’écran et comparé à l’échange',
              appairage_adresse VARCHAR(255) NULL COMMENT 'adresse VÉRIFIÉE, destinataire du courriel d’auto-configuration',
              appairage_poste VARCHAR(255) NULL COMMENT 'renseigné par CMM au moment de l’échange',
              appairage_cree_le DATETIME NULL,
              appairage_expire_le DATETIME NULL COMMENT 'création + 24 h',
              appairage_utilise_le DATETIME NULL COMMENT 'usage unique : renseigné à l’échange',
              appairage_jeton_id INT UNSIGNED NULL COMMENT 'jeton remis par l’échange (trace)',
              maj_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_jonctions_profil_identifiant (identifiant),
              UNIQUE KEY uq_jonctions_profil_appairage_code (appairage_code),
              KEY k_jonctions_profil_compte (compte_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    /** Ajoute une colonne si elle manque (table créée par une version antérieure). Idempotent. */
    private static function assurerColonne(PDO $pdo, string $colonne, string $definition): void
    {
        $presente = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = 'jonctions_jetons' AND COLUMN_NAME = '" . $colonne . "'")->fetchColumn();
        if ($presente === 0) {
            $pdo->exec("ALTER TABLE jonctions_jetons ADD COLUMN $colonne $definition");
        }
    }

    /** Ajoute un index s'il manque. Idempotent. */
    private static function assurerIndex(PDO $pdo, string $index, string $colonne): void
    {
        $present = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = 'jonctions_jetons' AND INDEX_NAME = '" . $index . "'")->fetchColumn();
        if ($present === 0) {
            $pdo->exec("ALTER TABLE jonctions_jetons ADD KEY $index ($colonne)");
        }
    }
}

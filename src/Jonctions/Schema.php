<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use PDO;

/**
 * UNE seule table pour tout le secteur Jonctions (V2-08d) : les secrets à usage limité de l'API
 * d'entrée et de l'appairage partagent `jonctions_jetons`, discriminés par leur `nature`.
 *
 *   - `application`          : jeton d'une application (portée `depot`), empreinte sha256 ;
 *   - `appareil`             : jeton d'un appareil appairé, lié à un COMPTE et à un poste ;
 *   - `verification_adresse` : adresse saisie du profil, vérifiée par un lien à usage unique ;
 *   - `appairage_en_attente` : code d'échange 24 h remis à CMM (empreinte + code court de comparaison).
 *
 * Le plafond de l'oracle V2-01 tient en 20 tables : Jonctions en tient UNE. Toujours sha256, jamais un
 * secret en clair ; aucune ligne n'est supprimée (révocation, usage et remplacement sont des traces).
 * Idempotent : rejouable sur une base déjà à ce schéma.
 */
final class Schema
{
    public const NATURE_APPLICATION = 'application';
    public const NATURE_APPAREIL = 'appareil';
    public const NATURE_PROFIL = 'verification_adresse';
    public const NATURE_APPAIRAGE = 'appairage_en_attente';

    public static function installer(PDO $pdo): void
    {
        // Table unique. Chaque `nature` remplit le sous-ensemble de colonnes qui la concerne : les
        // empreintes de jeton (`empreinte`), de lien de vérification (`verif_empreinte`) et de code
        // d'échange (`appairage_code`) vivent dans des colonnes distinctes, chacune unique. Les natures
        // autres que jeton laissent `empreinte` NULL (MySQL tolère plusieurs NULL dans un index unique).
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS jonctions_jetons (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              nature VARCHAR(24) NOT NULL COMMENT 'application | appareil | verification_adresse | appairage_en_attente',
              application VARCHAR(100) NULL COMMENT 'application ou appareil relié ; null pour un profil ou un appairage',
              empreinte CHAR(64) NULL COMMENT 'sha256 du jeton ; jamais le jeton en clair',
              portee VARCHAR(20) NOT NULL DEFAULT 'depot' COMMENT 'depot | *',
              compte_id INT UNSIGNED NULL COMMENT 'compte du secteur Droits ; null pour un jeton d’application',
              compte_identifiant VARCHAR(255) NULL COMMENT 'identifiant du compte (compte synthétique de test inclus)',
              appareil VARCHAR(255) NULL COMMENT 'poste appairé ; null pour un jeton d’application',
              identifiant VARCHAR(255) NULL COMMENT 'identifiant de connexion du profil ; clé du profil',
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
              appairage_utilise_le DATETIME NULL COMMENT 'usage unique ou remplacement : trace, jamais une suppression',
              appairage_jeton_id INT UNSIGNED NULL COMMENT 'jeton remis par l’échange (trace)',
              cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              derniere_utilisation DATETIME NULL,
              revoque_le DATETIME NULL COMMENT 'révocation = trace, jamais une suppression',
              maj_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_jonctions_jeton_empreinte (empreinte),
              UNIQUE KEY uq_jonctions_profil_identifiant (identifiant),
              UNIQUE KEY uq_jonctions_profil_appairage_code (appairage_code),
              UNIQUE KEY uq_jonctions_profil_verif (verif_empreinte),
              KEY k_jonctions_jeton_application (application),
              KEY k_jonctions_jeton_compte (compte_id),
              KEY k_jonctions_jeton_nature (nature)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}

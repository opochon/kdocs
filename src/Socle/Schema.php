<?php

declare(strict_types=1);

namespace Ged\Socle;

use PDO;

/** Tables du secteur Socle. Idempotent : rejouable sur une base existante. */
final class Schema
{
    public static function installer(PDO $pdo): void
    {
        // Le document : une ligne = un fichier du fonds. La base n'est qu'un index de ce que
        // le dossier montre ; tout ce qui doit survivre à une reconstruction vit dans le `.meta.json`.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS documents (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              chemin_relatif VARCHAR(1024) NOT NULL COMMENT 'chemin sous la racine documentaire, séparateurs /',
              nom VARCHAR(255) NOT NULL,
              titre VARCHAR(512) NOT NULL,
              type VARCHAR(64) NULL,
              emetteur VARCHAR(255) NULL,
              date_document DATE NULL,
              montant DECIMAL(14,2) NULL,
              etiquettes JSON NULL COMMENT 'liste de chaînes',
              source VARCHAR(20) NULL COMMENT 'dossier | adresse | ecran | mobile',
              statut VARCHAR(20) NOT NULL DEFAULT 'a_controler',
              empreinte CHAR(64) NOT NULL COMMENT 'sha256 du contenu : sert au suivi des déplacements',
              taille INT UNSIGNED NOT NULL,
              mime VARCHAR(127) NOT NULL,
              meta JSON NOT NULL COMMENT 'contenu canonique du .meta.json (le dossier fait foi)',
              supprime_le DATETIME NULL COMMENT 'drapeau corbeille : le fichier vit alors sous .corbeille/',
              supprime_par VARCHAR(64) NULL,
              absent_depuis DATETIME NULL COMMENT 'fichier disparu du fonds : la ligne reste (zéro suppression)',
              cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              maj_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_chemin (chemin_relatif(700)),
              KEY k_empreinte (empreinte),
              KEY k_corbeille (supprime_le),
              KEY k_statut (statut, supprime_le)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        // Une table créée par une version antérieure n'a pas l'index du statut : l'écran « À traiter »
        // l'exige pour borner sa lecture à la file, jamais au fonds entier.
        self::assurerIndexStatut($pdo);

        // Les versions : une archive par contenu observé, dans `.versions/<fichier>/` à côté du document.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS document_versions (
              document_id INT UNSIGNED NOT NULL,
              numero SMALLINT UNSIGNED NOT NULL,
              nom_archive VARCHAR(255) NOT NULL COMMENT 'ex. v001_1a2b3c4d.pdf',
              empreinte CHAR(64) NOT NULL,
              taille INT UNSIGNED NOT NULL,
              cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (document_id, numero),
              KEY k_empreinte (empreinte)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        // Trace des déplacements : par le dépôt, par mise à jour, ou constatés par l'indexeur
        // quand le dossier a été manipulé hors application (la base suit le dossier).
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS document_deplacements (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              document_id INT UNSIGNED NOT NULL,
              chemin_avant VARCHAR(1024) NOT NULL,
              chemin_apres VARCHAR(1024) NOT NULL,
              cause VARCHAR(20) NOT NULL COMMENT 'depot | mise_a_jour | indexeur',
              auteur VARCHAR(64) NOT NULL DEFAULT 'indexeur',
              date_deplacement DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY k_document (document_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        // Comptes : minimal, pour l'installateur (--admin). Le secteur Sécurité reprendra la gestion.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS comptes (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              identifiant VARCHAR(64) NOT NULL,
              empreinte VARCHAR(255) NOT NULL COMMENT 'password_hash',
              role VARCHAR(20) NOT NULL DEFAULT 'admin',
              cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uq_identifiant (identifiant)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        // État de l'installation (version du schéma, date, compte posé) : jamais une donnée utilisateur.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS installation (
              cle VARCHAR(64) PRIMARY KEY,
              valeur VARCHAR(255) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    /**
     * Pose l'index du statut s'il manque (table créée avant lui) : sans cet index, une requête
     * `WHERE statut = ?` parcourt tout le fonds. `(statut, supprime_le)` couvre aussi le comptage du
     * bilan, sans lire une seule ligne de document. Idempotent, rejouable (y compris pour corriger un
     * ancien index à une seule colonne).
     */
    public static function assurerIndexStatut(PDO $pdo): void
    {
        $colonnes = $pdo->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)"
            . " FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = 'documents' AND INDEX_NAME = 'k_statut'")->fetchColumn();
        if (is_string($colonnes) && $colonnes !== '') {
            if (strcasecmp($colonnes, 'statut,supprime_le') === 0) {
                return;
            }
            $pdo->exec('ALTER TABLE documents DROP KEY k_statut');
        }
        $pdo->exec('ALTER TABLE documents ADD KEY k_statut (statut, supprime_le)');
    }

    /** Tables de l'index documentaire, dans l'ordre de destruction/reconstruction (jamais comptes ni installation). */
    public const TABLES_INDEX = ['documents', 'document_versions', 'document_deplacements'];
}

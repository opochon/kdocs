<?php

declare(strict_types=1);

namespace Ged\Ingestion;

use PDO;

/** Tables du secteur Ingestion. Idempotent : rejouable sur une base existante. */
final class Schema
{
    /** Colonnes du journal des courriels traites (modele Paperless `ProcessedMail`). */
    public const COLONNES_COURRIER = ['regle', 'dossier', 'uid', 'sujet', 'statut', 'erreur', 'traite_le'];

    /** L'installateur pose les tables de base PUIS les colonnes du journal courrier (idempotent). */
    public static function installer(PDO $pdo): void
    {
        self::assurerTables($pdo);
        self::assurerColonnesCourrier($pdo);
    }

    /** Étape v1, idempotente : les deux tables de base. `Migrations::monter` l'appelle pour la version 1. */
    public static function assurerTables(PDO $pdo): void
    {
        // Un « travail » = un fichier reçu (lot ou pièce). La même empreinte n'entre jamais deux fois.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS ingestion_travaux (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              empreinte CHAR(64) NOT NULL,
              source VARCHAR(20) NOT NULL COMMENT 'dossier | adresse | ecran | mobile',
              nom_origine VARCHAR(255) NOT NULL,
              chemin VARCHAR(1024) NOT NULL COMMENT 'copie de travail, hors du fonds',
              parent_id INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'lot dont la pièce est issue ; 0 = reçu directement (0 et non NULL : sinon UNIQUE laisse passer les doublons)',
              pages VARCHAR(255) NULL COMMENT 'pages du lot, ex. 25-28,30',
              etat VARCHAR(20) NOT NULL DEFAULT 'recu' COMMENT 'recu | decoupe | pret | range | erreur',
              essais TINYINT UNSIGNED NOT NULL DEFAULT 0,
              erreur VARCHAR(1000) NULL,
              document_id INT UNSIGNED NULL COMMENT 'document du fonds une fois rangé',
              analyse JSON NULL COMMENT 'ce que le moteur a lu : texte, facture, origine',
              reserve_jusqua DATETIME NULL COMMENT 'verrou court d un tic ; expiré = repris',
              cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              maj_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_empreinte_parent (empreinte, parent_id),
              KEY k_etat (etat, reserve_jusqua)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        // Messages déjà relevés sur l'adresse d'ingestion : un message n'est traité qu'une fois.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS ingestion_messages (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              boite VARCHAR(255) NOT NULL,
              message_id VARCHAR(512) NOT NULL,
              pieces TINYINT UNSIGNED NOT NULL DEFAULT 0,
              recu_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uq_message (boite(150), message_id(300))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    /**
     * Étape v2, idempotente : le journal des courriels traités (modele Paperless `ProcessedMail`) enrichit
     * `ingestion_messages` au lieu d'ouvrir une table de plus. Un message d'adresse n'a que `boite`/
     * `message_id`/`pieces` ; une ligne de courrier porte en plus la règle, le dossier, l'uid, l'objet, le
     * statut, l'erreur et la date de traitement. `Migrations::monter` l'appelle pour la version 2 ;
     * l'installateur la pose avec le reste du schéma.
     */
    public static function assurerColonnesCourrier(PDO $pdo): void
    {
        $colonnes = [
            'regle' => "ADD COLUMN regle VARCHAR(190) NULL COMMENT 'nom de la règle de courrier'",
            'dossier' => "ADD COLUMN dossier VARCHAR(190) NULL COMMENT 'dossier IMAP relevé'",
            'uid' => "ADD COLUMN uid VARCHAR(190) NULL COMMENT 'UID du message dans le dossier'",
            'sujet' => "ADD COLUMN sujet VARCHAR(255) NULL",
            'statut' => "ADD COLUMN statut VARCHAR(32) NULL COMMENT 'SUCCESS|PROCESSED_WO_CONSUMPTION|FAILED|purge'",
            'erreur' => 'ADD COLUMN erreur TEXT NULL',
            'traite_le' => 'ADD COLUMN traite_le DATETIME NULL',
        ];
        foreach ($colonnes as $colonne => $definition) {
            if (!Migrations::colonneExiste($pdo, 'ingestion_messages', $colonne)) {
                $pdo->exec('ALTER TABLE ingestion_messages ' . $definition);
            }
        }
    }
}

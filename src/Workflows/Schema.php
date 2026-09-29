<?php

declare(strict_types=1);

namespace Ged\Workflows;

use PDO;

/**
 * Tables du secteur Workflows : les définitions (règles et circuits dans la même table, un seul moteur),
 * les instances (un document dans un circuit, à un état) et le journal en ajout seul.
 *
 * `workflow_definitions` porte les deux niveaux (`genre` = regle | circuit) : une règle lit un document
 * et déclenche des actions, un circuit fait traverser des états. Le moteur les interprète ensemble.
 *
 * La table `documents` du Socle n'est jamais modifiée ; on ne référence `documents.id` que par valeur,
 * sans clé étrangère : la reconstruction de l'index ne casse rien ici.
 */
final class Schema
{
    public const TABLES = ['workflow_definitions', 'workflow_instances', 'workflow_journal'];

    public static function installer(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS workflow_definitions (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              genre VARCHAR(16) NOT NULL COMMENT 'regle | circuit',
              nom VARCHAR(190) NOT NULL,
              gabarit VARCHAR(64) NULL COMMENT 'nom du gabarit d''origine, pour l''installation idempotente',
              actif TINYINT(1) NOT NULL DEFAULT 0,
              declencheur VARCHAR(32) NULL COMMENT 'règle : document_depose | document_modifie | echeance',
              corps JSON NOT NULL COMMENT 'règle : {conditions,actions} ; circuit : {etats,transitions}',
              cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              maj_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY k_genre (genre),
              KEY k_gabarit (gabarit)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS workflow_instances (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              definition_id INT UNSIGNED NOT NULL COMMENT 'le circuit suivi',
              document_id INT UNSIGNED NOT NULL COMMENT 'id de la ligne documents du Socle (par valeur, sans FK)',
              etat VARCHAR(64) NOT NULL,
              assigne_a VARCHAR(64) NULL COMMENT 'rôle ou utilisateur chargé de l''étape',
              cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              maj_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_instance (definition_id, document_id),
              KEY k_document (document_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        // Journal en AJOUT SEUL : aucune mise à jour, aucune suppression. Le temps vient de l'horloge injectée.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS workflow_journal (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              document_id INT UNSIGNED NULL,
              definition_id INT UNSIGNED NULL,
              instance_id INT UNSIGNED NULL,
              genre VARCHAR(24) NOT NULL COMMENT 'demarrage|assignation|transition|refus|notification|jonction|regle|erreur|essai',
              de_etat VARCHAR(64) NULL,
              vers_etat VARCHAR(64) NULL,
              auteur VARCHAR(64) NOT NULL DEFAULT 'systeme',
              horodatage DATETIME NOT NULL,
              details JSON NULL,
              KEY k_document (document_id),
              KEY k_horodatage (horodatage)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}

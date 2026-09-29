<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use PDO;

/**
 * Tables du secteur Jonctions (API d'entrée). Idempotent : rejouable sur une base existante.
 * Aucune donnée utilisateur n'y est supprimée : un jeton révoqué reste comme trace.
 */
final class Schema
{
    public static function installer(PDO $pdo): void
    {
        // Un jeton par application, porté par l'API d'entrée (ClearMyMails, mobile…). Seule l'empreinte
        // sha256 est stockée : le jeton en clair n'existe qu'à sa création, côté appelant.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS jonctions_jetons (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              application VARCHAR(100) NOT NULL,
              empreinte CHAR(64) NOT NULL COMMENT 'sha256 du jeton ; jamais le jeton en clair',
              portee VARCHAR(20) NOT NULL DEFAULT 'depot' COMMENT 'depot | *',
              cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              derniere_utilisation DATETIME NULL,
              revoque_le DATETIME NULL COMMENT 'révocation = trace, jamais une suppression',
              UNIQUE KEY uq_jonctions_jeton_empreinte (empreinte),
              KEY k_jonctions_jeton_application (application)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}

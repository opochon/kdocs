<?php

declare(strict_types=1);

namespace Ged\Classement;

use PDO;

/**
 * Tables du secteur Classement. Idempotent : rejouable sur une base existante.
 *
 * Le journal d'apprentissage ne stocke QUE les choix humains : le tri automatique n'enseigne jamais.
 * L'habitude n'est pas figée en table, elle se recalcule à partir des derniers choix (source de vérité).
 */
final class Schema
{
    public static function installer(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS classement_choix (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              emetteur_cle VARCHAR(191) NOT NULL COMMENT 'nom d''émetteur normalisé (minuscules, sans accents)',
              champ VARCHAR(32) NOT NULL COMMENT 'type | etiquettes',
              valeur VARCHAR(512) NOT NULL,
              document_ref VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'pièce concernée : un même document ne pèse qu''une fois',
              source VARCHAR(16) NOT NULL DEFAULT 'humain' COMMENT 'humain seul enseigne ; auto n''est jamais écrit',
              cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              maj_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_choix_document (document_ref, champ, source),
              KEY k_habitude (emetteur_cle, champ, source, cree_le)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}

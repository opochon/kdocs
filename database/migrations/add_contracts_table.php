<?php
/**
 * Migration PHP : création de la table `contracts` (GAP-030 — module contrats + échéances).
 *
 * Idempotente : la table n'est créée que si elle n'existe pas.
 * Compatible MySQL et SQLite.
 *
 * NE PAS exécuter automatiquement — lancée manuellement via CLI de migration.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Core/Config.php';

use KDocs\Core\Database;

echo "Migration: Création de la table `contracts` (GAP-030)...\n\n";

try {
    $db = Database::getInstance();

    $db->exec("CREATE TABLE IF NOT EXISTS contracts (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        document_id   INT          NULL,
        title         VARCHAR(255) NOT NULL,
        counterparty  VARCHAR(255) NULL,
        start_date    DATE         NULL,
        due_date      DATE         NULL,
        notice_days   INT          NOT NULL DEFAULT 30,
        status        VARCHAR(50)  NOT NULL DEFAULT 'active',
        created_at    DATETIME     NOT NULL,
        updated_at    DATETIME     NOT NULL
    )");

    echo "   OK  Table contracts créée (ou déjà présente).\n";
    echo "\nMigration terminée.\n";
} catch (\Exception $e) {
    echo "ERREUR: " . $e->getMessage() . "\n";
    exit(1);
}

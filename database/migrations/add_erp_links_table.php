<?php
/**
 * Migration PHP : création de la table `erp_links` (plugin K-ERP Connect).
 *
 * Idempotente : CREATE TABLE IF NOT EXISTS — ne recréera pas la table si elle existe déjà.
 * Pattern : add_contracts_table.php.
 *
 * NE PAS exécuter automatiquement — lancée manuellement via CLI de migration.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Core/Config.php';

use KDocs\Core\Database;

echo "Migration: Création de la table `erp_links` (K-ERP Connect)...\n\n";

try {
    $db = Database::getInstance();

    $db->exec("CREATE TABLE IF NOT EXISTS erp_links (
        id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        document_id         INT          NOT NULL,
        connector           VARCHAR(50)  NOT NULL DEFAULT 'ktime'
                            COMMENT 'Identifiant du connecteur ERP (ktime, bexio…)',
        external_id         INT          NULL
                            COMMENT 'ID de la facture dans le système ERP',
        external_ref        VARCHAR(255) NULL
                            COMMENT 'Référence croisée (ged:doc:N)',
        status              VARCHAR(50)  NULL
                            COMMENT 'Statut ERP (draft, a_payer, payee…)',
        validation_status   VARCHAR(50)  NULL
                            COMMENT 'Statut validation ERP (pending, validated, rejected)',
        validated_by_name   VARCHAR(255) NULL
                            COMMENT 'Nom de la personne ayant validé dans K-Time',
        validated_at        DATETIME     NULL
                            COMMENT 'Date/heure de validation (bon pour accord)',
        payload_json        JSON         NULL
                            COMMENT 'Payload envoyé à K-Time lors de la création',
        created_at          DATETIME     NOT NULL,
        updated_at          DATETIME     NOT NULL,
        UNIQUE KEY uq_doc_connector (document_id, connector),
        KEY idx_erp_connector (connector),
        KEY idx_erp_external (connector, external_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    echo "   OK  Table erp_links créée (ou déjà présente).\n";
    echo "\nMigration terminée.\n";
} catch (\Exception $e) {
    echo "ERREUR: " . $e->getMessage() . "\n";
    exit(1);
}

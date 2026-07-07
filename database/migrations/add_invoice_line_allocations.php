<?php
/**
 * Migration PHP : table `invoice_line_allocations` (ventilation fractionnée K-ERP Connect)
 * + colonnes de blocage sur `erp_links` (miroir statut/cause).
 *
 * Contrat : docs/SPEC-ERPCONNECT-VENTILATION.md §5.2. Idempotente.
 * NE PAS exécuter automatiquement — lancée manuellement via CLI de migration.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Core/Config.php';

use KDocs\Core\Database;

echo "Migration: invoice_line_allocations + erp_links (blocage)...\n\n";

try {
    $db = Database::getInstance();

    $db->exec("CREATE TABLE IF NOT EXISTS invoice_line_allocations (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        line_item_id    INT          NOT NULL,
        document_id     INT          NOT NULL,
        quantity        DECIMAL(12,3) NOT NULL,
        allocation_type ENUM('stock','facture','fiche_travail','vente_comptant','recu_conteste','non_attribue') NOT NULL,
        erp_ref_type    VARCHAR(50)  NULL,
        erp_ref_id      VARCHAR(100) NULL,
        erp_ref_label   VARCHAR(255) NULL,
        status          ENUM('proposed','confirmed','rejected') NOT NULL DEFAULT 'proposed',
        confidence      DECIMAL(5,2) NULL,
        created_at      DATETIME     NOT NULL,
        updated_at      DATETIME     NOT NULL,
        KEY idx_ila_line (line_item_id),
        KEY idx_ila_doc (document_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    echo "   OK  Table invoice_line_allocations créée (ou déjà présente).\n";

    // ── Colonnes de blocage sur erp_links (ajout défensif, portable MySQL/MariaDB) ──
    $schema = (string) ($db->query('SELECT DATABASE()')->fetchColumn() ?: '');
    foreach (['block_kind' => "VARCHAR(30) NULL COMMENT 'note_credit|correction_facture|blocage_paiement'",
              'block_cause' => "VARCHAR(1000) NULL COMMENT 'Cause du blocage (miroir K-Time)'"] as $col => $ddl) {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$schema, 'erp_links', $col]);
        if ((int) $stmt->fetchColumn() === 0) {
            $db->exec("ALTER TABLE erp_links ADD COLUMN {$col} {$ddl}");
            echo "   OK  Colonne erp_links.{$col} ajoutée.\n";
        } else {
            echo "   ~   Colonne erp_links.{$col} déjà présente.\n";
        }
    }

    echo "\nMigration terminée.\n";
} catch (\Exception $e) {
    echo "ERREUR: " . $e->getMessage() . "\n";
    exit(1);
}

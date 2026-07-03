<?php
/**
 * Migration PHP : colonnes tenant_id sur users et documents (GAP-041 — multi-mandant).
 *
 * NULL = mandant global (comportement actuel inchangé, pas de régression).
 * Idempotente : chaque colonne n'est ajoutée que si elle manque (SHOW COLUMNS).
 * Ne PAS exécuter automatiquement — appeler manuellement :
 *   php database/migrations/add_tenant_columns.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Core/Config.php';

use KDocs\Core\Database;

echo "Migration: Ajout colonnes tenant_id sur users et documents (GAP-041)...\n\n";

try {
    $db = Database::getInstance();

    $columnExists = function (string $table, string $column) use ($db): bool {
        try {
            $stmt = $db->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $stmt->execute([$column]);
            return $stmt->fetch() !== false;
        } catch (\Exception $e) {
            return false;
        }
    };

    foreach (['users', 'documents'] as $table) {
        if (!$columnExists($table, 'tenant_id')) {
            try {
                $db->exec(
                    "ALTER TABLE `{$table}` ADD COLUMN `tenant_id` INT DEFAULT NULL
                     COMMENT 'Mandant (NULL = global/unique — GAP-041)'"
                );
                echo "   OK Colonne tenant_id ajoutee sur `{$table}`\n";
            } catch (\Exception $e) {
                echo "   AVERTISSEMENT erreur pour `{$table}`.tenant_id : " . $e->getMessage() . "\n";
            }
        } else {
            echo "   INFO Colonne tenant_id existe deja sur `{$table}`\n";
        }
    }

    echo "\nOK Migration terminee avec succes!\n";
} catch (\Exception $e) {
    echo "ERREUR de migration: " . $e->getMessage() . "\n";
    exit(1);
}

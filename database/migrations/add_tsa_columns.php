<?php
/**
 * Migration PHP : colonnes d'horodatage qualifié TSA sur `documents` (GAP-023).
 *
 * `tsa_token`          — réponse TimeStampResp RFC 3161 encodée en base64 (TEXT).
 * `tsa_timestamped_at` — date/heure UTC du scellement (DATETIME).
 *
 * Idempotent : chaque colonne n'est ajoutée que si elle est absente.
 * NE PAS exécuter ce script sur un environnement de test PHPUnit (SQLite in-memory).
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Core/Config.php';

use KDocs\Core\Database;

echo "Migration: Ajout des colonnes TSA (horodatage qualifié RFC 3161) à `documents`...\n\n";

try {
    $db = Database::getInstance();

    $columnExists = static function (string $table, string $column) use ($db): bool {
        try {
            $stmt = $db->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $stmt->execute([$column]);
            return $stmt->fetch() !== false;
        } catch (\Exception) {
            return false;
        }
    };

    $columns = [
        'tsa_token'          => "TEXT NULL COMMENT 'Token TSA RFC 3161 (base64 de la TimeStampResp)'",
        'tsa_timestamped_at' => "DATETIME NULL COMMENT 'Date UTC du scellement TSA (GAP-023)'",
    ];

    foreach ($columns as $column => $definition) {
        if (!$columnExists('documents', $column)) {
            try {
                $db->exec("ALTER TABLE documents ADD COLUMN `{$column}` {$definition}");
                echo "   + Colonne {$column} ajoutée\n";
            } catch (\Exception $e) {
                echo "   ! Erreur pour {$column} : " . $e->getMessage() . "\n";
            }
        } else {
            echo "   = Colonne {$column} existe déjà\n";
        }
    }

    echo "\nMigration TSA terminée.\n";
} catch (\Exception $e) {
    echo "Erreur de migration : " . $e->getMessage() . "\n";
    exit(1);
}

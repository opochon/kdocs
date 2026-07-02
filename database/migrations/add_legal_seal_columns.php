<?php
/**
 * Migration PHP : colonnes de scellement légal WORM sur `documents` (P2 conformité CH).
 *
 * GAP-020/024 : un document scellé (legal_sealed=1) devient non modifiable —
 * toute écriture API renvoie 403 (LegalSealedException côté service).
 * retention_until = échéance de rétention (10 ans compta, CO 958f / GeBüV).
 *
 * Idempotent : chaque colonne n'est ajoutée que si elle manque.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Core/Config.php';

use KDocs\Core\Database;

echo "Migration: Ajout des colonnes de scellement légal à `documents`...\n\n";

try {
    $db = Database::getInstance();

    $columnExists = function ($table, $column) use ($db) {
        try {
            $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            return $stmt->fetch() !== false;
        } catch (\Exception $e) {
            return false;
        }
    };

    $columns = [
        'legal_sealed'     => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Document scellé WORM (non modifiable)'",
        'legal_sealed_at'  => "DATETIME DEFAULT NULL COMMENT 'Date du scellement'",
        'legal_sealed_by'  => "INT DEFAULT NULL COMMENT 'Utilisateur ayant scellé'",
        'retention_until'  => "DATE DEFAULT NULL COMMENT 'Échéance de rétention légale'",
    ];

    foreach ($columns as $column => $definition) {
        if (!$columnExists('documents', $column)) {
            try {
                $db->exec("ALTER TABLE documents ADD COLUMN `$column` $definition");
                echo "   ✅ Colonne $column ajoutée\n";
            } catch (\Exception $e) {
                echo "   ⚠️  Erreur pour $column: " . $e->getMessage() . "\n";
            }
        } else {
            echo "   ℹ️  Colonne $column existe déjà\n";
        }
    }

    echo "\n✅ Migration terminée avec succès!\n";
} catch (\Exception $e) {
    echo "❌ Erreur de migration: " . $e->getMessage() . "\n";
    exit(1);
}

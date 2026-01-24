<?php
/**
 * Migration 012: Colonnes pour la séparation de PDF multi-pages
 */

require_once __DIR__ . '/../app/Core/Database.php';

use KDocs\Core\Database;

function columnExists($db, $table, $column): bool
{
    try {
        $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $stmt->rowCount() > 0;
    } catch (\Exception $e) {
        return false;
    }
}

try {
    $db = Database::getInstance();
    
    echo "Migration 012: Ajout colonnes pour séparation PDF...\n";
    
    // Vérifier et ajouter parent_document_id
    if (!columnExists($db, 'documents', 'parent_document_id')) {
        $db->exec("ALTER TABLE `documents` ADD COLUMN `parent_document_id` INT UNSIGNED NULL COMMENT 'ID du document parent si ce document provient d\'un split'");
        echo "  ✓ Colonne parent_document_id ajoutée\n";
    } else {
        echo "  ⚠ Colonne parent_document_id existe déjà\n";
    }
    
    // Vérifier et ajouter split_pages
    if (!columnExists($db, 'documents', 'split_pages')) {
        $db->exec("ALTER TABLE `documents` ADD COLUMN `split_pages` JSON NULL COMMENT 'Numéros de pages (0-indexed) si ce document provient d\'un split'");
        echo "  ✓ Colonne split_pages ajoutée\n";
    } else {
        echo "  ⚠ Colonne split_pages existe déjà\n";
    }
    
    // Vérifier et ajouter split_into_count
    if (!columnExists($db, 'documents', 'split_into_count')) {
        $db->exec("ALTER TABLE `documents` ADD COLUMN `split_into_count` INT UNSIGNED NULL DEFAULT 0 COMMENT 'Nombre de documents créés par séparation'");
        echo "  ✓ Colonne split_into_count ajoutée\n";
    } else {
        echo "  ⚠ Colonne split_into_count existe déjà\n";
    }
    
    // Créer l'index
    try {
        $db->exec("CREATE INDEX IF NOT EXISTS `idx_parent_document` ON `documents` (`parent_document_id`)");
        echo "  ✓ Index idx_parent_document créé\n";
    } catch (\Exception $e) {
        // Index peut déjà exister
        echo "  ⚠ Index idx_parent_document: " . $e->getMessage() . "\n";
    }
    
    echo "Migration 012 terminée.\n";
    
} catch (\Exception $e) {
    echo "ERREUR Migration 012: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}

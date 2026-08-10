<?php
/**
 * Migration PHP : colonnes de filiation de découpage PDF sur `documents`.
 *
 * Le classificateur écrase `classification_suggestions` à chaque classement (méthode
 * nominale) — cette colonne JSON ne peut donc pas porter la filiation d'un document
 * issu d'un split (parent, pages d'origine, méthode de découpage IA/règles) sans que
 * l'information disparaisse au premier classement automatique de l'enfant.
 *
 * Ces colonnes lui donnent un emplacement dédié, stable, jamais écrasé par la
 * classification.
 *
 * Additive uniquement : ADD COLUMN, nullable, aucune colonne existante modifiée ou
 * supprimée, aucune donnée existante touchée. Idempotent (skip si colonne déjà là).
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Core/Config.php';

use KDocs\Core\Database;

echo "Migration: Ajout des colonnes de filiation de split à `documents`...\n\n";

try {
    $db = Database::getInstance();

    $columnExists = function ($table, $column) use ($db) {
        try {
            // SHOW COLUMNS ... LIKE ? refuse le bind côté ce driver ; on liste toutes les
            // colonnes et on compare en PHP (pas de piège avec les underscores en LIKE).
            $stmt = $db->query("SHOW COLUMNS FROM `$table`");
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if (($row['Field'] ?? null) === $column) {
                    return true;
                }
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    };

    $columns = [
        'parent_document_id' => "INT DEFAULT NULL COMMENT 'Document PDF d\\'origine si ce document provient d\\'un split (PDFSplitterService)'",
        'split_pages'         => "VARCHAR(255) DEFAULT NULL COMMENT 'Pages d\\'origine dans le PDF parent (JSON, ex: [0,1])'",
        'split_method'        => "VARCHAR(20) DEFAULT NULL COMMENT 'Méthode de découpage : ai | rules'",
    ];

    foreach ($columns as $column => $definition) {
        if (!$columnExists('documents', $column)) {
            try {
                $db->exec("ALTER TABLE documents ADD COLUMN `$column` $definition");
                echo "   OK colonne $column ajoutée\n";
            } catch (\Exception $e) {
                echo "   ERREUR pour $column: " . $e->getMessage() . "\n";
            }
        } else {
            echo "   INFO colonne $column existe déjà\n";
        }
    }

    try {
        $indexRows = $db->query("SHOW INDEX FROM documents WHERE Key_name = 'idx_documents_parent_document_id'")->fetchAll();
        if (empty($indexRows)) {
            $db->exec("CREATE INDEX idx_documents_parent_document_id ON documents(parent_document_id)");
            echo "   OK index idx_documents_parent_document_id créé\n";
        } else {
            echo "   INFO index déjà présent\n";
        }
    } catch (\Exception $e) {
        echo "   AVERTISSEMENT index: " . $e->getMessage() . "\n";
    }

    echo "\nMigration terminée avec succès.\n";
} catch (\Exception $e) {
    echo "Erreur de migration: " . $e->getMessage() . "\n";
    exit(1);
}

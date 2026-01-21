<?php
/**
 * Script de migration pour architecture Filesystem-First
 */

require __DIR__ . '/../vendor/autoload.php';

use KDocs\Core\Database;

$db = Database::getInstance();

echo "Migration Filesystem-First...\n";

// Créer table document_folders
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS document_folders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            path VARCHAR(500) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            parent_id INT NULL,
            depth INT DEFAULT 0,
            file_count INT DEFAULT 0,
            last_scanned DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (parent_id) REFERENCES document_folders(id) ON DELETE CASCADE
        )
    ");
    echo "✓ Table document_folders créée\n";
} catch (\Exception $e) {
    echo "⚠ document_folders: " . $e->getMessage() . "\n";
}

// Ajouter index
try {
    $db->exec("CREATE INDEX IF NOT EXISTS idx_folders_path ON document_folders(path)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_folders_parent ON document_folders(parent_id)");
    echo "✓ Index créés\n";
} catch (\Exception $e) {
    echo "⚠ Index: " . $e->getMessage() . "\n";
}

// Ajouter colonnes à documents
$columns = [
    'folder_id' => 'INT NULL',
    'relative_path' => 'VARCHAR(500)',
    'thumbnail_path' => 'VARCHAR(500)',
    'is_indexed' => 'BOOLEAN DEFAULT FALSE'
];

foreach ($columns as $col => $def) {
    try {
        $db->exec("ALTER TABLE documents ADD COLUMN $col $def");
        echo "✓ Colonne $col ajoutée\n";
    } catch (\Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "⚠ Colonne $col existe déjà\n";
        } else {
            echo "✗ Erreur $col: " . $e->getMessage() . "\n";
        }
    }
}

// Ajouter clé étrangère
try {
    $db->exec("ALTER TABLE documents ADD CONSTRAINT documents_ibfk_folder FOREIGN KEY (folder_id) REFERENCES document_folders(id) ON DELETE SET NULL");
    echo "✓ Clé étrangère ajoutée\n";
} catch (\Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate key') !== false) {
        echo "⚠ Clé étrangère existe déjà\n";
    } else {
        echo "✗ Erreur FK: " . $e->getMessage() . "\n";
    }
}

echo "\nMigration terminée !\n";

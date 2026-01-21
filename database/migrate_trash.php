<?php
/**
 * Migration pour système de trash et détection de modifications
 */

require __DIR__ . '/../vendor/autoload.php';
use KDocs\Core\Database;

$db = Database::getInstance();

echo "Migration Trash et détection modifications...\n";

// Ajouter colonnes
$columns = [
    'deleted_at' => 'DATETIME NULL',
    'deleted_by' => 'INT NULL',
    'file_modified_at' => 'INT NULL COMMENT "Timestamp de dernière modification du fichier"'
];

foreach ($columns as $colName => $colType) {
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = '$colName'");
        $exists = (int)$stmt->fetchColumn();
        if (!$exists) {
            $db->exec("ALTER TABLE documents ADD COLUMN $colName $colType");
            echo "✓ Colonne documents.$colName ajoutée\n";
        } else {
            echo "ℹ Colonne documents.$colName existe déjà\n";
        }
    } catch (\Exception $e) {
        echo "⚠ Erreur pour colonne $colName: " . $e->getMessage() . "\n";
    }
}

// Créer index
try {
    $db->exec("CREATE INDEX IF NOT EXISTS idx_documents_deleted ON documents(deleted_at)");
    echo "✓ Index deleted créé\n";
} catch (\Exception $e) {
    echo "⚠ Index deleted: " . $e->getMessage() . "\n";
}

try {
    $db->exec("CREATE INDEX IF NOT EXISTS idx_documents_file_modified ON documents(file_modified_at)");
    echo "✓ Index file_modified créé\n";
} catch (\Exception $e) {
    echo "⚠ Index file_modified: " . $e->getMessage() . "\n";
}

echo "\n✅ Migration terminée!\n";

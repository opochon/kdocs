<?php
require __DIR__ . '/../vendor/autoload.php';
use KDocs\Core\Database;

$db = Database::getInstance();

echo "Migration Paperless-ngx...\n";

$sql = file_get_contents(__DIR__ . '/migration_paperless.sql');

// Ajouter les colonnes à documents si elles n'existent pas
$columns = [
    'ocr_text' => 'LONGTEXT',
    'document_date' => 'DATE',
    'amount' => 'DECIMAL(10,2)',
    'indexed_at' => 'DATETIME'
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

// Exécuter le SQL du fichier
$statements = preg_split('/;\s*(?=\n|$)/', $sql);

foreach ($statements as $statement) {
    $statement = trim($statement);
    if (empty($statement) || preg_match('/^--/', $statement)) {
        continue;
    }
    
    try {
        $db->exec($statement);
        $preview = substr($statement, 0, 60);
        if (strlen($statement) > 60) $preview .= '...';
        echo "✓ " . $preview . "\n";
    } catch (\Exception $e) {
        $msg = $e->getMessage();
        // Ignorer les erreurs de colonnes/tables déjà existantes
        if (strpos($msg, 'already exists') === false && 
            strpos($msg, 'Duplicate') === false && 
            strpos($msg, 'Duplicate column') === false &&
            strpos($msg, 'Duplicate key') === false &&
            strpos($msg, 'Duplicate entry') === false) {
            echo "⚠ " . $msg . "\n";
        }
    }
}

echo "\n✅ Migration terminée!\n";
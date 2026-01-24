<?php
/**
 * Migration 014: Ajout colonne ai_ignored_tags
 */

require_once __DIR__ . '/../app/autoload.php';

use KDocs\Core\Database;

$db = Database::getInstance();

$sqlFile = __DIR__ . '/migrations/014_add_ai_ignored_tags.sql';
$sql = file_get_contents($sqlFile);

try {
    // Exécuter le SQL ligne par ligne pour gérer les erreurs "IF NOT EXISTS"
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            try {
                $db->exec($statement);
                echo "✓ Exécuté: " . substr($statement, 0, 50) . "...\n";
            } catch (\PDOException $e) {
                // Ignorer les erreurs "column already exists"
                if (strpos($e->getMessage(), 'Duplicate column') === false && 
                    strpos($e->getMessage(), 'already exists') === false) {
                    throw $e;
                }
                echo "⚠ Colonne déjà existante, ignoré\n";
            }
        }
    }
    
    echo "\n✅ Migration 014 terminée avec succès\n";
} catch (\Exception $e) {
    echo "❌ Erreur migration 014: " . $e->getMessage() . "\n";
    exit(1);
}

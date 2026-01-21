<?php
/**
 * Script de migration pour les fonctionnalités Priorité 2 et 3
 */

require __DIR__ . '/../vendor/autoload.php';

use KDocs\Core\Database;

$db = Database::getInstance();
$pdo = $db;

echo "Migration Priorité 2 et 3...\n";

// Migrations à exécuter
$migrations = [
    __DIR__ . '/migration_saved_searches.sql',
    __DIR__ . '/migration_document_sharing.sql',
    __DIR__ . '/migration_document_history.sql',
];

foreach ($migrations as $migrationFile) {
    if (!file_exists($migrationFile)) {
        echo "⚠ Fichier de migration non trouvé : $migrationFile\n";
        continue;
    }
    
    echo "\n📄 Exécution de " . basename($migrationFile) . "...\n";
    
    $sql = file_get_contents($migrationFile);
    $queries = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($queries as $query) {
        if (empty($query)) continue;
        
        try {
            // Gérer CREATE TABLE IF NOT EXISTS
            if (preg_match('/CREATE TABLE IF NOT EXISTS `?(\w+)`?/i', $query, $matches)) {
                $tableName = $matches[1];
                $checkTable = $pdo->query("SHOW TABLES LIKE '{$tableName}'")->fetch();
                if ($checkTable) {
                    echo "ℹ Table {$tableName} existe déjà\n";
                    continue;
                }
            }
            
            $pdo->exec($query);
            echo "✓ " . substr($query, 0, 60) . "...\n";
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'already exists') === false && 
                strpos($e->getMessage(), 'Duplicate') === false) {
                echo "✗ Erreur : " . $e->getMessage() . "\n";
            }
        }
    }
}

echo "\n✅ Migration Priorité 2 et 3 terminée!\n";

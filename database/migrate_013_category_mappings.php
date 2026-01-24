<?php
/**
 * Migration 013: Système de mapping des catégories IA
 * Exécuter avec: php database/migrate_013_category_mappings.php
 */

require_once __DIR__ . '/../app/autoload.php';

use KDocs\Core\Database;

$db = Database::getInstance();

echo "🚀 Migration 013: Système de mapping des catégories IA\n";
echo "========================================================\n\n";

try {
    // Lire le fichier SQL
    $sql = file_get_contents(__DIR__ . '/migrations/013_category_mappings.sql');
    
    // Séparer les requêtes en supprimant les commentaires
    $lines = explode("\n", $sql);
    $cleanSql = '';
    foreach ($lines as $line) {
        $line = trim($line);
        // Ignorer les lignes de commentaire
        if (empty($line) || strpos($line, '--') === 0) {
            continue;
        }
        $cleanSql .= $line . "\n";
    }
    
    // Séparer les requêtes
    $queries = array_filter(array_map('trim', explode(';', $cleanSql)));
    
    foreach ($queries as $index => $query) {
        if (empty($query)) {
            continue;
        }
        
        $query = trim($query);
        if (empty($query)) {
            continue;
        }
        
        try {
            echo "Exécution de la requête " . ($index + 1) . "...\n";
            $db->exec($query);
            if (stripos($query, 'CREATE TABLE') !== false) {
                echo "✅ Table category_mappings créée\n";
            } elseif (stripos($query, 'ADD COLUMN') !== false || stripos($query, 'ALTER TABLE') !== false) {
                echo "✅ Colonne ai_additional_categories ajoutée à documents\n";
            } else {
                echo "✅ Requête exécutée\n";
            }
        } catch (PDOException $e) {
            // Ignorer les erreurs "table already exists" ou "column already exists"
            if (stripos($e->getMessage(), 'already exists') !== false || 
                stripos($e->getMessage(), 'Duplicate') !== false ||
                stripos($e->getMessage(), 'Duplicate column') !== false) {
                echo "⚠️  " . $e->getMessage() . " (ignoré)\n";
            } else {
                echo "❌ Erreur: " . $e->getMessage() . "\n";
                echo "Requête: " . substr($query, 0, 100) . "...\n";
                throw $e;
            }
        }
    }
    
    echo "\n✅ Migration terminée avec succès !\n";
    
} catch (Exception $e) {
    echo "\n❌ Erreur lors de la migration : " . $e->getMessage() . "\n";
    exit(1);
}

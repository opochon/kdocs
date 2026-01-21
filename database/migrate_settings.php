<?php
/**
 * Migration pour la table settings
 */

require __DIR__ . '/../app/autoload.php';

use KDocs\Core\Database;

try {
    $db = Database::getInstance();
    
    echo "📋 Création de la table settings...\n";
    
    $sql = file_get_contents(__DIR__ . '/migration_settings.sql');
    
    // Séparer les requêtes
    $queries = array_filter(
        array_map('trim', explode(';', $sql)),
        function($q) {
            return !empty($q) && !preg_match('/^--/', $q);
        }
    );
    
    foreach ($queries as $query) {
        if (empty(trim($query))) continue;
        try {
            $db->exec($query);
            echo "✓ Requête exécutée\n";
        } catch (PDOException $e) {
            // Ignorer les erreurs de duplication
            if (strpos($e->getMessage(), 'Duplicate') === false && 
                strpos($e->getMessage(), 'already exists') === false) {
                echo "⚠ Erreur: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "✅ Migration settings terminée avec succès !\n";
    
} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
    exit(1);
}

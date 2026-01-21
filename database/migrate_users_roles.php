<?php
/**
 * Migration Multi-utilisateurs avancé pour K-Docs
 * Exécuter avec: php database/migrate_users_roles.php
 */

require_once __DIR__ . '/../app/autoload.php';

use KDocs\Core\Database;

$db = Database::getInstance();

echo "🚀 Migration Multi-utilisateurs avancé...\n\n";

try {
    // Lire le fichier SQL
    $sql = file_get_contents(__DIR__ . '/migration_users_roles.sql');
    
    // Séparer les requêtes
    $queries = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($queries as $query) {
        if (empty($query) || strpos($query, '--') === 0) {
            continue;
        }
        
        try {
            $db->exec($query);
            if (strpos($query, 'ALTER TABLE users') !== false) {
                echo "✅ Colonnes ajoutées à la table users\n";
            } elseif (strpos($query, 'CREATE TABLE') !== false) {
                $tableName = preg_match('/CREATE TABLE.*?(\w+)/i', $query, $matches) ? $matches[1] : 'table';
                echo "✅ Table $tableName créée\n";
            }
        } catch (PDOException $e) {
            // Ignorer les erreurs "column already exists" ou "table already exists"
            if (strpos($e->getMessage(), 'already exists') !== false || 
                strpos($e->getMessage(), 'Duplicate') !== false ||
                strpos($e->getMessage(), 'Duplicate column') !== false) {
                echo "⚠️  Élément existe déjà (ignoré)\n";
            } else {
                echo "❌ Erreur: " . $e->getMessage() . "\n";
                echo "   Requête: " . substr($query, 0, 100) . "...\n";
            }
        }
    }
    
    // Mettre à jour les utilisateurs existants pour définir leur rôle
    try {
        $db->exec("UPDATE users SET role = 'admin' WHERE is_admin = 1 AND (role IS NULL OR role = '')");
        $db->exec("UPDATE users SET role = 'user' WHERE (is_admin = 0 OR is_admin IS NULL) AND (role IS NULL OR role = '')");
        echo "✅ Rôles mis à jour pour les utilisateurs existants\n";
    } catch (PDOException $e) {
        echo "⚠️  Erreur lors de la mise à jour des rôles: " . $e->getMessage() . "\n";
    }
    
    echo "\n✅ Migration Multi-utilisateurs avancé terminée avec succès !\n";
    
} catch (Exception $e) {
    echo "\n❌ Erreur lors de la migration : " . $e->getMessage() . "\n";
    exit(1);
}

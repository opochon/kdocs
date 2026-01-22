<?php
/**
 * Migration Workflow Designer
 * Remplace les anciennes tables workflow Paperless-ngx par le nouveau système de graphe
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Config.php';

use KDocs\Core\Database;
use KDocs\Core\Config;

echo "=== Migration Workflow Designer ===\n\n";

try {
    $db = Database::getInstance();
    
    // Lire le fichier SQL
    $sqlFile = __DIR__ . '/migration_workflow_designer.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Fichier SQL non trouvé: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Désactiver temporairement les vérifications FK pour les suppressions
    echo "Désactivation temporaire des vérifications de clés étrangères...\n";
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // Diviser en requêtes individuelles (séparées par ;)
    $queries = array_filter(
        array_map('trim', explode(';', $sql)),
        function($query) {
            return !empty($query) && !preg_match('/^\s*--/', $query) && 
                   !preg_match('/^SET\s+FOREIGN_KEY_CHECKS/i', $query);
        }
    );
    
    // Réactiver les vérifications FK après les suppressions
    $reEnableFK = false;
    
    echo "Exécution de " . count($queries) . " requêtes SQL...\n\n";
    
    foreach ($queries as $index => $query) {
        // Réactiver FK après les DROP TABLE
        if (!$reEnableFK && preg_match('/CREATE TABLE/i', $query)) {
            echo "Réactivation des vérifications de clés étrangères...\n";
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");
            $reEnableFK = true;
        }
        try {
            // Ignorer les commentaires multi-lignes
            $query = preg_replace('/\/\*.*?\*\//s', '', $query);
            $query = trim($query);
            
            if (empty($query)) {
                continue;
            }
            
            // Extraire le nom de la table pour affichage
            if (preg_match('/CREATE TABLE\s+`?(\w+)`?/i', $query, $matches)) {
                $tableName = $matches[1];
                echo "  [$index] Création table: $tableName...\n";
            } elseif (preg_match('/DROP TABLE\s+IF EXISTS\s+`?(\w+)`?/i', $query, $matches)) {
                $tableName = $matches[1];
                echo "  [$index] Suppression table: $tableName...\n";
            } else {
                echo "  [$index] Exécution requête...\n";
            }
            
            $result = $db->exec($query);
            if ($result === false) {
                $errorInfo = $db->errorInfo();
                echo "    ⚠️  Erreur SQL: " . ($errorInfo[2] ?? 'Unknown error') . "\n";
            }
            
        } catch (PDOException $e) {
            // Ignorer les erreurs "table already exists" pour les CREATE TABLE
            if (strpos($e->getMessage(), 'already exists') !== false) {
                echo "    ⚠️  Table existe déjà, ignoré\n";
                continue;
            }
            // Ignorer les erreurs "table doesn't exist" pour les DROP TABLE
            if (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), "does not exist") !== false) {
                echo "    ⚠️  Table n'existe pas, ignoré\n";
                continue;
            }
            echo "    ❌ Erreur: " . $e->getMessage() . "\n";
            echo "    Requête: " . substr($query, 0, 200) . "...\n";
            throw $e;
        }
    }
    
    // S'assurer que FK est réactivé
    if (!$reEnableFK) {
        echo "Réactivation des vérifications de clés étrangères...\n";
        $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    }
    
    echo "\n✅ Migration terminée avec succès!\n";
    
} catch (Exception $e) {
    echo "❌ Erreur de migration: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

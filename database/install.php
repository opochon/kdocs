<?php
/**
 * Script d'installation de la base de données K-Docs
 * Usage: php database/install.php
 */

$config = require __DIR__ . '/../config/config.php';
$dbConfig = $config['database'];

try {
    // Connexion sans spécifier la base de données d'abord
    $dsn = sprintf(
        'mysql:host=%s;port=%d;charset=%s',
        $dbConfig['host'],
        $dbConfig['port'],
        $dbConfig['charset']
    );
    
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Créer la base de données si elle n'existe pas
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbConfig['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbConfig['name']}`");
    
    // Désactiver temporairement la vérification des clés étrangères
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // Lire le schéma SQL ligne par ligne
    $lines = file(__DIR__ . '/schema.sql', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $currentStatement = '';
    $inStatement = false;
    
    foreach ($lines as $lineNum => $line) {
        $trimmed = trim($line);
        
        // Ignorer les commentaires et les lignes vides
        if (empty($trimmed) || preg_match('/^--/', $trimmed)) {
            continue;
        }
        
        // Ignorer CREATE DATABASE et USE
        if (preg_match('/CREATE DATABASE/i', $trimmed) || preg_match('/^USE /i', $trimmed)) {
            continue;
        }
        
        $currentStatement .= $line . "\n";
        $inStatement = true;
        
        // Si la ligne se termine par ;, c'est la fin d'une instruction
        if (preg_match('/;\s*$/', $trimmed)) {
            $stmt = trim($currentStatement);
            if (!empty($stmt) && strlen($stmt) > 5) {
                try {
                    $pdo->exec($stmt);
                } catch (PDOException $e) {
                    $errorMsg = $e->getMessage();
                    // Ignorer seulement les erreurs de "already exists" et "duplicate"
                    if (strpos($errorMsg, 'already exists') === false && 
                        strpos($errorMsg, 'Duplicate') === false &&
                        strpos($errorMsg, 'PRIMARY') === false) {
                        // Afficher seulement les vraies erreurs
                        if (strpos($errorMsg, 'Syntax error') === false || strpos($stmt, 'CREATE') !== false) {
                            echo "⚠ Ligne " . ($lineNum + 1) . ": " . substr($errorMsg, 0, 80) . "\n";
                            echo "   SQL: " . substr($stmt, 0, 100) . "...\n";
                        }
                    }
                }
            }
            $currentStatement = '';
            $inStatement = false;
        }
    }
    
    // Exécuter la dernière instruction si elle existe
    if (!empty(trim($currentStatement))) {
        try {
            $pdo->exec(trim($currentStatement));
        } catch (PDOException $e) {
            // Ignorer les erreurs attendues
        }
    }
    
    // Réactiver la vérification des clés étrangères
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    // Ajouter les index après la création des tables
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_documents_type ON documents(document_type_id)",
        "CREATE INDEX IF NOT EXISTS idx_documents_correspondent ON documents(correspondent_id)",
        "CREATE INDEX IF NOT EXISTS idx_documents_date ON documents(doc_date)",
        "CREATE INDEX IF NOT EXISTS idx_tasks_status ON tasks(status)",
        "CREATE INDEX IF NOT EXISTS idx_tasks_assigned ON tasks(assigned_to, status)",
        "CREATE INDEX IF NOT EXISTS idx_tasks_due ON tasks(due_date)",
        "CREATE INDEX IF NOT EXISTS idx_workflow_instances_status ON workflow_instances(status)",
        "CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id, is_read)",
    ];
    
    foreach ($indexes as $indexSql) {
        try {
            // MariaDB ne supporte pas IF NOT EXISTS pour CREATE INDEX, donc on essaie/catch
            $indexSql = str_replace(' IF NOT EXISTS', '', $indexSql);
            $pdo->exec($indexSql);
        } catch (PDOException $e) {
            // Ignorer si l'index existe déjà
            if (strpos($e->getMessage(), 'Duplicate key name') === false && 
                strpos($e->getMessage(), 'Base table or view not found') === false) {
                // Ne rien afficher pour les tables qui n'existent pas encore
            }
        }
    }
    
    // Vérifier les tables créées
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "\n✓ Base de données '{$dbConfig['name']}' créée avec succès!\n";
    echo "✓ " . count($tables) . " tables créées:\n";
    foreach ($tables as $table) {
        echo "  - $table\n";
    }
    
    // Vérifier les données initiales
    try {
        $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $roleCount = $pdo->query("SELECT COUNT(*) FROM role_types")->fetchColumn();
        $docTypeCount = $pdo->query("SELECT COUNT(*) FROM document_types")->fetchColumn();
        $groupCount = $pdo->query("SELECT COUNT(*) FROM groups")->fetchColumn();
        
        echo "\n✓ Données initiales:\n";
        echo "  - Utilisateurs: $userCount\n";
        echo "  - Types de rôles: $roleCount\n";
        echo "  - Types de documents: $docTypeCount\n";
        echo "  - Groupes: $groupCount\n";
    } catch (PDOException $e) {
        echo "\n⚠ Certaines tables n'ont pas pu être créées correctement.\n";
    }
    
} catch (PDOException $e) {
    echo "ERREUR: " . $e->getMessage() . "\n";
    exit(1);
}

<?php
/**
 * Migration Mail Accounts, Tasks, File Renaming
 * Exécuter ce script pour créer les tables nécessaires
 */

require_once __DIR__ . '/../app/autoload.php';

use KDocs\Core\Database;

$db = Database::getInstance();

echo "🚀 Migration Mail Accounts, Tasks, File Renaming - K-Docs\n";
echo "==========================================================\n\n";

try {
    // Désactiver temporairement les vérifications de clés étrangères
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // 1. Mail Accounts
    echo "1. Création tables Mail Accounts...\n";
    $sql = file_get_contents(__DIR__ . '/migration_mail_accounts.sql');
    // Exécuter ligne par ligne pour gérer les erreurs
    $statements = explode(';', $sql);
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement) && !preg_match('/^--/', $statement)) {
            try {
                $db->exec($statement);
            } catch (\Exception $e) {
                // Ignorer les erreurs de clés étrangères pour l'instant
                if (strpos($e->getMessage(), 'Foreign key') === false) {
                    throw $e;
                }
            }
        }
    }
    
    // Réactiver les vérifications
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    echo "   ✅ Tables Mail Accounts créées\n\n";
    
    // 2. Scheduled Tasks
    echo "2. Création tables Scheduled Tasks...\n";
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");
    $sql = file_get_contents(__DIR__ . '/migration_tasks.sql');
    $statements = explode(';', $sql);
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement) && !preg_match('/^--/', $statement)) {
            try {
                $db->exec($statement);
            } catch (\Exception $e) {
                if (strpos($e->getMessage(), 'Foreign key') === false && strpos($e->getMessage(), 'Duplicate') === false) {
                    throw $e;
                }
            }
        }
    }
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "   ✅ Tables Scheduled Tasks créées\n\n";
    
    // 3. File Renaming
    echo "3. Création tables File Renaming...\n";
    $sql = file_get_contents(__DIR__ . '/migration_file_renaming.sql');
    $db->exec($sql);
    echo "   ✅ Tables File Renaming créées\n\n";
    
    echo "✅ Migration terminée avec succès !\n";
    
} catch (\Exception $e) {
    echo "❌ Erreur lors de la migration : " . $e->getMessage() . "\n";
    exit(1);
}

<?php
/**
 * Migration pour retirer "folder" de l'icône par défaut des dossiers logiques
 */

require_once __DIR__ . '/../app/autoload.php';

use KDocs\Core\Database;

$db = Database::getInstance();

echo "Retrait de 'folder' des icônes par défaut...\n\n";

try {
    // Modifier la valeur par défaut de la colonne icon
    $db->exec("ALTER TABLE logical_folders MODIFY COLUMN icon VARCHAR(50) DEFAULT NULL");
    echo "✅ Colonne icon modifiée (DEFAULT NULL)\n";
    
    // Mettre à jour les dossiers qui ont 'folder' comme icône
    $db->exec("UPDATE logical_folders SET icon = NULL WHERE icon = 'folder'");
    echo "✅ Dossiers avec 'folder' mis à jour\n";
    
    echo "\n✅ Migration terminée avec succès!\n";
    
} catch (\Exception $e) {
    echo "\n❌ Erreur lors de la migration: " . $e->getMessage() . "\n";
    exit(1);
}

<?php
/**
 * Migration 009: Support IA pour les champs de classification
 */

require_once __DIR__ . '/../app/autoload.php';

use KDocs\Core\Database;

$db = Database::getInstance();

echo "🚀 Migration 009: Support IA pour champs de classification\n";
echo "===========================================================\n\n";

try {
    $columnExists = function($table, $column) use ($db) {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
            return $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    };
    
    echo "1. Ajout colonnes IA...\n";
    
    if (!$columnExists('classification_fields', 'use_ai')) {
        $db->exec("ALTER TABLE classification_fields ADD COLUMN use_ai BOOLEAN DEFAULT FALSE COMMENT 'Utiliser l\'IA (Claude) au lieu des mots-clés'");
        echo "   ✅ Colonne use_ai ajoutée\n";
    } else {
        echo "   ⚠️  Colonne use_ai existe déjà\n";
    }
    
    if (!$columnExists('classification_fields', 'ai_prompt')) {
        $db->exec("ALTER TABLE classification_fields ADD COLUMN ai_prompt TEXT COMMENT 'Prompt personnalisé pour l\'IA'");
        echo "   ✅ Colonne ai_prompt ajoutée\n";
    } else {
        echo "   ⚠️  Colonne ai_prompt existe déjà\n";
    }
    
    echo "\n✅ Migration 009 terminée avec succès !\n";
    
} catch (Exception $e) {
    echo "\n❌ Erreur lors de la migration : " . $e->getMessage() . "\n";
    exit(1);
}

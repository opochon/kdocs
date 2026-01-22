<?php
/**
 * Migration 007: Ajout des colonnes de matching pour classification automatique
 * Exécuter avec: php database/migrate_007_matching_columns.php
 */

require_once __DIR__ . '/../app/autoload.php';

use KDocs\Core\Database;

$db = Database::getInstance();

echo "🚀 Migration 007: Colonnes de matching pour classification automatique\n";
echo "======================================================================\n\n";

try {
    // Fonction pour vérifier si une colonne existe
    $columnExists = function($table, $column) use ($db) {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
            return $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    };
    
    // Colonnes pour correspondants
    echo "1. Ajout colonnes à la table correspondants...\n";
    $correspondentsColumns = [
        'matching_algorithm' => "VARCHAR(20) DEFAULT 'any'",
        'matching_keywords' => 'TEXT',
        'is_insensitive' => 'BOOLEAN DEFAULT TRUE'
    ];
    foreach ($correspondentsColumns as $col => $def) {
        if (!$columnExists('correspondents', $col)) {
            $db->exec("ALTER TABLE correspondents ADD COLUMN `$col` $def");
            echo "   ✅ Colonne $col ajoutée\n";
        } else {
            echo "   ⚠️  Colonne $col existe déjà\n";
        }
    }
    
    // Colonnes pour tags
    echo "\n2. Ajout colonnes à la table tags...\n";
    $tagsColumns = [
        'matching_algorithm' => "VARCHAR(20) DEFAULT 'any'",
        'matching_keywords' => 'TEXT',
        'is_insensitive' => 'BOOLEAN DEFAULT TRUE'
    ];
    foreach ($tagsColumns as $col => $def) {
        if (!$columnExists('tags', $col)) {
            $db->exec("ALTER TABLE tags ADD COLUMN `$col` $def");
            echo "   ✅ Colonne $col ajoutée\n";
        } else {
            echo "   ⚠️  Colonne $col existe déjà\n";
        }
    }
    
    // Colonnes pour document_types
    echo "\n3. Ajout colonnes à la table document_types...\n";
    $docTypesColumns = [
        'matching_algorithm' => "VARCHAR(20) DEFAULT 'any'",
        'matching_keywords' => 'TEXT',
        'is_insensitive' => 'BOOLEAN DEFAULT TRUE',
        'consume_subfolder' => 'VARCHAR(100)'
    ];
    foreach ($docTypesColumns as $col => $def) {
        if (!$columnExists('document_types', $col)) {
            $db->exec("ALTER TABLE document_types ADD COLUMN `$col` $def");
            echo "   ✅ Colonne $col ajoutée\n";
        } else {
            echo "   ⚠️  Colonne $col existe déjà\n";
        }
    }
    
    // Colonnes pour documents
    echo "\n4. Ajout colonnes à la table documents...\n";
    $documentsColumns = [
        'classification_suggestions' => 'JSON',
        'consume_subfolder' => 'VARCHAR(100)',
        'status' => "VARCHAR(20) DEFAULT 'pending'"
    ];
    foreach ($documentsColumns as $col => $def) {
        if (!$columnExists('documents', $col)) {
            $db->exec("ALTER TABLE documents ADD COLUMN `$col` $def");
            echo "   ✅ Colonne $col ajoutée\n";
        } else {
            echo "   ⚠️  Colonne $col existe déjà\n";
        }
    }
    
    echo "\n✅ Migration 007 terminée avec succès !\n";
    
} catch (Exception $e) {
    echo "\n❌ Erreur lors de la migration : " . $e->getMessage() . "\n";
    exit(1);
}

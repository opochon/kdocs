<?php
/**
 * Migration PHP : ajoute les colonnes des tâches autonomes (standalone) à la table `tasks`.
 *
 * Contexte : la table `tasks` est historiquement le schéma des étapes de workflow
 * (workflow_instance_id, step_id, assigned_role_id...). L'UI /tasks + /tasks/create
 * expose une tâche autonome (titre, description, priorité, document, créateur) que le
 * modèle Task::create() ne persistait pas (title/description/priority étaient ignorés).
 * On étend donc la table avec des colonnes nullable pour supporter les deux usages.
 *
 * Idempotent : chaque colonne n'est ajoutée que si elle manque.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Core/Config.php';

use KDocs\Core\Database;

echo "Migration: Ajout des colonnes tâches autonomes à `tasks`...\n\n";

try {
    $db = Database::getInstance();

    $columnExists = function ($table, $column) use ($db) {
        try {
            $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            return $stmt->fetch() !== false;
        } catch (\Exception $e) {
            return false;
        }
    };

    $columns = [
        'title'            => "VARCHAR(255) DEFAULT NULL COMMENT 'Titre de la tâche autonome'",
        'description'      => "TEXT DEFAULT NULL COMMENT 'Description de la tâche autonome'",
        'priority'         => "VARCHAR(20) DEFAULT 'medium' COMMENT 'low|medium|high|urgent'",
        'document_id'      => "INT DEFAULT NULL COMMENT 'Document associé (tâche autonome)'",
        'workflow_type_id' => "INT DEFAULT NULL COMMENT 'Type de workflow (tâche autonome)'",
        'created_by'       => "INT DEFAULT NULL COMMENT 'Utilisateur créateur'",
        'updated_at'       => "DATETIME DEFAULT NULL",
        'completed_by'     => "INT DEFAULT NULL COMMENT 'Utilisateur ayant clôturé'",
    ];

    foreach ($columns as $column => $definition) {
        if (!$columnExists('tasks', $column)) {
            try {
                $db->exec("ALTER TABLE tasks ADD COLUMN `$column` $definition");
                echo "   ✅ Colonne $column ajoutée\n";
            } catch (\Exception $e) {
                echo "   ⚠️  Erreur pour $column: " . $e->getMessage() . "\n";
            }
        } else {
            echo "   ℹ️  Colonne $column existe déjà\n";
        }
    }

    // Rendre workflow_instance_id et step_id nullable (une tâche autonome n'a pas de workflow).
    echo "\n2. Rendre workflow_instance_id / step_id nullables...\n";
    try {
        $db->exec("ALTER TABLE tasks MODIFY COLUMN workflow_instance_id INT DEFAULT NULL");
        echo "   ✅ workflow_instance_id nullable\n";
    } catch (\Exception $e) {
        echo "   ⚠️  Erreur workflow_instance_id: " . $e->getMessage() . "\n";
    }
    try {
        $db->exec("ALTER TABLE tasks MODIFY COLUMN step_id INT DEFAULT NULL");
        echo "   ✅ step_id nullable\n";
    } catch (\Exception $e) {
        echo "   ⚠️  Erreur step_id: " . $e->getMessage() . "\n";
    }

    echo "\n✅ Migration terminée avec succès!\n";
} catch (\Exception $e) {
    echo "❌ Erreur de migration: " . $e->getMessage() . "\n";
    exit(1);
}

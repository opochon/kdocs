<?php
/**
 * Migration: Enrichir la table correspondents pour liaison ERP
 *
 * Ajoute les colonnes nécessaires pour une gestion complète des correspondants
 * avec support pour personnes, entreprises et administrations.
 *
 * @date 2026-02-04
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use KDocs\Core\Database;

echo "Migration: Enrichissement de la table correspondents...\n\n";

try {
    $db = Database::getInstance();

    // Fonction pour vérifier si une colonne existe
    $columnExists = function($table, $column) use ($db) {
        try {
            $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            return $stmt->fetch() !== false;
        } catch (\Exception $e) {
            return false;
        }
    };

    // Colonnes à ajouter
    $newColumns = [
        'type' => "ENUM('personne', 'entreprise', 'administration') DEFAULT 'personne' COMMENT 'Type de correspondant'",
        'nom_entreprise' => "VARCHAR(255) DEFAULT NULL COMMENT 'Nom de l\\'entreprise (si type=entreprise)'",
        'prenom' => "VARCHAR(100) DEFAULT NULL COMMENT 'Prénom (si type=personne)'",
        'npa' => "VARCHAR(20) DEFAULT NULL COMMENT 'Code postal / NPA'",
        'ville' => "VARCHAR(100) DEFAULT NULL COMMENT 'Ville'",
        'pays' => "VARCHAR(100) DEFAULT 'Suisse' COMMENT 'Pays (défaut: Suisse)'",
        'telephone' => "VARCHAR(50) DEFAULT NULL COMMENT 'Numéro de téléphone'",
        'type_contact' => "ENUM('client', 'fournisseur', 'administration', 'partenaire', 'autre') DEFAULT NULL COMMENT 'Type de relation'",
        'reference_erp' => "VARCHAR(100) DEFAULT NULL COMMENT 'Référence externe ERP pour liaison future'",
        'notes' => "TEXT DEFAULT NULL COMMENT 'Notes internes'",
        'updated_at' => "DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Date de mise à jour'"
    ];

    echo "1. Ajout des nouvelles colonnes à la table correspondents...\n";

    foreach ($newColumns as $column => $definition) {
        if (!$columnExists('correspondents', $column)) {
            try {
                $db->exec("ALTER TABLE correspondents ADD COLUMN `$column` $definition");
                echo "   OK Colonne $column ajoutée\n";
            } catch (\Exception $e) {
                echo "   WARN Erreur pour $column: " . $e->getMessage() . "\n";
            }
        } else {
            echo "   INFO Colonne $column existe déjà\n";
        }
    }

    // Renommer 'name' en 'nom' pour cohérence (optionnel - on garde name pour compatibilité)
    // On ajoute un alias via une colonne virtuelle si nécessaire

    // Créer un index sur reference_erp pour les recherches futures
    echo "\n2. Création des index...\n";
    try {
        // Vérifier si l'index existe
        $indexExists = $db->query("SHOW INDEX FROM correspondents WHERE Key_name = 'idx_correspondents_reference_erp'")->fetch();
        if (!$indexExists) {
            $db->exec("CREATE INDEX idx_correspondents_reference_erp ON correspondents(reference_erp)");
            echo "   OK Index idx_correspondents_reference_erp créé\n";
        } else {
            echo "   INFO Index idx_correspondents_reference_erp existe déjà\n";
        }
    } catch (\Exception $e) {
        echo "   WARN Erreur création index: " . $e->getMessage() . "\n";
    }

    try {
        $indexExists = $db->query("SHOW INDEX FROM correspondents WHERE Key_name = 'idx_correspondents_type_contact'")->fetch();
        if (!$indexExists) {
            $db->exec("CREATE INDEX idx_correspondents_type_contact ON correspondents(type_contact)");
            echo "   OK Index idx_correspondents_type_contact créé\n";
        } else {
            echo "   INFO Index idx_correspondents_type_contact existe déjà\n";
        }
    } catch (\Exception $e) {
        echo "   WARN Erreur création index: " . $e->getMessage() . "\n";
    }

    // Migration des données existantes
    echo "\n3. Migration des données existantes...\n";
    try {
        // Mettre à jour les correspondants existants avec is_customer/is_supplier
        $db->exec("UPDATE correspondents SET type_contact = 'client' WHERE is_customer = 1 AND type_contact IS NULL");
        $db->exec("UPDATE correspondents SET type_contact = 'fournisseur' WHERE is_supplier = 1 AND type_contact IS NULL");
        echo "   OK Données existantes migrées\n";
    } catch (\Exception $e) {
        echo "   WARN Erreur migration données: " . $e->getMessage() . "\n";
    }

    echo "\n========================================\n";
    echo "Migration terminée avec succès!\n";
    echo "========================================\n";
    echo "\nNouvelle structure de la table correspondents:\n";

    // Afficher la structure finale
    $cols = $db->query("SHOW COLUMNS FROM correspondents")->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo "  - {$col['Field']}: {$col['Type']}\n";
    }

} catch (\Exception $e) {
    echo "ERREUR Migration: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}

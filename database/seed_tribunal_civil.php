<?php
/**
 * Script pour créer le correspondant "Tribunal civil" avec règles de matching
 */

require_once __DIR__ . '/../app/autoload.php';

use KDocs\Core\Database;

$db = Database::getInstance();

echo "🔧 Création correspondant Tribunal civil\n";
echo "==========================================\n\n";

try {
    // Vérifier si existe déjà
    $stmt = $db->prepare("SELECT id, name FROM correspondents WHERE name LIKE ?");
    $stmt->execute(['%tribunal%']);
    $existing = $stmt->fetchAll();
    
    if (!empty($existing)) {
        echo "Correspondants existants avec 'tribunal':\n";
        foreach ($existing as $corr) {
            echo "  - {$corr['name']} (ID: {$corr['id']})\n";
        }
        
        // Mettre à jour avec les règles de matching
        foreach ($existing as $corr) {
            $db->prepare("UPDATE correspondents SET matching_keywords = ?, matching_algorithm = 'any', is_insensitive = TRUE WHERE id = ?")
                ->execute(['tribunal civil, tribunal, cour, justice, courrier tribunal', $corr['id']]);
            echo "  ✅ Règles de matching mises à jour pour {$corr['name']}\n";
        }
    } else {
        // Créer le correspondant
        $db->prepare("
            INSERT INTO correspondents (name, matching_keywords, matching_algorithm, is_insensitive)
            VALUES (?, ?, 'any', TRUE)
        ")->execute(['Tribunal civil', 'tribunal civil, tribunal, cour, justice, courrier tribunal']);
        echo "✅ Correspondant 'Tribunal civil' créé avec règles de matching\n";
    }
    
    echo "\n✅ Terminé !\n";
    
} catch (\Exception $e) {
    echo "\n❌ Erreur: " . $e->getMessage() . "\n";
    exit(1);
}

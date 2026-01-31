<?php
/**
 * K-Docs - Exécution des migrations de stabilisation
 * 
 * Usage: php database/run_stabilisation_migrations.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use KDocs\Core\Database;

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║         K-DOCS - MIGRATIONS STABILISATION                    ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

$db = Database::getInstance();

$migrations = [
    '028_fulltext_search.sql' => 'Recherche FULLTEXT',
    '029_fix_deleted_fields.sql' => 'Harmonisation deleted',
];

$results = [];

foreach ($migrations as $file => $description) {
    echo "--- $description ($file) ---\n";
    
    $path = __DIR__ . '/migrations/' . $file;
    
    if (!file_exists($path)) {
        echo "\033[31m[ERREUR]\033[0m Fichier non trouvé: $path\n\n";
        $results[$file] = 'FILE_NOT_FOUND';
        continue;
    }
    
    $sql = file_get_contents($path);
    
    // Parser les statements (séparés par ;)
    // On ignore les commentaires et les DELIMITER
    $statements = [];
    $current = '';
    $inDelimiter = false;
    
    foreach (explode("\n", $sql) as $line) {
        $line = trim($line);
        
        // Ignorer commentaires
        if (str_starts_with($line, '--') || str_starts_with($line, '#') || empty($line)) {
            continue;
        }
        
        // Gérer DELIMITER (pour les procédures stockées)
        if (str_starts_with(strtoupper($line), 'DELIMITER')) {
            $inDelimiter = !$inDelimiter;
            continue;
        }
        
        $current .= ' ' . $line;
        
        // Si on n'est pas dans un DELIMITER et la ligne finit par ;
        if (!$inDelimiter && str_ends_with($line, ';')) {
            $stmt = trim($current);
            $stmt = rtrim($stmt, ';');
            if (!empty($stmt)) {
                $statements[] = $stmt;
            }
            $current = '';
        }
    }
    
    // Exécuter chaque statement
    $success = 0;
    $errors = 0;
    
    foreach ($statements as $i => $stmt) {
        $stmt = trim($stmt);
        if (empty($stmt)) continue;
        
        // Afficher un aperçu
        $preview = substr(preg_replace('/\s+/', ' ', $stmt), 0, 60);
        
        try {
            $db->exec($stmt);
            echo "\033[32m[OK]\033[0m $preview...\n";
            $success++;
        } catch (PDOException $e) {
            $errorMsg = $e->getMessage();
            
            // Certaines erreurs sont acceptables
            if (str_contains($errorMsg, 'Duplicate') || 
                str_contains($errorMsg, 'already exists') ||
                str_contains($errorMsg, 'PROCEDURE') ||
                str_contains($errorMsg, 'doesn\'t exist')) {
                echo "\033[33m[SKIP]\033[0m $preview... (déjà fait ou non applicable)\n";
            } else {
                echo "\033[31m[ERR]\033[0m $preview...\n";
                echo "       → $errorMsg\n";
                $errors++;
            }
        }
    }
    
    echo "\n";
    
    if ($errors > 0) {
        $results[$file] = "PARTIAL ($success OK, $errors erreurs)";
    } else {
        $results[$file] = "OK ($success statements)";
    }
}

// ============================================
// VÉRIFICATION POST-MIGRATION
// ============================================
echo "--- VÉRIFICATION ---\n\n";

// Vérifier index FULLTEXT
try {
    $stmt = $db->query("
        SELECT INDEX_NAME 
        FROM information_schema.statistics 
        WHERE table_schema = DATABASE() 
          AND table_name = 'documents' 
          AND index_type = 'FULLTEXT'
        LIMIT 1
    ");
    $hasFulltext = $stmt->fetch() !== false;
    echo ($hasFulltext ? "\033[32m[✓]\033[0m" : "\033[31m[✗]\033[0m") . " Index FULLTEXT sur documents\n";
} catch (Exception $e) {
    echo "\033[31m[✗]\033[0m Index FULLTEXT: " . $e->getMessage() . "\n";
}

// Vérifier cohérence deleted
try {
    $stmt = $db->query("
        SELECT COUNT(*) FROM documents 
        WHERE (is_deleted = 1 AND deleted_at IS NULL) 
           OR (is_deleted = 0 AND deleted_at IS NOT NULL)
    ");
    $inconsistent = $stmt->fetchColumn();
    echo ($inconsistent == 0 ? "\033[32m[✓]\033[0m" : "\033[33m[!]\033[0m") . " Cohérence deleted: $inconsistent incohérences\n";
} catch (Exception $e) {
    echo "\033[31m[✗]\033[0m Cohérence deleted: " . $e->getMessage() . "\n";
}

// ============================================
// RÉSUMÉ
// ============================================
echo "\n";
echo "══════════════════════════════════════════════════════════════\n";
echo "RÉSUMÉ DES MIGRATIONS\n";
echo "══════════════════════════════════════════════════════════════\n";

foreach ($results as $file => $status) {
    echo "  $file: $status\n";
}

echo "\n";

<?php
/**
 * Script de diagnostic complet pour kdocs
 * Vérifie : base de données, documents, OCR, configuration IA
 */

require_once __DIR__ . '/vendor/autoload.php';

use KDocs\Core\Database;
use KDocs\Core\Config;

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>K-Docs - Diagnostic Complet</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; }
        .card { background: white; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .card h2 { margin-top: 0; color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        .success { color: #28a745; }
        .warning { color: #ffc107; }
        .error { color: #dc3545; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; }
        tr:hover { background: #f1f3f5; }
        .code { background: #f4f4f4; padding: 10px; border-radius: 4px; font-family: monospace; overflow-x: auto; }
        .fix-btn { background: #007bff; color: white; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; }
        .fix-btn:hover { background: #0056b3; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔍 K-Docs - Diagnostic Complet</h1>

<?php
try {
    $db = Database::getInstance();
    $config = Config::load();
    
    // ========================================
    // 1. CONFIGURATION CLAUDE
    // ========================================
    echo '<div class="card">';
    echo '<h2>🤖 Configuration Claude API</h2>';
    
    $claudeKey = $config['claude']['api_key'] ?? $config['ai']['claude_api_key'] ?? $_ENV['ANTHROPIC_API_KEY'] ?? null;
    
    if ($claudeKey) {
        $masked = substr($claudeKey, 0, 10) . '...' . substr($claudeKey, -4);
        echo "<p class='success'>✅ Clé API configurée : <code>$masked</code></p>";
        
        // Test de connexion Claude
        $claude = new \KDocs\Services\ClaudeService();
        if ($claude->isConfigured()) {
            echo "<p class='success'>✅ ClaudeService initialisé correctement</p>";
        }
    } else {
        echo "<p class='error'>❌ Clé API Claude NON CONFIGURÉE</p>";
        echo "<p>La recherche IA et les suggestions ne fonctionneront pas sans clé API.</p>";
        echo "<div class='code'>";
        echo "Solutions :<br>";
        echo "1. Modifier config/config.php : 'api_key' => 'sk-ant-api03-...'<br>";
        echo "2. Ou créer le fichier claude_api_key.txt à la racine<br>";
        echo "3. Ou définir la variable d'environnement ANTHROPIC_API_KEY";
        echo "</div>";
    }
    echo '</div>';
    
    // ========================================
    // 2. DOCUMENTS EN BASE
    // ========================================
    echo '<div class="card">';
    echo '<h2>📄 Documents en Base de Données</h2>';
    
    $stats = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN content IS NOT NULL AND LENGTH(content) > 0 THEN 1 ELSE 0 END) as with_ocr,
            SUM(CASE WHEN content IS NULL OR LENGTH(content) = 0 THEN 1 ELSE 0 END) as without_ocr,
            SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) as deleted
        FROM documents
    ")->fetch();
    
    echo "<table>";
    echo "<tr><th>Métrique</th><th>Valeur</th></tr>";
    echo "<tr><td>Total documents</td><td><strong>{$stats['total']}</strong></td></tr>";
    echo "<tr><td>Avec contenu OCR</td><td class='success'>{$stats['with_ocr']}</td></tr>";
    echo "<tr><td>Sans contenu OCR</td><td class='" . ($stats['without_ocr'] > 0 ? 'warning' : 'success') . "'>{$stats['without_ocr']}</td></tr>";
    echo "<tr><td>Supprimés (soft delete)</td><td>{$stats['deleted']}</td></tr>";
    echo "</table>";
    
    // Liste des documents
    echo "<h3>📋 Liste des documents :</h3>";
    $docs = $db->query("
        SELECT d.id, d.title, d.original_filename, 
               LENGTH(d.content) as content_length,
               c.name as correspondent_name,
               dt.label as type_name,
               d.document_date,
               d.deleted_at
        FROM documents d
        LEFT JOIN correspondents c ON d.correspondent_id = c.id
        LEFT JOIN document_types dt ON d.document_type_id = dt.id
        ORDER BY d.id DESC
        LIMIT 20
    ")->fetchAll();
    
    echo "<table>";
    echo "<tr><th>ID</th><th>Titre</th><th>Fichier</th><th>OCR (chars)</th><th>Correspondant</th><th>Type</th><th>Date</th></tr>";
    foreach ($docs as $doc) {
        $ocrClass = $doc['content_length'] > 0 ? 'success' : 'error';
        $title = htmlspecialchars($doc['title'] ?: '-');
        $filename = htmlspecialchars($doc['original_filename'] ?: '-');
        $corr = htmlspecialchars($doc['correspondent_name'] ?: '-');
        $type = htmlspecialchars($doc['type_name'] ?: '-');
        $date = $doc['document_date'] ?: '-';
        echo "<tr>";
        echo "<td>{$doc['id']}</td>";
        echo "<td>$title</td>";
        echo "<td>$filename</td>";
        echo "<td class='$ocrClass'>{$doc['content_length']}</td>";
        echo "<td>$corr</td>";
        echo "<td>$type</td>";
        echo "<td>$date</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo '</div>';
    
    // ========================================
    // 3. TEST DE RECHERCHE
    // ========================================
    echo '<div class="card">';
    echo '<h2>🔍 Test de Recherche</h2>';
    
    $searchTerms = ['divorce', 'tribunal', 'facture', 'courrier'];
    
    foreach ($searchTerms as $term) {
        $search = '%' . $term . '%';
        $results = $db->query("
            SELECT id, title, original_filename,
                   CASE 
                       WHEN title LIKE ? THEN 'titre'
                       WHEN content LIKE ? THEN 'contenu'
                       WHEN original_filename LIKE ? THEN 'fichier'
                       ELSE 'autre'
                   END as match_type
            FROM documents 
            WHERE deleted_at IS NULL
              AND (title LIKE ? OR content LIKE ? OR original_filename LIKE ?)
            LIMIT 5
        ", [$search, $search, $search, $search, $search, $search])->fetchAll();
        
        $count = count($results);
        $class = $count > 0 ? 'success' : 'warning';
        echo "<p><strong>Recherche '$term':</strong> <span class='$class'>$count résultat(s)</span></p>";
        
        if ($count > 0) {
            echo "<ul>";
            foreach ($results as $r) {
                echo "<li>{$r['title']} (match: {$r['match_type']})</li>";
            }
            echo "</ul>";
        }
    }
    echo '</div>';
    
    // ========================================
    // 4. CONTENU OCR DU DOCUMENT 8 (Tribunal)
    // ========================================
    echo '<div class="card">';
    echo '<h2>📝 Contenu OCR du Document #8 (Courrier Tribunal)</h2>';
    
    $doc8 = $db->query("SELECT id, title, content, original_filename FROM documents WHERE id = 8")->fetch();
    
    if ($doc8) {
        echo "<p><strong>Titre:</strong> " . htmlspecialchars($doc8['title'] ?: 'N/A') . "</p>";
        echo "<p><strong>Fichier:</strong> " . htmlspecialchars($doc8['original_filename'] ?: 'N/A') . "</p>";
        echo "<p><strong>Longueur contenu:</strong> " . strlen($doc8['content'] ?? '') . " caractères</p>";
        
        if (!empty($doc8['content'])) {
            $preview = htmlspecialchars(substr($doc8['content'], 0, 1000));
            echo "<div class='code' style='max-height: 300px; overflow-y: auto;'>";
            echo nl2br($preview);
            if (strlen($doc8['content']) > 1000) {
                echo "<br><em>... (tronqué)</em>";
            }
            echo "</div>";
            
            // Vérifier si "divorce" est dans le contenu
            if (stripos($doc8['content'], 'divorce') !== false) {
                echo "<p class='success'>✅ Le mot 'divorce' est présent dans le contenu</p>";
            } else {
                echo "<p class='warning'>⚠️ Le mot 'divorce' n'est PAS dans le contenu OCR</p>";
            }
        } else {
            echo "<p class='error'>❌ Contenu OCR vide !</p>";
        }
    } else {
        echo "<p class='error'>Document #8 non trouvé</p>";
    }
    echo '</div>';
    
    // ========================================
    // 5. STRUCTURE DES TABLES
    // ========================================
    echo '<div class="card">';
    echo '<h2>🗄️ Structure Tables Importantes</h2>';
    
    // Vérifier la colonne content
    $columns = $db->query("SHOW COLUMNS FROM documents")->fetchAll();
    $hasContent = false;
    foreach ($columns as $col) {
        if ($col['Field'] === 'content') {
            $hasContent = true;
            echo "<p class='success'>✅ Colonne 'content' existe : {$col['Type']}</p>";
        }
    }
    if (!$hasContent) {
        echo "<p class='error'>❌ Colonne 'content' manquante !</p>";
    }
    
    // Compter les entités
    $correspondents = $db->query("SELECT COUNT(*) as c FROM correspondents")->fetch()['c'];
    $types = $db->query("SELECT COUNT(*) as c FROM document_types")->fetch()['c'];
    $tags = $db->query("SELECT COUNT(*) as c FROM tags")->fetch()['c'];
    
    echo "<table>";
    echo "<tr><th>Table</th><th>Nombre d'entrées</th></tr>";
    echo "<tr><td>correspondents</td><td>$correspondents</td></tr>";
    echo "<tr><td>document_types</td><td>$types</td></tr>";
    echo "<tr><td>tags</td><td>$tags</td></tr>";
    echo "</table>";
    echo '</div>';
    
    // ========================================
    // 6. RECOMMANDATIONS
    // ========================================
    echo '<div class="card">';
    echo '<h2>💡 Recommandations</h2>';
    
    $issues = [];
    
    if (!$claudeKey) {
        $issues[] = "Configurer la clé API Claude pour activer l'IA";
    }
    
    if ($stats['without_ocr'] > 0) {
        $issues[] = "Relancer l'OCR sur les {$stats['without_ocr']} documents sans contenu";
    }
    
    if (empty($issues)) {
        echo "<p class='success'>✅ Aucun problème majeur détecté</p>";
    } else {
        echo "<ul>";
        foreach ($issues as $issue) {
            echo "<li class='warning'>⚠️ $issue</li>";
        }
        echo "</ul>";
    }
    echo '</div>';
    
} catch (Exception $e) {
    echo '<div class="card">';
    echo '<h2 class="error">❌ Erreur</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    echo '</div>';
}
?>

</div>
</body>
</html>

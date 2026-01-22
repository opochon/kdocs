<?php
// Script de diagnostic pour kdocs
require_once __DIR__ . '/vendor/autoload.php';

$config = include __DIR__ . '/config/config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . $config['database']['host'] . ';port=' . $config['database']['port'] . ';dbname=' . $config['database']['name'],
        $config['database']['user'],
        $config['database']['password']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>Diagnostic K-Docs</h2>";
    
    // 1. Documents en base
    echo "<h3>Documents en base</h3>";
    $stmt = $pdo->query('SELECT id, title, original_filename, LENGTH(content) as content_len, LENGTH(ocr_text) as ocr_len FROM documents LIMIT 20');
    echo "<table border='1'><tr><th>ID</th><th>Title</th><th>File</th><th>Content (bytes)</th><th>OCR (bytes)</th></tr>";
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo "<tr><td>{$row['id']}</td><td>{$row['title']}</td><td>{$row['original_filename']}</td><td>{$row['content_len']}</td><td>{$row['ocr_len']}</td></tr>";
    }
    echo "</table>";
    
    // 2. Recherche divorce
    echo "<h3>Recherche 'divorce'</h3>";
    $stmt = $pdo->query("SELECT id, title FROM documents WHERE title LIKE '%divorce%' OR content LIKE '%divorce%' OR ocr_text LIKE '%divorce%'");
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<p>Résultats: " . count($results) . "</p>";
    if ($results) {
        echo "<ul>";
        foreach ($results as $row) {
            echo "<li>ID: {$row['id']} | {$row['title']}</li>";
        }
        echo "</ul>";
    }
    
    // 3. Recherche tribunal
    echo "<h3>Recherche 'tribunal'</h3>";
    $stmt = $pdo->query("SELECT id, title FROM documents WHERE title LIKE '%tribunal%' OR content LIKE '%tribunal%' OR ocr_text LIKE '%tribunal%'");
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<p>Résultats: " . count($results) . "</p>";
    if ($results) {
        echo "<ul>";
        foreach ($results as $row) {
            echo "<li>ID: {$row['id']} | {$row['title']}</li>";
        }
        echo "</ul>";
    }
    
    // 4. Vérifier Claude API
    echo "<h3>Configuration Claude API</h3>";
    $claudeKey = $config['claude']['api_key'] ?? $config['ai']['claude_api_key'] ?? null;
    echo "<p>Clé API configurée: " . ($claudeKey ? "<span style='color:green'>OUI (". substr($claudeKey, 0, 20) . "...)</span>" : "<span style='color:red'>NON</span>") . "</p>";
    
    // 5. Contenu du document 8 (celui avec Tribunal)
    echo "<h3>Contenu Document ID 8</h3>";
    $stmt = $pdo->query("SELECT id, title, LEFT(content, 500) as content_preview, LEFT(ocr_text, 500) as ocr_preview FROM documents WHERE id = 8");
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($doc) {
        echo "<p><strong>Titre:</strong> {$doc['title']}</p>";
        echo "<p><strong>Content (500 premiers chars):</strong><br><pre>" . htmlspecialchars($doc['content_preview'] ?: 'VIDE') . "</pre></p>";
        echo "<p><strong>OCR Text (500 premiers chars):</strong><br><pre>" . htmlspecialchars($doc['ocr_preview'] ?: 'VIDE') . "</pre></p>";
    } else {
        echo "<p>Document 8 non trouvé</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color:red'>Erreur: " . $e->getMessage() . "</p>";
}

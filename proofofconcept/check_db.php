<?php
require_once __DIR__ . '/helpers.php';

$pdo = poc_db();

echo "=== INDEX FULLTEXT ===\n";
$stmt = $pdo->query("SHOW INDEX FROM documents WHERE Index_type = 'FULLTEXT'");
$indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($indexes as $idx) {
    echo "- {$idx['Key_name']}: {$idx['Column_name']}\n";
}

echo "\n=== COLONNES TEXTE ===\n";
$stmt = $pdo->query("SHOW COLUMNS FROM documents");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $col) {
    if (stripos($col['Type'], 'text') !== false || stripos($col['Field'], 'text') !== false || $col['Field'] === 'title' || $col['Field'] === 'content') {
        echo "- {$col['Field']}: {$col['Type']}\n";
    }
}

echo "\n=== COLONNE EMBEDDING ===\n";
foreach ($cols as $col) {
    if (stripos($col['Field'], 'embed') !== false || stripos($col['Field'], 'vector') !== false) {
        echo "- {$col['Field']}: {$col['Type']}\n";
    }
}

echo "\n=== DOCUMENTS AVEC EMBEDDING ===\n";
$stmt = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN embedding IS NOT NULL AND LENGTH(embedding) > 10 THEN 1 ELSE 0 END) as with_emb FROM documents WHERE deleted_at IS NULL");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Total: {$stats['total']}, Avec embedding: {$stats['with_emb']}\n";

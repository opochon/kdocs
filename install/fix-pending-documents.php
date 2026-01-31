<?php
/**
 * K-Docs - Script pour corriger le statut des documents en attente
 *
 * Les documents avec status='pending' mais qui ont déjà été traités
 * (ont un OCR text ou ont été indexés) doivent passer à 'indexed'
 */

require_once __DIR__ . '/../vendor/autoload.php';

use KDocs\Core\Database;

$db = Database::getInstance();

echo "=== Correction des documents en attente ===\n\n";

// Compter les documents pending
$pendingCount = $db->query("SELECT COUNT(*) FROM documents WHERE status = 'pending'")->fetchColumn();
echo "Documents en attente: $pendingCount\n\n";

if ($pendingCount == 0) {
    echo "Aucun document à corriger.\n";
    exit(0);
}

// Lister les documents pending
$stmt = $db->query("SELECT id, filename, status, ocr_text, file_path FROM documents WHERE status = 'pending'");
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
$skipped = 0;

foreach ($documents as $doc) {
    $id = $doc['id'];
    $filename = $doc['filename'];
    $hasOcr = !empty($doc['ocr_text']) && strlen($doc['ocr_text']) > 10;

    // Vérifier si le fichier existe physiquement
    // Le file_path peut être absolu ou relatif
    $filePath = $doc['file_path'];
    if (strpos($filePath, ':') !== false || strpos($filePath, '/') === 0) {
        // Chemin absolu - normaliser les backslashes
        $fullPath = str_replace('\\\\', '\\', $filePath);
        $fullPath = str_replace('\\', '/', $fullPath);
    } else {
        // Chemin relatif
        $basePath = __DIR__ . '/../storage/documents';
        $fullPath = $basePath . '/' . $filePath;
    }
    $fileExists = file_exists($fullPath);

    echo "[$id] $filename\n";
    echo "    - Fichier existe: " . ($fileExists ? 'Oui' : 'Non') . "\n";
    echo "    - OCR: " . ($hasOcr ? 'Oui (' . strlen($doc['ocr_text']) . ' chars)' : 'Non') . "\n";

    // Si le fichier existe, marquer comme indexed
    if ($fileExists) {
        $newStatus = $hasOcr ? 'indexed' : 'indexed'; // On indexe quand même, OCR peut être vide pour certains fichiers

        $update = $db->prepare("UPDATE documents SET status = ? WHERE id = ?");
        $update->execute([$newStatus, $id]);

        echo "    => Status mis à jour: $newStatus\n";
        $updated++;
    } else {
        echo "    => Fichier introuvable, conservé en pending\n";
        $skipped++;
    }
    echo "\n";
}

echo "=== Résumé ===\n";
echo "Documents mis à jour: $updated\n";
echo "Documents ignorés: $skipped\n";

// Vérifier le résultat
$remaining = $db->query("SELECT COUNT(*) FROM documents WHERE status = 'pending'")->fetchColumn();
echo "Documents encore en attente: $remaining\n";

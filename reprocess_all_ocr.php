<?php
/**
 * Script pour relancer l'OCR sur tous les documents
 * Usage: http://localhost/kdocs/reprocess_all_ocr.php
 */
require_once __DIR__ . '/vendor/autoload.php';

use KDocs\Core\Database;
use KDocs\Core\Config;

set_time_limit(0);
header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html><html><head><title>Retraitement OCR</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#1e1e1e;color:#0f0;}";
echo ".error{color:#f00;}.success{color:#0f0;}.warning{color:#ff0;}</style></head><body>";
echo "<h1>🔄 Retraitement OCR de tous les documents</h1>";
echo "<pre>";

try {
    $db = Database::getInstance();
    $config = Config::load();
    
    // Vérifier Tesseract
    $tesseractPath = $config['ocr']['tesseract_path'] ?? 'tesseract';
    echo "Tesseract: $tesseractPath\n";
    
    // Test Tesseract
    $testCmd = escapeshellcmd($tesseractPath) . ' --version 2>&1';
    $tesseractVersion = shell_exec($testCmd);
    if (strpos($tesseractVersion, 'tesseract') !== false) {
        echo "<span class='success'>✅ Tesseract disponible</span>\n";
    } else {
        echo "<span class='error'>❌ Tesseract non trouvé!</span>\n";
        echo "Sortie: $tesseractVersion\n";
    }
    
    // Vérifier Ghostscript (pour PDF)
    $gsPath = $config['tools']['ghostscript'] ?? 'gswin64c';
    echo "Ghostscript: $gsPath\n";
    
    echo "\n" . str_repeat("=", 60) . "\n\n";
    
    // Récupérer tous les documents
    $docs = $db->query("
        SELECT id, title, original_filename, file_path, mime_type, filename
        FROM documents 
        WHERE deleted_at IS NULL
        ORDER BY id
    ")->fetchAll();
    
    echo "Documents à traiter : " . count($docs) . "\n\n";
    
    $success = 0;
    $failed = 0;
    
    foreach ($docs as $doc) {
        echo str_repeat("-", 60) . "\n";
        echo "📄 Document #{$doc['id']}: " . ($doc['title'] ?: $doc['original_filename']) . "\n";
        
        // Trouver le fichier
        $basePath = realpath(__DIR__ . '/storage/documents');
        $possiblePaths = [];
        
        if (!empty($doc['file_path'])) {
            $possiblePaths[] = $basePath . '/' . $doc['file_path'];
            $possiblePaths[] = $doc['file_path'];
        }
        if (!empty($doc['filename'])) {
            $possiblePaths[] = $basePath . '/' . $doc['filename'];
        }
        if (!empty($doc['original_filename'])) {
            $possiblePaths[] = $basePath . '/' . $doc['original_filename'];
        }
        
        // Chercher aussi dans les sous-dossiers
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($iterator as $file) {
            if ($file->getFilename() === $doc['original_filename'] || 
                $file->getFilename() === $doc['filename']) {
                $possiblePaths[] = $file->getPathname();
            }
        }
        
        $foundPath = null;
        foreach (array_unique($possiblePaths) as $path) {
            if (file_exists($path) && is_file($path)) {
                $foundPath = $path;
                break;
            }
        }
        
        if (!$foundPath) {
            echo "<span class='warning'>⚠️ Fichier non trouvé</span>\n";
            echo "   Chemins essayés:\n";
            foreach (array_slice(array_unique($possiblePaths), 0, 3) as $p) {
                echo "   - $p\n";
            }
            $failed++;
            flush();
            continue;
        }
        
        echo "   Fichier: $foundPath\n";
        
        // Déterminer le type MIME
        $mimeType = $doc['mime_type'] ?: mime_content_type($foundPath);
        echo "   MIME: $mimeType\n";
        
        // Extraire le texte selon le type
        $text = '';
        
        if (strpos($mimeType, 'pdf') !== false) {
            // PDF : utiliser pdftotext ou Ghostscript + Tesseract
            $text = extractTextFromPDF($foundPath, $tesseractPath, $gsPath);
        } elseif (strpos($mimeType, 'image') !== false) {
            // Image : Tesseract direct
            $text = extractTextFromImage($foundPath, $tesseractPath);
        } elseif (strpos($mimeType, 'text') !== false) {
            // Texte brut
            $text = file_get_contents($foundPath);
        }
        
        if ($text && strlen(trim($text)) > 0) {
            $text = trim($text);
            
            // Nettoyer le texte pour UTF-8 valide
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
            // Supprimer les caractères non-UTF8
            $text = iconv('UTF-8', 'UTF-8//IGNORE', $text);
            
            // Mettre à jour la base
            $stmt = $db->prepare("UPDATE documents SET content = ?, ocr_text = ? WHERE id = ?");
            $stmt->execute([$text, $text, $doc['id']]);
            
            echo "<span class='success'>   ✅ OCR réussi: " . strlen($text) . " caractères</span>\n";
            echo "   Aperçu: " . htmlspecialchars(substr($text, 0, 80)) . "...\n";
            $success++;
        } else {
            echo "<span class='warning'>   ⚠️ Aucun texte extrait</span>\n";
            $failed++;
        }
        
        flush();
    }
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "\n<span class='success'>✅ TERMINÉ</span>\n\n";
    echo "Réussis: $success\n";
    echo "Échecs: $failed\n";
    
    // Vérification finale
    $stats = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN content IS NOT NULL AND LENGTH(content) > 0 THEN 1 ELSE 0 END) as with_ocr
        FROM documents WHERE deleted_at IS NULL
    ")->fetch();
    
    echo "\n📊 Résultat final: {$stats['with_ocr']} / {$stats['total']} documents avec OCR\n";
    
} catch (Exception $e) {
    echo "<span class='error'>❌ ERREUR FATALE: " . $e->getMessage() . "</span>\n";
    echo $e->getTraceAsString();
}

echo "</pre>";
echo "<p><a href='/kdocs/diagnostic.php' style='color:#0af;'>→ Voir le diagnostic complet</a></p>";
echo "</body></html>";

// ============================================
// FONCTIONS D'EXTRACTION
// ============================================

function extractTextFromPDF(string $pdfPath, string $tesseractPath, string $gsPath): string
{
    $text = '';
    
    // Méthode 1: pdftotext (si disponible)
    $pdftotext = shell_exec("pdftotext -layout " . escapeshellarg($pdfPath) . " - 2>&1");
    if ($pdftotext && strlen(trim($pdftotext)) > 50 && strpos($pdftotext, 'not found') === false) {
        return $pdftotext;
    }
    
    // Méthode 2: Ghostscript + Tesseract
    $tempDir = sys_get_temp_dir() . '/kdocs_ocr_' . uniqid();
    @mkdir($tempDir);
    
    try {
        // Convertir PDF en images avec Ghostscript
        $gsCmd = sprintf(
            '"%s" -dNOPAUSE -dBATCH -sDEVICE=png16m -r300 -sOutputFile="%s/page_%%03d.png" "%s" 2>&1',
            $gsPath,
            $tempDir,
            $pdfPath
        );
        
        $gsOutput = shell_exec($gsCmd);
        
        // OCR chaque image
        $pages = glob($tempDir . '/page_*.png');
        sort($pages);
        
        foreach ($pages as $pagePath) {
            $pageText = extractTextFromImage($pagePath, $tesseractPath);
            if ($pageText) {
                $text .= $pageText . "\n\n";
            }
        }
    } finally {
        // Nettoyer
        array_map('unlink', glob($tempDir . '/*'));
        @rmdir($tempDir);
    }
    
    return $text;
}

function extractTextFromImage(string $imagePath, string $tesseractPath): string
{
    $tempOutput = sys_get_temp_dir() . '/tesseract_' . uniqid();
    
    $cmd = sprintf(
        '"%s" "%s" "%s" -l fra+eng 2>&1',
        $tesseractPath,
        $imagePath,
        $tempOutput
    );
    
    shell_exec($cmd);
    
    $text = '';
    if (file_exists($tempOutput . '.txt')) {
        $text = file_get_contents($tempOutput . '.txt');
        @unlink($tempOutput . '.txt');
    }
    
    return $text;
}

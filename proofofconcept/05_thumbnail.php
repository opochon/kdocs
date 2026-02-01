<?php
/**
 * 05_thumbnail.php
 * 
 * OBJECTIF: Générer miniature d'un document
 * 
 * ORIGINE: Logique inspirée de ThumbnailGenerator.php
 * CIBLE: Services/ThumbnailGenerator.php (amélioration après validation POC)
 * 
 * ENTRÉE: 
 *   - Chemin fichier source
 * 
 * SORTIE:
 *   - {thumbnail_path: string, method: string, success: bool}
 * 
 * CHAÎNE DE TRAITEMENT:
 *   DOCX/Office → LibreOffice → PDF → Ghostscript → JPG
 *   PDF → Ghostscript → JPG
 *   Image → GD resize → JPG
 * 
 * SIDE EFFECTS POTENTIELS:
 *   - Fichiers temporaires (nettoyés)
 */

require_once __DIR__ . '/helpers.php';

// ============================================
// CONSTANTES
// ============================================

const THUMB_WIDTH = 300;
const THUMB_HEIGHT = 400;

// ============================================
// FONCTIONS
// ============================================

/**
 * Génère miniature depuis PDF via Ghostscript
 */
function thumbnail_from_pdf(string $pdfPath, string $outputPath): bool {
    $cfg = poc_config();
    $gs = $cfg['tools']['ghostscript'];
    
    if (!file_exists($gs)) {
        poc_log("Ghostscript non trouvé: $gs", 'ERROR');
        return false;
    }
    
    $cmd = sprintf(
        '"%s" -dNOPAUSE -dBATCH -sDEVICE=jpeg -dJPEGQ=90 -r72 -dFirstPage=1 -dLastPage=1 -g%dx%d -dPDFFitPage -sOutputFile="%s" "%s"',
        $gs, THUMB_WIDTH, THUMB_HEIGHT, $outputPath, $pdfPath
    );
    
    poc_exec($cmd, $returnCode);
    
    if ($returnCode === 0 && file_exists($outputPath)) {
        poc_log("Miniature PDF créée: $outputPath");
        return true;
    }
    
    poc_log("Ghostscript échec: code $returnCode", 'ERROR');
    return false;
}

/**
 * Convertit Office en PDF via LibreOffice
 */
function office_to_pdf(string $officePath): ?string {
    $cfg = poc_config();
    $lo = $cfg['tools']['libreoffice'];
    
    if (!file_exists($lo)) {
        poc_log("LibreOffice non trouvé: $lo", 'ERROR');
        return null;
    }
    
    $tempDir = $cfg['poc']['output_dir'] . '/temp_' . uniqid();
    mkdir($tempDir, 0755, true);
    
    $cmd = sprintf(
        '"%s" --headless --convert-to pdf --outdir "%s" "%s"',
        $lo, $tempDir, $officePath
    );
    
    poc_log("LibreOffice: $cmd");
    $output = poc_exec($cmd, $returnCode);
    
    if ($returnCode !== 0) {
        poc_log("LibreOffice échec: $output", 'ERROR');
        @rmdir($tempDir);
        return null;
    }
    
    // Trouver le PDF généré
    $baseName = pathinfo($officePath, PATHINFO_FILENAME);
    $pdfPath = $tempDir . '/' . $baseName . '.pdf';
    
    if (!file_exists($pdfPath)) {
        // Chercher tout PDF
        $pdfs = glob($tempDir . '/*.pdf');
        if (!empty($pdfs)) {
            $pdfPath = $pdfs[0];
        } else {
            poc_log("PDF non trouvé après conversion", 'ERROR');
            @rmdir($tempDir);
            return null;
        }
    }
    
    poc_log("PDF créé: $pdfPath");
    return $pdfPath;
}

/**
 * Génère miniature depuis image via GD
 */
function thumbnail_from_image(string $imagePath, string $outputPath): bool {
    $info = @getimagesize($imagePath);
    if (!$info) {
        poc_log("Image non lisible: $imagePath", 'ERROR');
        return false;
    }
    
    $srcWidth = $info[0];
    $srcHeight = $info[1];
    $type = $info[2];
    
    // Charger image source
    $srcImg = null;
    switch ($type) {
        case IMAGETYPE_JPEG:
            $srcImg = @imagecreatefromjpeg($imagePath);
            break;
        case IMAGETYPE_PNG:
            $srcImg = @imagecreatefrompng($imagePath);
            break;
        case IMAGETYPE_GIF:
            $srcImg = @imagecreatefromgif($imagePath);
            break;
        case IMAGETYPE_WEBP:
            if (function_exists('imagecreatefromwebp')) {
                $srcImg = @imagecreatefromwebp($imagePath);
            }
            break;
        default:
            poc_log("Type image non supporté: $type", 'WARN');
            return false;
    }
    
    if (!$srcImg) {
        return false;
    }
    
    // Calculer dimensions
    $ratio = min(THUMB_WIDTH / $srcWidth, THUMB_HEIGHT / $srcHeight);
    $newWidth = (int)($srcWidth * $ratio);
    $newHeight = (int)($srcHeight * $ratio);
    
    // Créer miniature
    $thumbImg = imagecreatetruecolor($newWidth, $newHeight);
    $white = imagecolorallocate($thumbImg, 255, 255, 255);
    imagefill($thumbImg, 0, 0, $white);
    
    imagecopyresampled($thumbImg, $srcImg, 0, 0, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);
    
    $result = imagejpeg($thumbImg, $outputPath, 90);
    
    imagedestroy($srcImg);
    imagedestroy($thumbImg);
    
    if ($result) {
        poc_log("Miniature image créée: $outputPath");
    }
    
    return $result;
}

/**
 * Génère un placeholder pour formats non supportés
 */
function generate_placeholder(string $outputPath, string $extension): bool {
    $img = imagecreatetruecolor(THUMB_WIDTH, THUMB_HEIGHT);
    
    // Couleurs selon extension
    $colors = [
        'doc' => [41, 98, 255],
        'docx' => [41, 98, 255],
        'xls' => [33, 115, 70],
        'xlsx' => [33, 115, 70],
        'ppt' => [255, 87, 34],
        'pptx' => [255, 87, 34],
        'pdf' => [211, 47, 47],
        'default' => [100, 100, 100],
    ];
    
    $color = $colors[$extension] ?? $colors['default'];
    $bgColor = imagecolorallocate($img, 240, 240, 240);
    $fgColor = imagecolorallocate($img, $color[0], $color[1], $color[2]);
    $white = imagecolorallocate($img, 255, 255, 255);
    
    imagefill($img, 0, 0, $bgColor);
    
    // Icône document simplifiée
    $iconX = 75;
    $iconY = 80;
    $iconW = 150;
    $iconH = 180;
    
    // Rectangle blanc (page)
    imagefilledrectangle($img, $iconX, $iconY, $iconX + $iconW, $iconY + $iconH, $white);
    imagerectangle($img, $iconX, $iconY, $iconX + $iconW, $iconY + $iconH, $fgColor);
    
    // Lignes de texte simulées
    $lineColor = imagecolorallocate($img, 200, 200, 200);
    for ($i = 0; $i < 5; $i++) {
        $lineY = $iconY + 40 + ($i * 25);
        $lineW = ($i % 2 == 0) ? 100 : 70;
        imagefilledrectangle($img, $iconX + 25, $lineY, $iconX + 25 + $lineW, $lineY + 10, $lineColor);
    }
    
    // Badge extension
    $badgeY = THUMB_HEIGHT - 60;
    imagefilledrectangle($img, 0, $badgeY, THUMB_WIDTH, $badgeY + 40, $fgColor);
    
    // Texte extension
    $extText = strtoupper($extension);
    $textWidth = imagefontwidth(5) * strlen($extText);
    $textX = (THUMB_WIDTH - $textWidth) / 2;
    imagestring($img, 5, (int)$textX, $badgeY + 12, $extText, $white);
    
    $result = imagejpeg($img, $outputPath, 90);
    imagedestroy($img);
    
    if ($result) {
        poc_log("Placeholder créé: $outputPath");
    }
    
    return $result;
}

/**
 * Point d'entrée principal: génère miniature selon le type
 */
function generate_thumbnail(string $sourcePath, string $outputPath): array {
    $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
    
    poc_log("Génération miniature: $sourcePath ($ext)");
    
    $result = [
        'source' => $sourcePath,
        'output' => $outputPath,
        'extension' => $ext,
        'method' => 'none',
        'success' => false,
    ];
    
    $officeExts = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp'];
    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    if ($ext === 'pdf') {
        // PDF direct
        $result['success'] = thumbnail_from_pdf($sourcePath, $outputPath);
        $result['method'] = 'ghostscript';
        
    } elseif (in_array($ext, $officeExts)) {
        // Office → PDF → JPG
        $pdfPath = office_to_pdf($sourcePath);
        if ($pdfPath) {
            $result['success'] = thumbnail_from_pdf($pdfPath, $outputPath);
            $result['method'] = 'libreoffice+ghostscript';
            
            // Nettoyer PDF temporaire
            @unlink($pdfPath);
            @rmdir(dirname($pdfPath));
        }
        
        // Fallback placeholder si échec
        if (!$result['success']) {
            $result['success'] = generate_placeholder($outputPath, $ext);
            $result['method'] = 'placeholder';
        }
        
    } elseif (in_array($ext, $imageExts)) {
        // Image direct
        $result['success'] = thumbnail_from_image($sourcePath, $outputPath);
        $result['method'] = 'gd';
        
    } else {
        // Placeholder pour autres formats
        $result['success'] = generate_placeholder($outputPath, $ext);
        $result['method'] = 'placeholder';
    }
    
    return $result;
}

// ============================================
// EXÉCUTION (si appelé directement)
// ============================================

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($argv[0] ?? '')) {
    
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════╗\n";
    echo "║  POC 05 - GÉNÉRATION MINIATURES                              ║\n";
    echo "╚══════════════════════════════════════════════════════════════╝\n\n";
    
    $cfg = poc_config();
    
    // Vérifier outils
    echo "--- OUTILS ---\n";
    poc_result("Ghostscript", poc_tool_exists('ghostscript'), $cfg['tools']['ghostscript']);
    poc_result("LibreOffice", poc_tool_exists('libreoffice'), $cfg['tools']['libreoffice']);
    poc_result("GD extension", extension_loaded('gd'));
    
    // Test avec fichier passé en argument
    $testFile = $argv[1] ?? null;
    
    if ($testFile && file_exists($testFile)) {
        echo "\n--- TEST: $testFile ---\n";
        
        $outputPath = $cfg['poc']['output_dir'] . '/thumb_test_' . time() . '.jpg';
        $result = generate_thumbnail($testFile, $outputPath);
        
        poc_result("Génération", $result['success'], "Méthode: {$result['method']}");
        
        if ($result['success'] && file_exists($outputPath)) {
            echo "Miniature: $outputPath (" . filesize($outputPath) . " bytes)\n";
        }
        
        // Sauvegarder rapport
        $reportFile = $cfg['poc']['output_dir'] . '/05_thumbnail_result.json';
        file_put_contents($reportFile, json_encode($result, JSON_PRETTY_PRINT));
        echo "Rapport: $reportFile\n";
        
    } else {
        echo "\nUsage: php 05_thumbnail.php <chemin_fichier>\n";
        echo "Exemple: php 05_thumbnail.php samples/test.docx\n";
        
        // Test avec tous les samples si présents
        $samplesDir = $cfg['poc']['samples_dir'];
        if (is_dir($samplesDir)) {
            $files = glob($samplesDir . '/*.*');
            if (!empty($files)) {
                echo "\n--- TEST SAMPLES ---\n";
                foreach ($files as $file) {
                    $ext = pathinfo($file, PATHINFO_EXTENSION);
                    $outputPath = $cfg['poc']['output_dir'] . '/thumb_' . pathinfo($file, PATHINFO_FILENAME) . '.jpg';
                    $result = generate_thumbnail($file, $outputPath);
                    poc_result(basename($file), $result['success'], $result['method']);
                }
            }
        }
    }
    
    echo "\n✓ POC 05 terminé\n\n";
}

// Export pour chaînage
return 'generate_thumbnail';

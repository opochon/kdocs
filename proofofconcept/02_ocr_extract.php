<?php
/**
 * 02_ocr_extract.php - EXTRACTION HYBRIDE
 *
 * LOGIQUE:
 *   - Image (JPG/PNG/TIFF/GIF) → Tesseract OCR
 *   - PDF → Hybride (pdftotext natif, sinon OCR complet)
 *   - Office (DOCX, XLS, ODT...) → LibreOffice --convert-to txt
 *   - MSG Outlook → Extraction basique
 */

require_once __DIR__ . '/helpers.php';

// ============================================
// CONFIGURATION
// ============================================

$IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'tiff', 'tif', 'bmp', 'webp'];
$OFFICE_EXTENSIONS = ['docx', 'doc', 'odt', 'rtf'];
$SPREADSHEET_EXTENSIONS = ['xlsx', 'xls', 'ods', 'csv'];
$PRESENTATION_EXTENSIONS = ['pptx', 'ppt', 'odp'];

// ============================================
// FONCTIONS
// ============================================

/**
 * OCR via Tesseract (images uniquement)
 */
function ocr_tesseract(string $imagePath, string $lang = 'fra+eng'): ?array {
    $cfg = poc_config();
    $tesseract = $cfg['tools']['tesseract'];

    if (!file_exists($tesseract)) {
        poc_log("Tesseract non trouvé: $tesseract", 'ERROR');
        return null;
    }

    $tempBase = $cfg['poc']['output_dir'] . '/ocr_' . uniqid();
    $cmd = sprintf('"%s" "%s" "%s" -l %s 2>&1', $tesseract, $imagePath, $tempBase, $lang);

    poc_exec($cmd, $returnCode);

    $textFile = $tempBase . '.txt';
    if (!file_exists($textFile)) {
        poc_log("Tesseract échec: pas de fichier output", 'ERROR');
        return null;
    }

    $text = file_get_contents($textFile);
    $text = ensure_utf8($text); // FIX UTF-8
    @unlink($textFile);

    return [
        'text' => trim($text),
        'method' => 'tesseract',
        'confidence' => estimate_confidence($text),
    ];
}

/**
 * Extraction via LibreOffice (Office, ODT, etc.)
 */
function extract_libreoffice(string $filePath): ?array {
    $cfg = poc_config();
    $lo = $cfg['tools']['libreoffice'];

    if (!file_exists($lo)) {
        poc_log("LibreOffice non trouvé: $lo", 'ERROR');
        return null;
    }

    $tempDir = $cfg['poc']['output_dir'] . '/lo_' . uniqid();
    @mkdir($tempDir, 0755, true);

    $cmd = sprintf(
        '"%s" --headless --convert-to txt:Text --outdir "%s" "%s" 2>&1',
        $lo, $tempDir, $filePath
    );

    poc_log("LibreOffice: $cmd");
    $output = poc_exec($cmd, $returnCode);

    $baseName = pathinfo($filePath, PATHINFO_FILENAME);
    $txtPath = $tempDir . '/' . $baseName . '.txt';

    if (!file_exists($txtPath)) {
        $txts = glob($tempDir . '/*.txt');
        if (!empty($txts)) {
            $txtPath = $txts[0];
        } else {
            poc_log("LibreOffice: pas de TXT généré", 'WARN');
            @rmdir($tempDir);
            return null;
        }
    }

    $text = file_get_contents($txtPath);
    $text = ensure_utf8($text); // FIX UTF-8

    @unlink($txtPath);
    @rmdir($tempDir);

    return [
        'text' => trim($text),
        'method' => 'libreoffice',
        'confidence' => 1.0,
    ];
}

/**
 * Extraction PDF hybride :
 * 1. pdftotext (texte natif) - rapide
 * 2. Si vide → OCR via Ghostscript + Tesseract
 */
function extract_pdf_hybrid(string $pdfPath): array {
    $cfg = poc_config();

    // 1. Essayer pdftotext d'abord (rapide)
    $text = extract_pdf_native($pdfPath);

    if (!empty($text) && str_word_count($text) > 50) {
        poc_log("PDF texte natif: " . str_word_count($text) . " mots");
        return [
            'text' => $text,
            'method' => 'pdftotext',
            'confidence' => 1.0,
        ];
    }

    // 2. PDF scanné → OCR complet
    poc_log("PDF sans texte natif → OCR");
    return ocr_pdf_full($pdfPath);
}

/**
 * Extrait texte natif d'un PDF via pdftotext
 */
function extract_pdf_native(string $pdfPath): string {
    $cfg = poc_config();

    // Méthode 1: pdftotext si disponible
    $pdftotext = $cfg['tools']['pdftotext'] ?? '';
    if (file_exists($pdftotext)) {
        $tempFile = $cfg['poc']['output_dir'] . '/pdftext_' . uniqid() . '.txt';
        $cmd = sprintf('"%s" -enc UTF-8 "%s" "%s" 2>&1', $pdftotext, $pdfPath, $tempFile);
        exec($cmd);

        if (file_exists($tempFile)) {
            $text = file_get_contents($tempFile);
            @unlink($tempFile);
            return trim($text);
        }
    }

    // Méthode 2: LibreOffice (moins fiable pour PDF)
    $result = extract_libreoffice($pdfPath);
    return $result['text'] ?? '';
}

/**
 * OCR complet d'un PDF (toutes les pages)
 */
function ocr_pdf_full(string $pdfPath): array {
    $cfg = poc_config();
    $gs = $cfg['tools']['ghostscript'];
    $tesseract = $cfg['tools']['tesseract'];

    if (!file_exists($gs) || !file_exists($tesseract)) {
        poc_log("Outils OCR manquants (GS ou Tesseract)", 'ERROR');
        return ['text' => '', 'method' => 'none', 'confidence' => 0];
    }

    $tempDir = $cfg['poc']['output_dir'] . '/ocr_pdf_' . uniqid();
    @mkdir($tempDir, 0755, true);

    // Convertir PDF en images (toutes les pages)
    $imgPattern = "$tempDir/page_%03d.png";
    $cmd = sprintf(
        '"%s" -dNOPAUSE -dBATCH -dSAFER -sDEVICE=png16m -r200 -sOutputFile="%s" "%s" 2>&1',
        $gs, $imgPattern, $pdfPath
    );
    poc_log("Ghostscript: conversion PDF → images");
    exec($cmd);

    // OCR chaque image
    $images = glob("$tempDir/page_*.png");
    $fullText = '';
    $pageCount = count($images);

    if ($pageCount === 0) {
        poc_log("Ghostscript: aucune image générée", 'ERROR');
        @rmdir($tempDir);
        return ['text' => '', 'method' => 'none', 'confidence' => 0];
    }

    poc_log("OCR de $pageCount page(s)...");

    foreach ($images as $i => $img) {
        $pageNum = $i + 1;
        $txtBase = str_replace('.png', '', $img);

        $cmd = sprintf('"%s" "%s" "%s" -l fra+eng 2>&1', $tesseract, $img, $txtBase);
        exec($cmd);

        $txtFile = $txtBase . '.txt';
        if (file_exists($txtFile)) {
            $pageText = file_get_contents($txtFile);
            $pageText = ensure_utf8($pageText); // FIX UTF-8
            if ($pageCount > 1) {
                $fullText .= "--- Page $pageNum ---\n";
            }
            $fullText .= $pageText . "\n\n";
            @unlink($txtFile);
        }
        @unlink($img);
    }

    @rmdir($tempDir);

    $wordCount = str_word_count($fullText);
    poc_log("OCR terminé: $wordCount mots extraits");

    return [
        'text' => trim($fullText),
        'method' => 'ocr_pdf',
        'confidence' => estimate_confidence($fullText),
        'pages_ocr' => $pageCount,
    ];
}

/**
 * Extraction basique d'un fichier MSG Outlook
 * Gère UTF-16LE (format interne MSG)
 */
function extract_msg_basic(string $msgPath): array {
    $content = @file_get_contents($msgPath);
    if (!$content) {
        return ['text' => '', 'method' => 'msg_error', 'confidence' => 0];
    }

    // MSG utilise souvent UTF-16LE
    // Essayer de détecter et convertir
    if (substr($content, 0, 2) === "\xFF\xFE") {
        // UTF-16LE BOM
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16LE');
    }

    $text = '';

    // Extraire les chaînes lisibles
    // Pattern pour texte UTF-16LE inline (caractères alternés avec \x00)
    $utf16Pattern = '/(?:[\x20-\x7E][\x00]){4,}/';
    if (preg_match_all($utf16Pattern, $content, $matches)) {
        foreach ($matches[0] as $match) {
            $decoded = @mb_convert_encoding($match, 'UTF-8', 'UTF-16LE');
            if ($decoded && strlen($decoded) > 10) {
                $text .= $decoded . "\n";
            }
        }
    }

    // Extraire aussi le texte ASCII pur
    $asciiPattern = '/[\x20-\x7E]{20,}/';
    if (preg_match_all($asciiPattern, $content, $matches)) {
        foreach ($matches[0] as $match) {
            // Éviter les doublons
            if (strpos($text, $match) === false) {
                $text .= $match . "\n";
            }
        }
    }

    $text = ensure_utf8($text);

    // Si quasi vide, marquer comme non supporté
    if (str_word_count($text) < 10) {
        return [
            'text' => '',
            'method' => 'msg_unsupported',
            'confidence' => 0,
            'error' => 'Format MSG complexe - exporter en PDF depuis Outlook',
        ];
    }

    return [
        'text' => trim($text),
        'method' => 'msg_basic',
        'confidence' => 0.6,
    ];
}

/**
 * Extraction XLSX (format ZIP avec XML)
 * Équivalent à OCRService::extractTextFromXlsx()
 */
function extract_xlsx(string $filePath): ?array {
    if (!class_exists('ZipArchive')) {
        poc_log("ZipArchive non disponible", 'ERROR');
        return null;
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        poc_log("Impossible d'ouvrir le fichier XLSX", 'ERROR');
        return null;
    }

    $text = '';

    // Lire les chaînes partagées
    $sharedStrings = [];
    $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedStringsXml) {
        $xml = @simplexml_load_string($sharedStringsXml);
        if ($xml !== false) {
            foreach ($xml->si as $si) {
                $sharedStrings[] = (string)$si->t;
            }
        }
    }

    // Lire les feuilles (max 10)
    for ($i = 1; $i <= 10; $i++) {
        $sheetXml = $zip->getFromName("xl/worksheets/sheet$i.xml");
        if (!$sheetXml) break;

        $xml = @simplexml_load_string($sheetXml);
        if ($xml === false) continue;

        foreach ($xml->sheetData->row as $row) {
            $rowText = [];
            foreach ($row->c as $cell) {
                $value = '';
                if (isset($cell['t']) && (string)$cell['t'] === 's') {
                    // Référence aux chaînes partagées
                    $idx = (int)$cell->v;
                    $value = $sharedStrings[$idx] ?? '';
                } else {
                    $value = (string)$cell->v;
                }
                if (!empty($value)) {
                    $rowText[] = $value;
                }
            }
            if (!empty($rowText)) {
                $text .= implode(' | ', $rowText) . "\n";
            }
        }
    }

    $zip->close();

    $text = ensure_utf8(trim($text));

    if (empty($text)) {
        return null;
    }

    return [
        'text' => $text,
        'method' => 'xlsx_native',
        'confidence' => 1.0,
    ];
}

/**
 * Extraction PPTX (format ZIP avec XML)
 * Équivalent à OCRService::extractTextFromPptx()
 */
function extract_pptx(string $filePath): ?array {
    if (!class_exists('ZipArchive')) {
        poc_log("ZipArchive non disponible", 'ERROR');
        return null;
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        poc_log("Impossible d'ouvrir le fichier PPTX", 'ERROR');
        return null;
    }

    $text = '';

    // Parcourir les slides (max 100)
    for ($i = 1; $i <= 100; $i++) {
        $slideXml = $zip->getFromName("ppt/slides/slide$i.xml");
        if (!$slideXml) break;

        // Extraire le texte des balises <a:t>
        preg_match_all('/<a:t>([^<]*)<\/a:t>/i', $slideXml, $matches);
        if (!empty($matches[1])) {
            $text .= "--- Slide $i ---\n";
            $text .= implode(' ', $matches[1]) . "\n\n";
        }
    }

    $zip->close();

    $text = ensure_utf8(trim($text));

    if (empty($text)) {
        return null;
    }

    return [
        'text' => $text,
        'method' => 'pptx_native',
        'confidence' => 1.0,
    ];
}

/**
 * Extraction CSV
 */
function extract_csv(string $filePath): ?array {
    $content = @file_get_contents($filePath);
    if ($content === false) {
        return null;
    }

    $text = ensure_utf8($content);

    return [
        'text' => trim($text),
        'method' => 'csv_native',
        'confidence' => 1.0,
    ];
}

/**
 * Extraction DOCX native via ZipArchive (fallback si LibreOffice échoue)
 */
function extract_docx_native(string $filePath): ?array {
    if (!class_exists('ZipArchive')) {
        return null;
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return null;
    }

    $content = $zip->getFromName('word/document.xml');
    $zip->close();

    if (!$content) {
        return null;
    }

    // Extraire le texte des balises <w:t>
    preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/i', $content, $matches);

    if (empty($matches[1])) {
        return null;
    }

    $text = implode(' ', $matches[1]);
    $text = ensure_utf8(trim($text));

    if (empty($text)) {
        return null;
    }

    return [
        'text' => $text,
        'method' => 'docx_native',
        'confidence' => 1.0,
    ];
}

/**
 * Extraction fichier texte brut
 */
function extract_text_file(string $filePath): ?array {
    $content = @file_get_contents($filePath);
    if ($content === false) {
        return null;
    }

    $text = ensure_utf8($content);

    return [
        'text' => trim($text),
        'method' => 'text_native',
        'confidence' => 1.0,
    ];
}

/**
 * Estime la confiance (pour OCR)
 */
function estimate_confidence(string $text): float {
    if (empty($text)) return 0.0;

    $cleanChars = preg_match_all('/[a-zA-ZÀ-ÿ0-9\s.,;:!?\'"()\-]/', $text);
    $totalChars = mb_strlen($text);

    return $totalChars > 0 ? min(1.0, $cleanChars / $totalChars) : 0.0;
}

/**
 * Détection langue simple
 */
function detect_language(string $text): string {
    $sample = mb_strtolower(mb_substr($text, 0, 1000));

    $patterns = [
        'fra' => ['le ', 'la ', 'les ', 'de ', 'du ', 'des ', 'un ', 'une ', 'et ', 'est ', 'pour ', 'dans '],
        'deu' => ['der ', 'die ', 'das ', 'und ', 'ist ', 'von ', 'mit ', 'für ', 'auf ', 'den '],
        'eng' => ['the ', 'and ', 'is ', 'of ', 'to ', 'in ', 'for ', 'that ', 'with ', 'on '],
    ];

    $scores = [];
    foreach ($patterns as $lang => $words) {
        $scores[$lang] = 0;
        foreach ($words as $word) {
            $scores[$lang] += substr_count($sample, $word);
        }
    }

    arsort($scores);
    return array_key_first($scores);
}

/**
 * POINT D'ENTRÉE PRINCIPAL
 *
 * Image → Tesseract OCR
 * PDF → Hybride (pdftotext ou OCR)
 * DOCX → Native ZipArchive ou LibreOffice
 * XLSX → Native ZipArchive
 * PPTX → Native ZipArchive
 * CSV/TXT → Lecture directe
 * Office autres → LibreOffice
 * MSG → Extraction basique
 */
function extract_text(string $path): array {
    global $IMAGE_EXTENSIONS, $SPREADSHEET_EXTENSIONS, $PRESENTATION_EXTENSIONS;

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    poc_log("Extraction: $path ($ext)");

    $result = null;

    // ========== IMAGES ==========
    if (in_array($ext, $IMAGE_EXTENSIONS)) {
        poc_log("Type: Image → Tesseract OCR");
        $result = ocr_tesseract($path);
    }

    // ========== PDF ==========
    elseif ($ext === 'pdf') {
        poc_log("Type: PDF → Extraction hybride");
        $result = extract_pdf_hybrid($path);
    }

    // ========== DOCX (priorité native) ==========
    elseif ($ext === 'docx') {
        poc_log("Type: DOCX → Native ZipArchive");
        $result = extract_docx_native($path);
        // Fallback LibreOffice si échec
        if (!$result || empty($result['text'])) {
            poc_log("DOCX native échoué → LibreOffice");
            $result = extract_libreoffice($path);
        }
    }

    // ========== XLSX ==========
    elseif ($ext === 'xlsx') {
        poc_log("Type: XLSX → Native ZipArchive");
        $result = extract_xlsx($path);
        // Fallback LibreOffice si échec
        if (!$result || empty($result['text'])) {
            poc_log("XLSX native échoué → LibreOffice");
            $result = extract_libreoffice($path);
        }
    }

    // ========== PPTX ==========
    elseif ($ext === 'pptx') {
        poc_log("Type: PPTX → Native ZipArchive");
        $result = extract_pptx($path);
        // Fallback LibreOffice si échec
        if (!$result || empty($result['text'])) {
            poc_log("PPTX native échoué → LibreOffice");
            $result = extract_libreoffice($path);
        }
    }

    // ========== CSV ==========
    elseif ($ext === 'csv') {
        poc_log("Type: CSV → Lecture directe");
        $result = extract_csv($path);
    }

    // ========== TXT et fichiers texte ==========
    elseif (in_array($ext, ['txt', 'md', 'json', 'xml', 'html', 'htm'])) {
        poc_log("Type: Texte → Lecture directe");
        $result = extract_text_file($path);
    }

    // ========== MSG Outlook ==========
    elseif ($ext === 'msg') {
        poc_log("Type: MSG Outlook → Extraction basique");
        $result = extract_msg_basic($path);
    }

    // ========== Autres Office (ODS, ODT, XLS, PPT, DOC, etc.) ==========
    elseif (in_array($ext, ['doc', 'odt', 'rtf', 'xls', 'ods', 'ppt', 'odp'])) {
        poc_log("Type: Office legacy → LibreOffice");
        $result = extract_libreoffice($path);
    }

    // ========== FORMAT INCONNU ==========
    else {
        poc_log("Type: Format inconnu ($ext) → Tentative LibreOffice");
        $result = extract_libreoffice($path);
    }

    // ========== RÉSULTAT ==========
    if (!$result || empty($result['text'])) {
        return [
            'text' => '',
            'method' => $result['method'] ?? 'none',
            'confidence' => 0.0,
            'lang' => 'unknown',
            'error' => $result['error'] ?? 'Extraction échouée',
            'char_count' => 0,
            'word_count' => 0,
        ];
    }

    // Enrichir le résultat
    $result['lang'] = detect_language($result['text']);
    $result['char_count'] = mb_strlen($result['text']);
    $result['word_count'] = str_word_count($result['text']);

    poc_log("Extrait: {$result['word_count']} mots, langue: {$result['lang']}");

    return $result;
}

// ============================================
// EXÉCUTION (si appelé directement)
// ============================================

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($argv[0] ?? '')) {

    echo "\n";
    echo "================================================================\n";
    echo "  POC 02 - EXTRACTION HYBRIDE\n";
    echo "  Image → Tesseract | PDF → pdftotext/OCR | Office → LibreOffice\n";
    echo "================================================================\n\n";

    $cfg = poc_config();

    echo "--- OUTILS ---\n";
    poc_result("Tesseract", poc_tool_exists('tesseract'), $cfg['tools']['tesseract']);
    poc_result("Ghostscript", poc_tool_exists('ghostscript'), $cfg['tools']['ghostscript']);
    poc_result("LibreOffice", poc_tool_exists('libreoffice'), $cfg['tools']['libreoffice']);

    $hasPdftotext = !empty($cfg['tools']['pdftotext']) && file_exists($cfg['tools']['pdftotext']);
    poc_result("pdftotext", $hasPdftotext, $hasPdftotext ? $cfg['tools']['pdftotext'] : 'Non trouvé - fallback OCR');

    $testFile = $argv[1] ?? null;

    if ($testFile && file_exists($testFile)) {
        echo "\n--- TEST: $testFile ---\n";
        $result = extract_text($testFile);

        poc_result("Extraction", !empty($result['text']), $result['method']);
        echo "Confiance: " . round(($result['confidence'] ?? 0) * 100) . "%\n";
        echo "Langue: " . ($result['lang'] ?? '?') . "\n";
        echo "Mots: " . ($result['word_count'] ?? 0) . "\n";

        if (!empty($result['pages_ocr'])) {
            echo "Pages OCR: {$result['pages_ocr']}\n";
        }

        echo "\n--- EXTRAIT (500 premiers chars) ---\n";
        echo mb_substr($result['text'] ?? '', 0, 500);
        echo "\n...\n";

        $outputFile = $cfg['poc']['output_dir'] . '/02_ocr_result.json';
        file_put_contents($outputFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "\nRapport: $outputFile\n";
    } else {
        echo "\nUsage: php 02_ocr_extract.php <chemin_fichier>\n";
        echo "\nExemples:\n";
        echo "  php 02_ocr_extract.php samples/test.pdf    → PDF hybride\n";
        echo "  php 02_ocr_extract.php samples/test.docx   → LibreOffice\n";
        echo "  php 02_ocr_extract.php samples/test.jpg    → Tesseract\n";
        echo "  php 02_ocr_extract.php samples/test.msg    → MSG basique\n";
    }

    echo "\n";
}

return 'extract_text';

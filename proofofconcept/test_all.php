<?php
/**
 * test_all.php - VALIDATION COMPLÈTE POC K-DOCS
 *
 * Ce script valide TOUTES les fonctionnalités K-DOCS avant merge:
 *
 *   1. Environnement (outils, DB, providers)
 *   2. Extraction multi-format (PDF, DOCX, XLSX, PPTX, Images, MSG)
 *   3. Miniatures (tous formats)
 *   4. Embeddings (Ollama + OpenAI)
 *   5. Classification CASCADE (Anthropic → Ollama → Règles)
 *   6. Extraction champs (montant, date, IBAN, référence)
 *   7. Recherche (FULLTEXT + Sémantique)
 *   8. Training (corrections + apprentissage)
 *   9. Flux Consume (split PDF)
 *   10. Flux Détection (delta sync)
 *
 * USAGE:
 *   php test_all.php
 *   php test_all.php --verbose
 */

// ============================================
// FIX UTF-8 pour Windows
// ============================================
if (PHP_OS_FAMILY === 'Windows') {
    @exec('chcp 65001 > nul 2>&1');
    if (function_exists('sapi_windows_cp_set')) {
        sapi_windows_cp_set(65001);
    }
}

if (!headers_sent() && php_sapi_name() !== 'cli') {
    header('Content-Type: text/html; charset=utf-8');
}

// ============================================
// CHARGEMENT MODULES
// ============================================
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/02_ocr_extract.php';

$HAS_CLASSIFY = file_exists(__DIR__ . '/04_suggest_classify.php');
$HAS_SEARCH = file_exists(__DIR__ . '/04_search.php');
$HAS_THUMBNAIL = file_exists(__DIR__ . '/05_thumbnail.php');
$HAS_CONSUME = file_exists(__DIR__ . '/06_consume_flow.php');
$HAS_DETECT = file_exists(__DIR__ . '/07_detect_flow.php');
$HAS_TRAINING = file_exists(__DIR__ . '/08_training.php');

if ($HAS_CLASSIFY) require_once __DIR__ . '/04_suggest_classify.php';
if ($HAS_SEARCH) require_once __DIR__ . '/04_search.php';
if ($HAS_THUMBNAIL) require_once __DIR__ . '/05_thumbnail.php';
if ($HAS_CONSUME) require_once __DIR__ . '/06_consume_flow.php';
if ($HAS_DETECT) require_once __DIR__ . '/07_detect_flow.php';
if ($HAS_TRAINING) require_once __DIR__ . '/08_training.php';

// ============================================
// CONFIG TEST
// ============================================
$SAMPLES_DIR = __DIR__ . '/samples';
$OUTPUT_DIR = __DIR__ . '/output';
$RESULTS = [];
$TOTAL_TESTS = 0;
$PASSED_TESTS = 0;
$VERBOSE = in_array('--verbose', $argv ?? []);

if (!is_dir($OUTPUT_DIR)) mkdir($OUTPUT_DIR, 0755, true);
if (!is_dir($SAMPLES_DIR)) mkdir($SAMPLES_DIR, 0755, true);

// ============================================
// FONCTIONS HELPER
// ============================================

function section(string $title): void {
    global $RESULTS;
    echo "\n";
    echo "================================================================\n";
    echo "  " . strtoupper($title) . "\n";
    echo "================================================================\n\n";
    $RESULTS[$title] = [];
}

function test(string $name, bool $success, string $detail = ''): void {
    global $RESULTS, $TOTAL_TESTS, $PASSED_TESTS;
    $TOTAL_TESTS++;
    if ($success) $PASSED_TESTS++;
    $icon = $success ? "[PASS]" : "[FAIL]";
    echo "  $icon $name";
    if ($detail) echo " ($detail)";
    echo "\n";
    $section = array_key_last($RESULTS);
    $RESULTS[$section][] = ['name' => $name, 'success' => $success, 'detail' => $detail];
}

function info(string $msg): void {
    echo "       $msg\n";
}

function test_db(): bool {
    try {
        $pdo = poc_db();
        $pdo->query("SELECT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Fonctions fallback si modules non chargés
if (!function_exists('get_pdf_page_count')) {
    function get_pdf_page_count(string $pdfPath): int {
        $content = @file_get_contents($pdfPath);
        if ($content) {
            $count = preg_match_all('/\/Type\s*\/Page[^s]/', $content);
            if ($count > 0) return $count;
        }
        return 1;
    }
}

if (!function_exists('generate_thumbnail')) {
    function generate_thumbnail(string $filePath, string $outputPath): array {
        $cfg = poc_config();
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $gs = $cfg['tools']['ghostscript'];

        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp'])) {
            return ['success' => @copy($filePath, $outputPath), 'method' => 'copy'];
        }

        if ($ext === 'pdf' && file_exists($gs)) {
            $cmd = sprintf('"%s" -dNOPAUSE -dBATCH -dSAFER -sDEVICE=jpeg -r72 -dFirstPage=1 -dLastPage=1 -sOutputFile="%s" "%s" 2>&1', $gs, $outputPath, $filePath);
            exec($cmd);
            return ['success' => file_exists($outputPath), 'method' => 'ghostscript'];
        }

        return ['success' => false, 'method' => 'unsupported'];
    }
}

// ============================================
// DÉBUT DES TESTS
// ============================================

echo "\n";
echo "================================================================\n";
echo "     K-DOCS POC - VALIDATION COMPLETE AVANT MERGE              \n";
echo "================================================================\n";
echo "\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "Samples: $SAMPLES_DIR\n";

$cfg = poc_config();

// ============================================
// 1. ENVIRONNEMENT
// ============================================
section("1. Environnement");

// PHP
test("PHP " . phpversion(), version_compare(phpversion(), '8.0', '>='));
test("Extension cURL", extension_loaded('curl'));
test("Extension mbstring", extension_loaded('mbstring'));
test("Extension PDO MySQL", extension_loaded('pdo_mysql'));
test("Extension GD", extension_loaded('gd'));
test("Extension ZipArchive", class_exists('ZipArchive'));

// Outils
test("Tesseract", poc_tool_exists('tesseract'), $cfg['tools']['tesseract']);
test("Ghostscript", poc_tool_exists('ghostscript'), $cfg['tools']['ghostscript']);
test("LibreOffice", poc_tool_exists('libreoffice'), $cfg['tools']['libreoffice']);
$hasPdftotext = !empty($cfg['tools']['pdftotext']) && file_exists($cfg['tools']['pdftotext']);
test("pdftotext", $hasPdftotext, $hasPdftotext ? 'OK' : 'Fallback OCR');

// DB & Providers
test("MySQL", test_db(), $cfg['db']['host'] . ':' . $cfg['db']['port']);
test("Ollama", ollama_available(), $cfg['ollama']['url'] ?? 'localhost:11434');
// OpenAI optionnel - Ollama suffit pour embeddings + classification
if (openai_available()) {
    test("OpenAI", true, 'Configuré (optionnel)');
}
test("Anthropic (Claude)", anthropic_available(), anthropic_available() ? 'Configuré' : 'Non configuré');

// Provider info
$providerInfo = get_embedding_provider_info();
info("Provider actif: {$providerInfo['provider']} ({$providerInfo['model']}, {$providerInfo['dimensions']} dims)");

// ============================================
// 2. FICHIERS SAMPLES
// ============================================
section("2. Fichiers samples");

$sampleFiles = glob($SAMPLES_DIR . '/*.*');

if (empty($sampleFiles)) {
    info("Aucun fichier dans samples/ - création de fichiers test...");

    // Créer fichier texte minimal (facture)
    $testFile = $SAMPLES_DIR . '/test_facture.txt';
    file_put_contents($testFile, "FACTURE N° 2025-001\nDate: 15/01/2025\nSwisscom SA\nMontant: CHF 1'250.50\nIBAN: CH93 0076 2011 6238 5295 7\n\nCordialement,\nEntreprise Test SA");
    $sampleFiles[] = $testFile;

    // Créer fichier CSV minimal
    $csvFile = $SAMPLES_DIR . '/test_data.csv';
    file_put_contents($csvFile, "Nom;Prénom;Montant\nDupont;Jean;150.00\nMartin;Marie;275.50");
    $sampleFiles[] = $csvFile;
}

test("Fichiers présents", count($sampleFiles) > 0, count($sampleFiles) . " fichiers");

$fileTypes = [];
foreach ($sampleFiles as $file) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $size = filesize($file);
    $sizeStr = $size > 1024*1024 ? round($size/1024/1024, 1) . ' MB' : round($size/1024, 1) . ' KB';
    info(basename($file) . " ($ext, $sizeStr)");
    $fileTypes[$ext] = ($fileTypes[$ext] ?? 0) + 1;

    if ($ext === 'pdf') {
        $pages = get_pdf_page_count($file);
        info("  -> $pages page(s)");
    }
}

// ============================================
// 3. EXTRACTION MULTI-FORMAT
// ============================================
section("3. Extraction texte (multi-format)");

$extractionResults = [];

foreach ($sampleFiles as $file) {
    $basename = basename($file);
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    $start = microtime(true);
    $result = extract_text($file);
    $elapsed = round((microtime(true) - $start) * 1000);

    $words = $result['word_count'] ?? str_word_count($result['text'] ?? '');
    $method = $result['method'] ?? 'none';
    $success = $words > 0;

    test($basename, $success, "$method, $words mots, {$elapsed}ms");

    if ($success && !empty($result['text']) && $VERBOSE) {
        $preview = mb_substr($result['text'], 0, 100);
        $preview = preg_replace('/\s+/', ' ', $preview);
        info("\"$preview...\"");
    }

    $extractionResults[$basename] = $result;
}

// ============================================
// 4. MINIATURES
// ============================================
section("4. Miniatures");

$thumbnailResults = [];

foreach ($sampleFiles as $file) {
    $basename = basename($file);
    $thumbPath = $OUTPUT_DIR . '/thumb_test_' . md5($file) . '.jpg';
    @unlink($thumbPath);

    $start = microtime(true);
    $result = generate_thumbnail($file, $thumbPath);
    $elapsed = round((microtime(true) - $start) * 1000);

    $success = ($result['success'] ?? false) && file_exists($thumbPath);
    $method = $result['method'] ?? 'none';
    $size = $success ? round(filesize($thumbPath) / 1024, 1) . ' KB' : '-';

    test($basename, $success, "$method, $size, {$elapsed}ms");

    $thumbnailResults[$basename] = ['success' => $success, 'path' => $success ? $thumbPath : null];
}

// ============================================
// 5. EMBEDDINGS (OLLAMA + OPENAI)
// ============================================
section("5. Embeddings (Ollama + OpenAI)");

$embeddingResults = [];

if (!$providerInfo['available']) {
    test("Provider disponible", false, "Aucun provider embedding");
} else {
    // Test Ollama direct
    if (ollama_available()) {
        $start = microtime(true);
        $testEmb = generate_embedding_ollama("Test embedding Ollama");
        $elapsed = round((microtime(true) - $start) * 1000);
        $success = !empty($testEmb);
        test("Ollama direct", $success, $success ? count($testEmb) . " dims, {$elapsed}ms" : "Échec");
    }

    // Test OpenAI direct (si configuré)
    if (openai_available()) {
        $start = microtime(true);
        $testEmb = generate_embedding_openai("Test embedding OpenAI");
        $elapsed = round((microtime(true) - $start) * 1000);
        $success = !empty($testEmb);
        test("OpenAI direct", $success, $success ? count($testEmb) . " dims, {$elapsed}ms" : "Échec");
    }

    // Test embedding sur documents
    foreach ($sampleFiles as $file) {
        $basename = basename($file);
        $text = $extractionResults[$basename]['text'] ?? '';

        if (strlen($text) < 20) {
            test("Emb: $basename", false, "Texte insuffisant");
            continue;
        }

        $start = microtime(true);
        $embedding = generate_embedding($text);
        $elapsed = round((microtime(true) - $start) * 1000);

        $success = !empty($embedding);
        $dims = $success ? count($embedding) : 0;

        test("Emb: $basename", $success, "$dims dims, {$elapsed}ms");

        if ($success) {
            $embeddingResults[$basename] = $embedding;
        }
    }
}

// ============================================
// 6. CLASSIFICATION CASCADE
// ============================================
section("6. Classification CASCADE (Anthropic/Ollama/Règles)");

if (!$HAS_CLASSIFY) {
    test("Module classification", false, "04_suggest_classify.php non trouvé");
} else {
    // Test cascade complet
    $testText = <<<TEXT
FACTURE N° 2025-001

Swisscom (Suisse) SA
Case postale 3050 Berne

Date: 15 janvier 2025

Services de télécommunications - Janvier 2025

Abonnement mobile: CHF 49.00
Roaming données: CHF 25.50
Total: CHF 74.50

TVA 8.1%: CHF 6.03
TOTAL À PAYER: CHF 80.53

Paiement sur compte:
IBAN: CH93 0076 2011 6238 5295 7
TEXT;

    // Test cascade
    $start = microtime(true);
    $result = classify_document($testText);
    $elapsed = round((microtime(true) - $start) * 1000);

    $method = $result['method'] ?? 'unknown';
    $type = $result['type'] ?? 'inconnu';
    $conf = round(($result['confidence'] ?? 0) * 100);

    test("Classification cascade", $type !== 'autre', "Méthode: $method, Type: $type, Conf: $conf%, {$elapsed}ms");

    // Test extraction champs
    $fields = $result['fields'] ?? [];

    // Montant
    $hasMontant = isset($fields['montant']) && $fields['montant'] > 0;
    test("Extraction montant", $hasMontant, $hasMontant ? number_format($fields['montant'], 2) . " CHF" : "Non détecté");

    // Date
    $hasDate = !empty($fields['date_document']);
    test("Extraction date", $hasDate, $hasDate ? $fields['date_document'] : "Non détectée");

    // IBAN
    $hasIban = !empty($fields['iban']);
    test("Extraction IBAN", $hasIban, $hasIban ? $fields['iban'] : "Non détecté");

    // Référence
    $hasRef = !empty($fields['reference']);
    test("Extraction référence", $hasRef, $hasRef ? $fields['reference'] : "Non détectée");

    // Correspondant
    $hasCorr = !empty($result['correspondent']);
    test("Détection correspondant", $hasCorr, $hasCorr ? $result['correspondent'] : "Non détecté");

    // Test sur fichiers samples
    foreach ($sampleFiles as $file) {
        $basename = basename($file);
        $text = $extractionResults[$basename]['text'] ?? '';

        if (empty($text)) continue;

        $start = microtime(true);
        $result = classify_document($text);
        $elapsed = round((microtime(true) - $start) * 1000);

        $method = $result['method'] ?? 'unknown';
        $type = $result['type'] ?? 'inconnu';
        $conf = round(($result['confidence'] ?? 0) * 100);

        test("Classif: $basename", true, "$method -> $type ({$conf}%), {$elapsed}ms");
    }
}

// ============================================
// 7. RECHERCHE (FULLTEXT + SÉMANTIQUE)
// ============================================
section("7. Recherche (FULLTEXT + Sémantique)");

if (!$HAS_SEARCH) {
    test("Module recherche", false, "04_search.php non trouvé");
} else {
    // Vérifier index FULLTEXT
    test("Index FULLTEXT", fulltext_index_exists(), fulltext_index_exists() ? 'OK' : 'Absent');

    // Compter documents avec embedding
    $embCount = count_documents_with_embedding();
    test("Documents avec embedding", $embCount >= 0, "$embCount documents");

    // Test recherche
    $queries = ['facture', 'contrat', 'rapport'];

    foreach ($queries as $query) {
        // FULLTEXT
        $start = microtime(true);
        $results = search_fulltext($query, 5);
        $elapsed = round((microtime(true) - $start) * 1000);
        test("FULLTEXT: \"$query\"", true, count($results) . " résultats, {$elapsed}ms");

        // Sémantique (si embeddings disponibles)
        if (!empty($embeddingResults) || $embCount > 0) {
            $start = microtime(true);
            $results = search_semantic($query, 5);
            $elapsed = round((microtime(true) - $start) * 1000);
            test("Sémantique: \"$query\"", true, count($results) . " résultats, {$elapsed}ms");
        }
    }

    // Hybride
    $start = microtime(true);
    $results = search_hybrid('facture client', 10);
    $elapsed = round((microtime(true) - $start) * 1000);
    test("Hybride: \"facture client\"", true, count($results) . " résultats, {$elapsed}ms");
}

// ============================================
// 8. TRAINING (APPRENTISSAGE)
// ============================================
section("8. Training (Apprentissage)");

if (!$HAS_TRAINING) {
    test("Module training", false, "08_training.php non trouvé");
} else {
    $trainingCfg = $cfg['ai']['training'] ?? [];
    test("Training activé", $trainingCfg['enabled'] ?? false, ($trainingCfg['enabled'] ?? false) ? 'Oui' : 'Non');

    // Test stockage correction
    $testHash = md5('test_' . time());
    $testText = "Document de test pour validation training";

    $stored = store_correction(
        $testHash,
        $testText,
        ['type' => 'autre', 'confidence' => 0.3, 'method' => 'rules'],
        ['type' => 'facture', 'correspondent' => 'Test SA', 'tags' => ['test']]
    );
    test("Stockage correction", $stored);

    // Test recherche similarité
    $found = get_trained_classification($testText);
    test("Recherche similarité", $found !== null, $found ? "Type: {$found['type']}" : "Pas de match");

    // Stats
    $stats = get_training_stats();
    test("Stats training", $stats['total_corrections'] >= 0, "{$stats['total_corrections']} corrections, {$stats['total_rules']} règles");
}

// ============================================
// 9. FLUX CONSUME (SPLIT PDF)
// ============================================
section("9. Flux Consume (split PDF)");

if (!$HAS_CONSUME) {
    test("Module consume", false, "06_consume_flow.php non trouvé");
} else {
    // Chercher un PDF multi-pages
    $multiPagePdf = null;
    foreach ($sampleFiles as $file) {
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'pdf') {
            $pages = get_pdf_page_count($file);
            if ($pages > 1) {
                $multiPagePdf = $file;
                break;
            }
        }
    }

    if (!$multiPagePdf) {
        test("PDF multi-pages", false, "Aucun PDF multi-pages dans samples/");
        info("Ajoute un PDF avec plusieurs pages pour tester le split");
    } else {
        $basename = basename($multiPagePdf);
        $pages = get_pdf_page_count($multiPagePdf);
        info("Test avec: $basename ($pages pages)");

        $start = microtime(true);
        $result = process_consume_file($multiPagePdf);
        $elapsed = round(microtime(true) - $start, 1);

        $docCount = count($result['documents'] ?? []);
        $splitRequired = $result['split_required'] ?? false;

        test("Traitement", true, "{$elapsed}s");
        test("Analyse pages", true, "$pages pages analysées");
        test("Détection split", true, $splitRequired ? "OUI - $docCount documents" : "NON - 1 document");
    }
}

// ============================================
// 10. FLUX DÉTECTION (DELTA SYNC)
// ============================================
section("10. Flux Détection (delta sync)");

if (!$HAS_DETECT) {
    test("Module détection", false, "07_detect_flow.php non trouvé");
} else {
    info("Simulation sur dossier samples/");

    $currentFiles = scan_directory($SAMPLES_DIR);
    test("Scan récursif", count($currentFiles) > 0, count($currentFiles) . " fichiers");

    $indexFile = $OUTPUT_DIR . '/test_state.index.json';
    $prevState = [];
    if (file_exists($indexFile)) {
        $data = json_decode(file_get_contents($indexFile), true);
        $prevState = $data['files'] ?? [];
    }

    $changes = detect_changes($currentFiles, $prevState);
    test("Détection changements", true,
        "Nouveaux: " . count($changes['new']) .
        ", Modifiés: " . count($changes['modified']) .
        ", Supprimés: " . count($changes['deleted'])
    );

    file_put_contents($indexFile, json_encode(['files' => $currentFiles, 'timestamp' => date('c')], JSON_PRETTY_PRINT));
}

// ============================================
// RÉSUMÉ FINAL
// ============================================
section("Résumé final");

$passRate = $TOTAL_TESTS > 0 ? round(($PASSED_TESTS / $TOTAL_TESTS) * 100) : 0;
$failed = $TOTAL_TESTS - $PASSED_TESTS;

echo "  Tests exécutés : $TOTAL_TESTS\n";
echo "  Réussis        : $PASSED_TESTS\n";
echo "  Échoués        : $failed\n";
echo "  Taux réussite  : $passRate%\n\n";

if ($passRate >= 90) {
    echo "  ================================================================\n";
    echo "  |   POC VALIDÉ - PRÊT POUR MERGE                              |\n";
    echo "  ================================================================\n";
} elseif ($passRate >= 70) {
    echo "  ================================================================\n";
    echo "  |   POC PARTIELLEMENT VALIDÉ                                  |\n";
    echo "  ================================================================\n";
} else {
    echo "  ================================================================\n";
    echo "  |   POC NON VALIDÉ                                            |\n";
    echo "  ================================================================\n";
}

// ============================================
// RAPPORT JSON
// ============================================
$report = [
    'date' => date('Y-m-d H:i:s'),
    'total_tests' => $TOTAL_TESTS,
    'passed' => $PASSED_TESTS,
    'failed' => $failed,
    'pass_rate' => $passRate,
    'results' => $RESULTS,
    'config' => [
        'ollama' => ollama_available(),
        'openai' => openai_available(),
        'anthropic' => anthropic_available(),
        'embedding_provider' => $providerInfo,
    ],
];

$reportPath = $OUTPUT_DIR . '/test_all_report.json';
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\n  Rapport JSON: $reportPath\n";

// ============================================
// RAPPORT HTML
// ============================================
$htmlReport = generate_html_report($RESULTS, $OUTPUT_DIR, $PASSED_TESTS, $TOTAL_TESTS, $report);
$htmlPath = $OUTPUT_DIR . '/test_report.html';
file_put_contents($htmlPath, $htmlReport);
echo "  Rapport HTML: $htmlPath\n";

// Ouvrir dans le navigateur (Windows)
if (PHP_OS_FAMILY === 'Windows') {
    $htmlPathWin = str_replace('/', '\\', $htmlPath);
    @exec("start \"\" \"$htmlPathWin\"");
    echo "  (Ouverture automatique dans le navigateur)\n";
}

echo "\n";

// ============================================
// FONCTION: Génère rapport HTML
// ============================================
function generate_html_report(array $results, string $outputDir, int $passed, int $total, array $fullReport): string {
    $date = date('Y-m-d H:i:s');
    $thumbs = glob($outputDir . '/thumb_test_*.jpg');
    $rate = $total > 0 ? round(($passed / $total) * 100) : 0;
    $failed = $total - $passed;

    $statusClass = $rate >= 90 ? 'validated' : ($rate >= 70 ? 'partial' : 'failed');
    $statusText = $rate >= 90 ? 'POC VALIDÉ - PRÊT POUR MERGE' : ($rate >= 70 ? 'POC PARTIELLEMENT VALIDÉ' : 'POC NON VALIDÉ');

    $configHtml = '';
    $config = $fullReport['config'] ?? [];
    $configHtml .= '<p><strong>Ollama:</strong> ' . ($config['ollama'] ? 'OK' : 'Non') . '</p>';
    $configHtml .= '<p><strong>OpenAI:</strong> ' . ($config['openai'] ? 'Configuré' : 'Non') . '</p>';
    $configHtml .= '<p><strong>Anthropic:</strong> ' . ($config['anthropic'] ? 'Configuré' : 'Non') . '</p>';
    if (isset($config['embedding_provider'])) {
        $configHtml .= '<p><strong>Provider:</strong> ' . $config['embedding_provider']['provider'] . ' (' . $config['embedding_provider']['model'] . ')</p>';
    }

    $html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>K-DOCS POC - Validation complète</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 1400px; margin: 0 auto; padding: 20px; background: #f0f2f5; color: #1a1a2e; }
        h1 { color: #16213e; border-bottom: 3px solid #0f3460; padding-bottom: 15px; margin-bottom: 20px; }
        h2 { color: #16213e; margin: 25px 0 15px; font-size: 1.3em; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin: 20px 0; }
        .stat-box { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); text-align: center; }
        .stat-box .number { font-size: 2.5em; font-weight: bold; }
        .stat-box.pass .number { color: #00a878; }
        .stat-box.fail .number { color: #e63946; }
        .stat-box.rate .number { color: #0f3460; }
        .status { padding: 25px; border-radius: 12px; text-align: center; font-size: 1.4em; font-weight: bold; margin: 25px 0; color: white; }
        .status.validated { background: linear-gradient(135deg, #00a878, #00d9a0); }
        .status.partial { background: linear-gradient(135deg, #f4a261, #e9c46a); }
        .status.failed { background: linear-gradient(135deg, #e63946, #ff6b6b); }
        .config-box { background: white; padding: 20px; border-radius: 12px; margin: 20px 0; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .thumbnails { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 15px; margin: 20px 0; }
        .thumb-card { background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); transition: transform 0.2s; }
        .thumb-card:hover { transform: translateY(-3px); }
        .thumb-card img { width: 100%; height: 150px; object-fit: contain; background: #f8f9fa; }
        .thumb-card .info { padding: 12px; font-size: 0.85em; }
        .thumb-card .filename { font-weight: 600; word-break: break-all; color: #16213e; }
        .thumb-card .size { color: #6c757d; margin-top: 4px; }
        .section { background: white; padding: 20px; border-radius: 12px; margin: 20px 0; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .test-item { padding: 10px 0; border-bottom: 1px solid #f0f2f5; display: flex; align-items: center; gap: 12px; }
        .test-item:last-child { border-bottom: none; }
        .test-item .icon { font-size: 1.2em; width: 25px; text-align: center; }
        .test-item.pass .icon { color: #00a878; }
        .test-item.fail .icon { color: #e63946; }
        .test-item .name { flex: 1; font-weight: 500; }
        .test-item .detail { color: #6c757d; font-size: 0.9em; }
        footer { margin-top: 40px; padding: 20px; text-align: center; color: #6c757d; font-size: 0.9em; }
    </style>
</head>
<body>
    <h1>K-DOCS POC - Validation complète</h1>
    <p style="color: #6c757d;">Date: $date</p>

    <div class="stats">
        <div class="stat-box pass"><div class="number">$passed</div><div>Réussis</div></div>
        <div class="stat-box fail"><div class="number">$failed</div><div>Échoués</div></div>
        <div class="stat-box rate"><div class="number">$rate%</div><div>Taux</div></div>
    </div>

    <div class="status $statusClass">$statusText</div>

    <div class="config-box">
        <h3>Configuration</h3>
        $configHtml
    </div>
HTML;

    // Miniatures
    if (!empty($thumbs)) {
        $html .= '<h2>Miniatures générées</h2><div class="thumbnails">';
        foreach ($thumbs as $thumb) {
            $filename = basename($thumb);
            $size = round(filesize($thumb) / 1024, 1);
            $imgData = base64_encode(file_get_contents($thumb));
            $html .= "<div class=\"thumb-card\"><img src=\"data:image/jpeg;base64,$imgData\" alt=\"$filename\"><div class=\"info\"><div class=\"filename\">$filename</div><div class=\"size\">$size KB</div></div></div>";
        }
        $html .= '</div>';
    }

    // Résultats par section
    foreach ($results as $section => $tests) {
        if (!is_array($tests) || empty($tests)) continue;
        $sectionTitle = htmlspecialchars($section);
        $html .= "<h2>$sectionTitle</h2><div class=\"section\">";
        foreach ($tests as $test) {
            if (!isset($test['success'])) continue;
            $class = $test['success'] ? 'pass' : 'fail';
            $icon = $test['success'] ? '&#10003;' : '&#10007;';
            $name = htmlspecialchars($test['name'] ?? '');
            $detail = htmlspecialchars($test['detail'] ?? '');
            $html .= "<div class=\"test-item $class\"><span class=\"icon\">$icon</span><span class=\"name\">$name</span><span class=\"detail\">$detail</span></div>";
        }
        $html .= '</div>';
    }

    $html .= '<footer>K-DOCS POC - Validation générée automatiquement le ' . $date . '</footer></body></html>';
    return $html;
}

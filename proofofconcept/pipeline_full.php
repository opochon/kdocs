<?php
/**
 * pipeline_full.php
 * 
 * OBJECTIF: Exécuter le pipeline complet sur un fichier
 * 
 * USAGE:
 *   php pipeline_full.php <fichier>
 *   php pipeline_full.php --scan
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/02_ocr_extract.php';
require_once __DIR__ . '/03_semantic_embed.php';
require_once __DIR__ . '/04_suggest_classify.php';
require_once __DIR__ . '/05_thumbnail.php';

function process_file(string $filePath): array {
    $cfg = poc_config();
    
    poc_log("PIPELINE: " . basename($filePath));
    
    $result = ['file' => $filePath, 'success' => true, 'steps' => []];
    $meta = poc_file_meta($filePath);
    
    if (!$meta) {
        return ['success' => false, 'error' => 'Fichier non trouvé'];
    }
    
    // 1. OCR
    $extraction = extract_text($filePath);
    $result['steps']['ocr'] = ['success' => !empty($extraction['text']), 'method' => $extraction['method']];
    
    // 2. Embedding
    if (ollama_available() && !empty($extraction['text'])) {
        $embedding = generate_embedding($extraction['text']);
        $result['steps']['embedding'] = ['success' => $embedding !== null];
    }
    
    // 3. Classification
    $classification = suggest_classification($extraction['text'] ?? '', $meta['filename']);
    $result['steps']['classification'] = $classification;
    
    // 4. Thumbnail
    $thumbPath = $cfg['poc']['output_dir'] . '/thumb_' . md5($filePath) . '.jpg';
    $thumbnail = generate_thumbnail($filePath, $thumbPath);
    $result['steps']['thumbnail'] = ['success' => $thumbnail['success'], 'method' => $thumbnail['method']];
    
    return $result;
}

// Exécution
if (php_sapi_name() === 'cli') {
    $arg = $argv[1] ?? null;
    
    if ($arg && file_exists($arg)) {
        $result = process_file($arg);
        print_r($result);
    } else {
        echo "Usage: php pipeline_full.php <fichier>\n";
    }
}

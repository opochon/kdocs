<?php
/**
 * 07_detect_flow.php - FLUX DÉTECTION (indexation auto)
 *
 * Surveille storage/documents, détecte nouveau/modifié, indexe automatiquement
 *
 * LOGIQUE:
 *   1. Scanner récursivement storage/documents
 *   2. Comparer avec état précédent (hash + mtime)
 *   3. Pour chaque fichier nouveau/modifié:
 *      - Extraire texte (LibreOffice ou Tesseract)
 *      - Générer embedding sémantique
 *      - Auto-tagger (règles + similarité)
 *      - Générer miniature
 *   4. Sauvegarder en DB (si dry_run = false)
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/02_ocr_extract.php';

// ============================================
// CONSTANTES
// ============================================

const SUPPORTED_EXTENSIONS = [
    'pdf', 'doc', 'docx', 'odt', 'rtf',
    'xls', 'xlsx', 'ods',
    'ppt', 'pptx', 'odp',
    'txt', 'csv',
    'jpg', 'jpeg', 'png', 'gif', 'tiff', 'tif', 'bmp',
];

// ============================================
// SCAN ET DÉTECTION
// ============================================

/**
 * Scanne un répertoire récursivement
 */
function scan_directory(string $path, array $extensions = []): array {
    if (!is_dir($path)) {
        poc_log("Répertoire non trouvé: $path", 'ERROR');
        return [];
    }

    $files = [];
    $extensions = array_map('strtolower', $extensions ?: SUPPORTED_EXTENSIONS);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;

        $ext = strtolower($file->getExtension());
        if (!in_array($ext, $extensions)) continue;

        // Ignorer fichiers cachés et .index
        $filename = $file->getFilename();
        if (str_starts_with($filename, '.')) continue;

        $fullPath = $file->getPathname();
        $relativePath = str_replace($path . DIRECTORY_SEPARATOR, '', $fullPath);
        $relativePath = str_replace($path . '/', '', $relativePath);

        $files[$relativePath] = [
            'path' => $fullPath,
            'relative' => $relativePath,
            'filename' => $filename,
            'extension' => $ext,
            'size' => $file->getSize(),
            'mtime' => $file->getMTime(),
            'hash' => md5_file($fullPath),
        ];
    }

    return $files;
}

/**
 * Charge l'état précédent
 */
function load_previous_state(string $indexFile): array {
    if (!file_exists($indexFile)) {
        return [];
    }

    $content = file_get_contents($indexFile);
    $data = json_decode($content, true);

    return $data['files'] ?? [];
}

/**
 * Sauvegarde l'état actuel
 */
function save_current_state(string $indexFile, array $files): void {
    $data = [
        'timestamp' => date('Y-m-d H:i:s'),
        'count' => count($files),
        'files' => $files,
    ];

    file_put_contents($indexFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * Détecte les changements entre deux états
 */
function detect_changes(array $current, array $previous): array {
    $changes = [
        'new' => [],
        'modified' => [],
        'deleted' => [],
        'unchanged' => [],
    ];

    // Fichiers nouveaux ou modifiés
    foreach ($current as $path => $file) {
        if (!isset($previous[$path])) {
            $changes['new'][] = $file;
        } elseif ($previous[$path]['hash'] !== $file['hash']) {
            $changes['modified'][] = $file;
        } else {
            $changes['unchanged'][] = $file;
        }
    }

    // Fichiers supprimés
    foreach ($previous as $path => $file) {
        if (!isset($current[$path])) {
            $changes['deleted'][] = $file;
        }
    }

    return $changes;
}

// ============================================
// COMPARAISON AVEC DB
// ============================================

/**
 * Compare les fichiers avec la base de données
 */
function compare_with_database(array $files): array {
    $db = poc_db();

    $result = [
        'to_index' => [],      // Dans FS, pas en DB
        'orphans' => [],       // En DB, pas dans FS
        'to_reindex' => [],    // Hash différent
        'synced' => [],        // OK
    ];

    // Récupérer tous les documents de la DB
    $stmt = $db->query("SELECT id, path, file_path, file_hash, original_filename FROM documents WHERE deleted_at IS NULL");
    $dbDocs = [];
    while ($row = $stmt->fetch()) {
        $path = $row['path'] ?: $row['file_path'];
        if ($path) {
            $dbDocs[$path] = $row;
        }
    }

    // Comparer
    foreach ($files as $relativePath => $file) {
        // Chercher en DB par chemin relatif ou absolu
        $found = null;
        foreach ($dbDocs as $dbPath => $dbDoc) {
            if ($dbPath === $relativePath ||
                $dbPath === $file['path'] ||
                str_ends_with($dbPath, '/' . $file['filename']) ||
                str_ends_with($dbPath, '\\' . $file['filename'])) {
                $found = $dbDoc;
                unset($dbDocs[$dbPath]); // Marquer comme traité
                break;
            }
        }

        if (!$found) {
            $result['to_index'][] = $file;
        } elseif ($found['file_hash'] !== $file['hash']) {
            $file['db_id'] = $found['id'];
            $result['to_reindex'][] = $file;
        } else {
            $file['db_id'] = $found['id'];
            $result['synced'][] = $file;
        }
    }

    // Ce qui reste dans dbDocs = orphelins
    foreach ($dbDocs as $dbDoc) {
        $result['orphans'][] = $dbDoc;
    }

    return $result;
}

// ============================================
// INDEXATION
// ============================================

/**
 * Vérifie si Ollama est disponible
 */
if (!function_exists('ollama_available')) {
    function ollama_available(): bool {
        $cfg = poc_config();
        $ch = curl_init($cfg['ollama']['url'] . '/api/tags');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }
}

/**
 * Génère un embedding via Ollama
 */
if (!function_exists('generate_embedding')) {
    function generate_embedding(string $text): ?array {
        if (empty(trim($text))) return null;

        $cfg = poc_config();
        $result = poc_ollama_call('/api/embeddings', [
            'model' => $cfg['ollama']['model'],
            'prompt' => mb_substr($text, 0, 8000),
        ]);

        return $result['embedding'] ?? null;
    }
}

/**
 * Génère une miniature
 */
function generate_thumbnail_for_file(string $filePath, string $outputPath): bool {
    $cfg = poc_config();
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    $gs = $cfg['tools']['ghostscript'];
    $lo = $cfg['tools']['libreoffice'];

    // Images: copier/redimensionner
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp'])) {
        // Simple copie pour le POC (en prod: redimensionner avec GD/Imagick)
        return copy($filePath, $outputPath);
    }

    // PDF: Ghostscript
    if ($ext === 'pdf' && file_exists($gs)) {
        $cmd = sprintf(
            '"%s" -dNOPAUSE -dBATCH -dSAFER -sDEVICE=jpeg -r72 -dFirstPage=1 -dLastPage=1 -sOutputFile="%s" "%s" 2>&1',
            $gs, $outputPath, $filePath
        );
        exec($cmd, $output, $ret);
        return file_exists($outputPath);
    }

    // Office: LibreOffice -> PDF -> Ghostscript
    if (in_array($ext, ['doc', 'docx', 'odt', 'xls', 'xlsx', 'ppt', 'pptx']) && file_exists($lo)) {
        $tempDir = $cfg['poc']['output_dir'] . '/thumb_temp_' . uniqid();
        @mkdir($tempDir, 0755, true);

        // Convertir en PDF
        $cmd = sprintf(
            '"%s" --headless --convert-to pdf --outdir "%s" "%s" 2>&1',
            $lo, $tempDir, $filePath
        );
        exec($cmd);

        $baseName = pathinfo($filePath, PATHINFO_FILENAME);
        $pdfPath = "$tempDir/$baseName.pdf";

        if (file_exists($pdfPath) && file_exists($gs)) {
            $cmd = sprintf(
                '"%s" -dNOPAUSE -dBATCH -dSAFER -sDEVICE=jpeg -r72 -dFirstPage=1 -dLastPage=1 -sOutputFile="%s" "%s" 2>&1',
                $gs, $outputPath, $pdfPath
            );
            exec($cmd);
            @unlink($pdfPath);
        }

        @rmdir($tempDir);
        return file_exists($outputPath);
    }

    return false;
}

/**
 * Suggère des tags basés sur le contenu
 */
function suggest_tags(string $text, string $filename): array {
    $tags = [];
    $textLower = mb_strtolower($text . ' ' . $filename);

    // Tags par mots-clés
    $tagRules = [
        'facture' => ['facture', 'invoice', 'rechnung', 'montant', 'total ttc'],
        'contrat' => ['contrat', 'convention', 'agreement', 'signature'],
        'rapport' => ['rapport', 'report', 'analyse', 'conclusion'],
        'courrier' => ['madame', 'monsieur', 'cordialement', 'veuillez'],
        'urgent' => ['urgent', 'immédiat', 'prioritaire', 'asap'],
        'confidentiel' => ['confidentiel', 'confidential', 'secret', 'privé'],
    ];

    foreach ($tagRules as $tag => $keywords) {
        foreach ($keywords as $kw) {
            if (str_contains($textLower, $kw)) {
                $tags[] = $tag;
                break;
            }
        }
    }

    return array_unique($tags);
}

/**
 * Traite un fichier pour indexation
 */
function process_for_indexation(array $file, bool $isUpdate = false): array {
    $cfg = poc_config();
    $filePath = $file['path'];

    $result = [
        'file' => $file['relative'],
        'filename' => $file['filename'],
        'success' => false,
        'is_update' => $isUpdate,
    ];

    try {
        // 1. Extraction texte
        $extraction = extract_text($filePath);
        $result['text_length'] = strlen($extraction['text'] ?? '');
        $result['word_count'] = str_word_count($extraction['text'] ?? '');
        $result['extraction_method'] = $extraction['method'] ?? 'unknown';

        // 2. Embedding sémantique
        $result['has_embedding'] = false;
        if (ollama_available() && !empty($extraction['text'])) {
            $embedding = generate_embedding($extraction['text']);
            $result['has_embedding'] = $embedding !== null;
        }

        // 3. Tags auto
        $result['suggested_tags'] = suggest_tags($extraction['text'] ?? '', $file['filename']);

        // 4. Miniature
        $thumbPath = $cfg['poc']['output_dir'] . '/thumb_' . $file['hash'] . '.jpg';
        $result['has_thumbnail'] = generate_thumbnail_for_file($filePath, $thumbPath);
        if ($result['has_thumbnail']) {
            $result['thumbnail_path'] = $thumbPath;
        }

        // 5. Sauvegarde en DB (si pas dry_run)
        if (!$cfg['poc']['dry_run']) {
            // TODO: Implémenter save_to_database()
            $result['db_saved'] = false;
        }

        $result['success'] = true;
        $result['status'] = 'indexed';

    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
        poc_log("Erreur indexation {$file['filename']}: " . $e->getMessage(), 'ERROR');
    }

    return $result;
}

// ============================================
// FLUX PRINCIPAL
// ============================================

/**
 * Exécute le flux de détection complet
 */
function run_detection_flow(bool $fullReindex = false): array {
    $cfg = poc_config();
    $scanPath = $cfg['paths']['documents'];

    poc_log("=== FLUX DÉTECTION ===");
    poc_log("Scan: $scanPath");
    poc_log("Mode: " . ($fullReindex ? 'Réindexation complète' : 'Incrémental'));

    $results = [
        'scan_path' => $scanPath,
        'timestamp' => date('Y-m-d H:i:s'),
        'mode' => $fullReindex ? 'full' : 'incremental',
        'stats' => [
            'scanned' => 0,
            'new' => 0,
            'modified' => 0,
            'deleted' => 0,
            'processed' => 0,
            'errors' => 0,
        ],
        'new' => [],
        'modified' => [],
        'deleted' => [],
        'errors' => [],
    ];

    // 1. Scanner le répertoire
    poc_log("Scan en cours...");
    $currentFiles = scan_directory($scanPath);
    $results['stats']['scanned'] = count($currentFiles);
    poc_log("Fichiers trouvés: " . count($currentFiles));

    // 2. Charger état précédent
    $indexFile = $cfg['poc']['output_dir'] . '/state.index.json';
    $previousState = $fullReindex ? [] : load_previous_state($indexFile);
    poc_log("État précédent: " . count($previousState) . " fichiers");

    // 3. Détecter les changements
    $changes = detect_changes($currentFiles, $previousState);
    $results['stats']['new'] = count($changes['new']);
    $results['stats']['modified'] = count($changes['modified']);
    $results['stats']['deleted'] = count($changes['deleted']);

    poc_log("Nouveaux: " . count($changes['new']));
    poc_log("Modifiés: " . count($changes['modified']));
    poc_log("Supprimés: " . count($changes['deleted']));

    // 4. Traiter les nouveaux
    foreach ($changes['new'] as $file) {
        poc_log("+ Nouveau: {$file['relative']}");
        $processResult = process_for_indexation($file, false);
        $results['new'][] = $processResult;

        if ($processResult['success']) {
            $results['stats']['processed']++;
        } else {
            $results['stats']['errors']++;
            $results['errors'][] = $processResult;
        }
    }

    // 5. Traiter les modifiés
    foreach ($changes['modified'] as $file) {
        poc_log("~ Modifié: {$file['relative']}");
        $processResult = process_for_indexation($file, true);
        $results['modified'][] = $processResult;

        if ($processResult['success']) {
            $results['stats']['processed']++;
        } else {
            $results['stats']['errors']++;
            $results['errors'][] = $processResult;
        }
    }

    // 6. Marquer les supprimés
    foreach ($changes['deleted'] as $file) {
        poc_log("- Supprimé: {$file['relative']}");
        $results['deleted'][] = [
            'file' => $file['relative'],
            'status' => 'marked_deleted',
        ];
        // TODO: Marquer comme supprimé en DB si pas dry_run
    }

    // 7. Sauvegarder nouvel état
    save_current_state($indexFile, $currentFiles);
    poc_log("État sauvegardé");

    return $results;
}

/**
 * Compare avec la DB et affiche le statut
 */
function run_db_comparison(): array {
    $cfg = poc_config();
    $scanPath = $cfg['paths']['documents'];

    poc_log("=== COMPARAISON DB ===");

    $currentFiles = scan_directory($scanPath);
    $comparison = compare_with_database($currentFiles);

    poc_log("À indexer (pas en DB): " . count($comparison['to_index']));
    poc_log("Orphelins (en DB, pas FS): " . count($comparison['orphans']));
    poc_log("À réindexer (hash diff): " . count($comparison['to_reindex']));
    poc_log("Synchronisés: " . count($comparison['synced']));

    return $comparison;
}

// ============================================
// EXÉCUTION CLI
// ============================================

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($argv[0] ?? '')) {

    echo "\n";
    echo "================================================================\n";
    echo "  POC 07 - FLUX DÉTECTION (indexation auto)\n";
    echo "================================================================\n\n";

    $cfg = poc_config();

    // Options
    $fullReindex = in_array('--full', $argv);
    $compareDb = in_array('--compare-db', $argv);

    if ($compareDb) {
        // Mode comparaison DB
        echo "Mode: Comparaison avec base de données\n\n";

        $comparison = run_db_comparison();

        echo "\n--- RÉSULTAT ---\n";
        echo "À indexer (nouveaux): " . count($comparison['to_index']) . "\n";
        echo "Orphelins (à supprimer): " . count($comparison['orphans']) . "\n";
        echo "À réindexer (modifiés): " . count($comparison['to_reindex']) . "\n";
        echo "Synchronisés: " . count($comparison['synced']) . "\n";

        if (!empty($comparison['to_index'])) {
            echo "\nFichiers à indexer:\n";
            foreach (array_slice($comparison['to_index'], 0, 10) as $f) {
                echo "  + {$f['relative']}\n";
            }
            if (count($comparison['to_index']) > 10) {
                echo "  ... et " . (count($comparison['to_index']) - 10) . " autres\n";
            }
        }

        // Sauvegarder
        $reportFile = $cfg['poc']['output_dir'] . '/07_db_comparison.json';
        file_put_contents($reportFile, json_encode($comparison, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "\nRapport: $reportFile\n";

    } else {
        // Mode détection standard
        echo "Mode: " . ($fullReindex ? 'Réindexation complète' : 'Incrémental') . "\n";
        echo "Dossier: {$cfg['paths']['documents']}\n";
        echo "Dry-run: " . ($cfg['poc']['dry_run'] ? 'OUI (pas de modif DB)' : 'NON') . "\n\n";

        $results = run_detection_flow($fullReindex);

        echo "\n--- RÉSULTAT ---\n";
        echo "Fichiers scannés: {$results['stats']['scanned']}\n";
        echo "Nouveaux: {$results['stats']['new']}\n";
        echo "Modifiés: {$results['stats']['modified']}\n";
        echo "Supprimés: {$results['stats']['deleted']}\n";
        echo "Traités: {$results['stats']['processed']}\n";
        echo "Erreurs: {$results['stats']['errors']}\n";

        // Détails
        if (!empty($results['new'])) {
            echo "\nNouveaux fichiers indexés:\n";
            foreach ($results['new'] as $r) {
                $status = $r['success'] ? 'OK' : 'ERREUR';
                $tags = !empty($r['suggested_tags']) ? ' [' . implode(', ', $r['suggested_tags']) . ']' : '';
                echo "  [$status] {$r['filename']} - {$r['word_count']} mots$tags\n";
            }
        }

        if (!empty($results['errors'])) {
            echo "\nErreurs:\n";
            foreach ($results['errors'] as $e) {
                echo "  {$e['filename']}: {$e['error']}\n";
            }
        }

        // Sauvegarder
        $reportFile = $cfg['poc']['output_dir'] . '/07_detect_result.json';
        file_put_contents($reportFile, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "\nRapport: $reportFile\n";
    }

    echo "\nOptions:\n";
    echo "  --full        Réindexation complète (ignore état précédent)\n";
    echo "  --compare-db  Compare avec la base de données\n";
    echo "\n";
}

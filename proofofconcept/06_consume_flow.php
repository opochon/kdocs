<?php
/**
 * 06_consume_flow.php - FLUX CONSUME COMPLET
 *
 * PDF bulk scan -> extraction page/page -> analyse -> split -> indexation
 *
 * LOGIQUE:
 *   1. Scanner dossier consume/
 *   2. Pour chaque PDF multi-pages:
 *      - Extraire texte + image page par page
 *      - Analyser chaque page (expéditeur, type, date...)
 *      - Détecter les ruptures de documents
 *      - Proposer split OU auto-split selon config
 *   3. Pour fichiers simples: traitement direct
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/02_ocr_extract.php';

// ============================================
// FONCTIONS UTILITAIRES
// ============================================

/**
 * Compte les pages d'un PDF via Ghostscript
 */
function get_pdf_page_count(string $pdfPath): int {
    $cfg = poc_config();
    $gs = $cfg['tools']['ghostscript'];

    if (!file_exists($gs)) {
        poc_log("Ghostscript non trouvé", 'ERROR');
        return 1;
    }

    // Méthode 1: pdfinfo si dispo
    $pdfinfo = @shell_exec("pdfinfo \"$pdfPath\" 2>&1");
    if ($pdfinfo && preg_match('/Pages:\s*(\d+)/', $pdfinfo, $m)) {
        return (int) $m[1];
    }

    // Méthode 2: Ghostscript
    $gsPath = str_replace('\\', '/', $pdfPath);
    $cmd = sprintf(
        '"%s" -q -dNODISPLAY -dNOSAFER -c "(%s) (r) file runpdfbegin pdfpagecount = quit" 2>&1',
        $gs, $gsPath
    );
    $output = trim(shell_exec($cmd) ?? '');

    if (is_numeric($output)) {
        return (int) $output;
    }

    // Méthode 3: compter les objets /Page
    $content = file_get_contents($pdfPath);
    $count = preg_match_all('/\/Type\s*\/Page[^s]/', $content);

    return max(1, $count);
}

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
 * Limite: ~8000 tokens ≈ 25000 chars
 */
if (!function_exists('generate_embedding')) {
    function generate_embedding(string $text): ?array {
        if (empty(trim($text))) return null;

        $cfg = poc_config();
        // Tronquer si trop long
        $maxChars = 25000;
        $truncatedText = mb_substr($text, 0, $maxChars);

        $result = poc_ollama_call('/api/embeddings', [
            'model' => $cfg['ollama']['model'],
            'prompt' => $truncatedText,
        ]);

        return $result['embedding'] ?? null;
    }
}

// ============================================
// EXTRACTION PAGE PAR PAGE
// ============================================

/**
 * Extrait chaque page d'un PDF (image + texte)
 */
function extract_pages(string $pdfPath): array {
    $cfg = poc_config();
    $gs = $cfg['tools']['ghostscript'];
    $lo = $cfg['tools']['libreoffice'];

    $pageCount = get_pdf_page_count($pdfPath);
    poc_log("PDF: $pageCount pages");

    // Limite de sécurité
    $maxPages = $cfg['consume']['max_pages_per_doc'] ?? 50;
    if ($pageCount > $maxPages) {
        poc_log("Trop de pages ($pageCount > $maxPages), limité", 'WARN');
        $pageCount = $maxPages;
    }

    $tempDir = $cfg['poc']['output_dir'] . '/pages_' . uniqid();
    @mkdir($tempDir, 0755, true);

    $pages = [];

    for ($i = 1; $i <= $pageCount; $i++) {
        poc_log("  Page $i/$pageCount...");

        $pageData = [
            'page_num' => $i,
            'text' => '',
            'image_path' => null,
            'has_text' => false,
            'ocr_used' => false,
        ];

        // 1. Extraire image de la page
        $imgPath = "$tempDir/page_$i.png";
        $cmd = sprintf(
            '"%s" -dNOPAUSE -dBATCH -dSAFER -sDEVICE=png16m -r150 -dFirstPage=%d -dLastPage=%d -sOutputFile="%s" "%s" 2>&1',
            $gs, $i, $i, $imgPath, $pdfPath
        );
        exec($cmd, $output, $ret);

        if (file_exists($imgPath)) {
            $pageData['image_path'] = $imgPath;
        }

        // 2. Extraire texte natif via pdftotext
        $pdftotext = $cfg['tools']['pdftotext'] ?? '';
        if (file_exists($pdftotext)) {
            $textPath = "$tempDir/page_$i.txt";
            $cmd = sprintf(
                '"%s" -f %d -l %d -enc UTF-8 "%s" "%s" 2>&1',
                $pdftotext, $i, $i, $pdfPath, $textPath
            );
            exec($cmd);

            if (file_exists($textPath)) {
                $text = trim(file_get_contents($textPath));
                $pageData['text'] = $text;
                $pageData['has_text'] = str_word_count($text) > 30;
                @unlink($textPath);
            }
        }

        // 3. Si pas assez de texte et image dispo -> OCR
        if (!$pageData['has_text'] && $pageData['image_path']) {
            $ocr = ocr_tesseract($pageData['image_path']);
            if ($ocr && !empty($ocr['text'])) {
                $pageData['text'] = $ocr['text'];
                $pageData['ocr_used'] = true;
                $pageData['has_text'] = str_word_count($ocr['text']) > 30;
            }
        }

        $pages[] = $pageData;
    }

    return [
        'pages' => $pages,
        'page_count' => $pageCount,
        'temp_dir' => $tempDir,
    ];
}

// ============================================
// ANALYSE ET CLASSIFICATION
// ============================================

/**
 * Analyse une page pour extraire métadonnées
 */
function analyze_page(array $page): array {
    $text = $page['text'] ?? '';

    // Détecter indicateur de pagination (Page 1/2, Seite 1 von 2, etc.)
    $pageIndicator = detect_page_indicator($text);

    $analysis = [
        'page_num' => $page['page_num'],
        'word_count' => str_word_count($text),
        'categorie' => detect_document_type($text),
        'expediteur' => extract_sender($text),
        'destinataire' => extract_recipient($text),
        'date' => extract_date($text),
        'montant' => extract_amount($text),
        'reference' => extract_reference($text),
        'doc_page' => $pageIndicator['current'] ?? null,  // Page N du document
        'doc_total_pages' => $pageIndicator['total'] ?? null,  // Total pages du doc
        'is_first_page' => $pageIndicator['is_first'] ?? false,  // Est première page
    ];

    return $analysis;
}

/**
 * Détecte les indicateurs de pagination dans le texte
 * Ex: "Page 1/2", "Page: 2/2", "Seite 1 von 3", "1 / 1"
 */
function detect_page_indicator(string $text): array {
    $result = ['current' => null, 'total' => null, 'is_first' => false];

    $patterns = [
        // "Page 1/2", "Page: 1 / 2", "page 1/2"
        '/page\s*:?\s*(\d+)\s*[\/\|]\s*(\d+)/i',
        // "Seite 1 von 2"
        '/seite\s*(\d+)\s*von\s*(\d+)/i',
        // "1 / 1" ou "1/1" en fin de ligne
        '/(\d+)\s*[\/\|]\s*(\d+)\s*$/m',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $result['current'] = (int)$m[1];
            $result['total'] = (int)$m[2];
            $result['is_first'] = ($result['current'] === 1);
            break;
        }
    }

    // Détecter aussi "Page 1" seul (sans total)
    if ($result['current'] === null) {
        if (preg_match('/page\s*:?\s*1\s*$/im', $text)) {
            $result['current'] = 1;
            $result['is_first'] = true;
        }
    }

    return $result;
}

/**
 * Détecte le type de document par mots-clés
 */
function detect_document_type(string $text): string {
    $text = mb_strtolower($text);

    $patterns = [
        'facture' => ['facture', 'invoice', 'rechnung', 'montant dû', 'total ttc', 'à payer'],
        'contrat' => ['contrat', 'convention', 'agreement', 'vertrag', 'signataire', 'parties'],
        'courrier' => ['madame', 'monsieur', 'cher client', 'veuillez', 'cordialement', 'salutations'],
        'rapport' => ['rapport', 'report', 'bericht', 'analyse', 'conclusion', 'recommandation'],
        'devis' => ['devis', 'offre', 'quotation', 'angebot', 'estimation', 'proposition'],
        'bon_commande' => ['bon de commande', 'purchase order', 'bestellung'],
        'releve' => ['relevé', 'statement', 'auszug', 'solde', 'mouvement'],
    ];

    $scores = [];
    foreach ($patterns as $type => $keywords) {
        $scores[$type] = 0;
        foreach ($keywords as $kw) {
            $scores[$type] += substr_count($text, $kw);
        }
    }

    arsort($scores);
    $best = array_key_first($scores);

    return $scores[$best] > 0 ? $best : 'autre';
}

/**
 * Extrait l'expéditeur
 */
function extract_sender(string $text): ?string {
    // Patterns courants pour expéditeur
    $patterns = [
        '/(?:De|From|Von|Expéditeur)\s*:\s*([^\n]+)/i',
        '/^([A-Z][A-Za-zÀ-ÿ\s&.-]+(?:SA|AG|GmbH|Sàrl|SARL|SAS|Ltd|Inc)?)\s*$/m',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $sender = trim($m[1]);
            if (strlen($sender) > 3 && strlen($sender) < 100) {
                return $sender;
            }
        }
    }

    return null;
}

/**
 * Extrait le destinataire
 */
function extract_recipient(string $text): ?string {
    $patterns = [
        '/(?:À|To|An|Destinataire)\s*:\s*([^\n]+)/i',
        '/(?:Madame|Monsieur|M\.|Mme)\s+([A-Za-zÀ-ÿ\s-]+)/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $recipient = trim($m[1]);
            if (strlen($recipient) > 3 && strlen($recipient) < 100) {
                return $recipient;
            }
        }
    }

    return null;
}

/**
 * Extrait la date du document
 */
if (!function_exists('extract_date')) {
function extract_date(string $text): ?string {
    $patterns = [
        // Format: 15 janvier 2025, 15 Jan 2025
        '/(\d{1,2})\s+(janvier|février|mars|avril|mai|juin|juillet|août|septembre|octobre|novembre|décembre|jan|fév|mar|avr|mai|jun|jul|aoû|sep|oct|nov|déc)\s+(\d{4})/i',
        // Format: 15/01/2025, 15.01.2025
        '/(\d{1,2})[\/\.-](\d{1,2})[\/\.-](\d{4})/',
        // Format: 2025-01-15
        '/(\d{4})-(\d{2})-(\d{2})/',
    ];

    $months = [
        'janvier' => 1, 'jan' => 1, 'février' => 2, 'fév' => 2, 'mars' => 3, 'mar' => 3,
        'avril' => 4, 'avr' => 4, 'mai' => 5, 'juin' => 6, 'jun' => 6,
        'juillet' => 7, 'jul' => 7, 'août' => 8, 'aoû' => 8, 'septembre' => 9, 'sep' => 9,
        'octobre' => 10, 'oct' => 10, 'novembre' => 11, 'nov' => 11, 'décembre' => 12, 'déc' => 12,
    ];

    // Pattern 1: jour mois année
    if (preg_match($patterns[0], $text, $m)) {
        $day = (int)$m[1];
        $month = $months[strtolower($m[2])] ?? 1;
        $year = (int)$m[3];
        if ($year >= 2000 && $year <= 2030) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }

    // Pattern 2: JJ/MM/AAAA (année 4 chiffres)
    if (preg_match($patterns[1], $text, $m)) {
        $day = (int)$m[1];
        $month = (int)$m[2];
        $year = (int)$m[3];
        if ($year >= 2000 && $year <= 2030 && $month >= 1 && $month <= 12) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }

    // Pattern 2b: JJ/MM/AA (année 2 chiffres)
    if (preg_match('/(\d{1,2})[\/\.-](\d{1,2})[\/\.-](\d{2})(?!\d)/', $text, $m)) {
        $day = (int)$m[1];
        $month = (int)$m[2];
        $year = (int)$m[3];
        // Convertir année 2 chiffres -> 4 chiffres (20xx pour 00-30, 19xx pour 31-99)
        $year = $year <= 30 ? 2000 + $year : 1900 + $year;
        if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }

    // Pattern 3: AAAA-MM-JJ
    if (preg_match($patterns[2], $text, $m)) {
        return $m[0];
    }

    return null;
}
}

/**
 * Extrait un montant
 */
if (!function_exists('extract_amount')) {
function extract_amount(string $text): ?string {
    $patterns = [
        '/(?:total|montant|amount|betrag|à payer)\s*(?:ttc|ht)?\s*:?\s*([\d\s\']+[.,]\d{2})\s*(?:chf|eur|€|fr)?/i',
        '/([\d\s\']+[.,]\d{2})\s*(?:chf|eur|€|fr\.?)/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $amount = preg_replace('/[\s\']/', '', $m[1]);
            $amount = str_replace(',', '.', $amount);
            if (is_numeric($amount) && (float)$amount > 0) {
                return $amount;
            }
        }
    }

    return null;
}
}

/**
 * Extrait une référence/numéro de document
 */
if (!function_exists('extract_reference')) {
function extract_reference(string $text): ?string {
    $patterns = [
        '/(?:réf|ref|référence|n°|no|numéro|number)\s*[.:]\s*([A-Z0-9\/-]+)/i',
        '/(?:facture|invoice|contrat|dossier)\s*(?:n°|no|#)?\s*:?\s*([A-Z0-9\/-]+)/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $ref = trim($m[1]);
            if (strlen($ref) >= 3 && strlen($ref) <= 30) {
                return $ref;
            }
        }
    }

    return null;
}
}

// ============================================
// GROUPEMENT ET SPLIT
// ============================================

/**
 * Groupe les pages par document (détection ruptures)
 */
function group_pages_by_document(array $pageAnalyses): array {
    if (empty($pageAnalyses)) return [];
    if (count($pageAnalyses) === 1) return [$pageAnalyses];

    $groups = [];
    $currentGroup = [$pageAnalyses[0]];

    for ($i = 1; $i < count($pageAnalyses); $i++) {
        $prev = $pageAnalyses[$i - 1];
        $curr = $pageAnalyses[$i];

        if (is_document_break($prev, $curr)) {
            $groups[] = $currentGroup;
            $currentGroup = [$curr];
            poc_log("  Rupture détectée entre pages {$prev['page_num']} et {$curr['page_num']}");
        } else {
            $currentGroup[] = $curr;
        }
    }
    $groups[] = $currentGroup;

    // Post-traitement: fusionner les groupes trop petits
    $groups = merge_single_page_groups($groups);

    return $groups;
}

/**
 * Fusionne les groupes d'une seule page SEULEMENT si dates identiques
 *
 * LOGIQUE AMÉLIORÉE: Ne fusionne que si :
 * - Même date (ou dates absentes)
 * - Pas d'indicateur "Page 1" sur la page isolée
 */
function merge_single_page_groups(array $groups): array {
    if (count($groups) <= 1) return $groups;

    $merged = [];
    $buffer = null;

    foreach ($groups as $group) {
        if ($buffer === null) {
            $buffer = $group;
            continue;
        }

        $canMerge = false;

        // Si le groupe actuel n'a qu'une page
        if (count($group) === 1 && count($buffer) > 0) {
            $prevPage = $buffer[count($buffer) - 1];
            $currPage = $group[0];

            // NE PAS fusionner si la page a un indicateur "Page 1"
            if ($currPage['is_first_page'] ?? false) {
                $canMerge = false;
            }
            // NE PAS fusionner si dates différentes (>1 jour)
            elseif (!empty($prevPage['date']) && !empty($currPage['date'])) {
                try {
                    $d1 = new DateTime($prevPage['date']);
                    $d2 = new DateTime($currPage['date']);
                    $diff = abs($d1->diff($d2)->days);
                    $canMerge = ($diff <= 1);  // Fusionner seulement si même date
                } catch (Exception $e) {
                    $canMerge = false;
                }
            }
            // Si pas de dates, vérifier le type
            else {
                $prevType = $prevPage['categorie'] ?? 'autre';
                $currType = $currPage['categorie'] ?? 'autre';
                $canMerge = ($prevType === $currType);
            }

            if ($canMerge) {
                $buffer = array_merge($buffer, $group);
                poc_log("  Fusion: groupe 1 page fusionné (même date)");
                continue;
            }
        }

        // Sinon, sauver le buffer et commencer un nouveau
        $merged[] = $buffer;
        $buffer = $group;
    }

    // Ajouter le dernier buffer
    if ($buffer !== null) {
        $merged[] = $buffer;
    }

    return $merged;
}

/**
 * Détermine si deux pages sont une rupture de document
 *
 * LOGIQUE AMÉLIORÉE:
 * - "Page 1" ou "Page 1/x" = nouveau document (rupture certaine)
 * - "Page x/y" où x > 1 = continuation (pas de rupture)
 * - Date différente (>1 jour) = rupture probable
 */
function is_document_break(array $prev, array $curr): bool {
    // RÈGLE 1: Si page courante indique "Page 1" ou "Page 1/x" → nouveau document
    if ($curr['is_first_page'] ?? false) {
        return true;
    }

    // RÈGLE 2: Si page courante indique "Page x/y" où x > 1 → continuation
    if (($curr['doc_page'] ?? 0) > 1) {
        return false;
    }

    // RÈGLE 3: Si page précédente était dernière page d'un doc → rupture
    $prevPage = $prev['doc_page'] ?? 0;
    $prevTotal = $prev['doc_total_pages'] ?? 0;
    if ($prevPage > 0 && $prevTotal > 0 && $prevPage === $prevTotal) {
        return true;
    }

    $breakIndicators = 0;

    // Critère A: Changement de date (>1 jour = poids fort)
    if (!empty($prev['date']) && !empty($curr['date']) && $prev['date'] !== $curr['date']) {
        try {
            $d1 = new DateTime($prev['date']);
            $d2 = new DateTime($curr['date']);
            $diff = abs($d1->diff($d2)->days);
            if ($diff > 1) {
                $breakIndicators += 2;
            }
        } catch (Exception $e) {}
    }

    // Critère B: Changement d'expéditeur (poids fort)
    $exp1 = strtolower(trim($prev['expediteur'] ?? ''));
    $exp2 = strtolower(trim($curr['expediteur'] ?? ''));
    if ($exp1 && $exp2 && $exp1 !== $exp2) {
        $breakIndicators += 2;
    }

    // Critère C: Nouvelle référence
    if (!empty($curr['reference']) &&
        !empty($prev['reference']) &&
        $curr['reference'] !== $prev['reference']) {
        $breakIndicators++;
    }

    // Critère D: Changement de type
    if ($prev['categorie'] !== $curr['categorie'] &&
        $prev['categorie'] !== 'autre' &&
        $curr['categorie'] !== 'autre') {
        $breakIndicators++;
    }

    return $breakIndicators >= 2;
}

/**
 * Split un PDF selon les groupes
 */
function split_pdf(string $pdfPath, array $groups): array {
    $cfg = poc_config();
    $gs = $cfg['tools']['ghostscript'];
    $results = [];

    $baseName = pathinfo($pdfPath, PATHINFO_FILENAME);

    foreach ($groups as $idx => $group) {
        $pages = array_column($group, 'page_num');
        $firstPage = min($pages);
        $lastPage = max($pages);

        $outputPath = $cfg['poc']['output_dir'] . '/' . $baseName . '_part' . ($idx + 1) . '.pdf';

        $cmd = sprintf(
            '"%s" -dNOPAUSE -dBATCH -dSAFER -sDEVICE=pdfwrite -dFirstPage=%d -dLastPage=%d -sOutputFile="%s" "%s" 2>&1',
            $gs, $firstPage, $lastPage, $outputPath, $pdfPath
        );
        exec($cmd, $output, $ret);

        if (file_exists($outputPath)) {
            $results[] = [
                'path' => $outputPath,
                'pages' => $pages,
                'page_range' => "$firstPage-$lastPage",
                'analysis' => $group[0], // Métadonnées de la première page
            ];
            poc_log("  Split créé: $outputPath (pages $firstPage-$lastPage)");
        } else {
            poc_log("  Échec split pages $firstPage-$lastPage", 'ERROR');
        }
    }

    return $results;
}

// ============================================
// TRAITEMENT PRINCIPAL
// ============================================

/**
 * Traite un fichier du dossier consume
 */
function process_consume_file(string $filePath): array {
    $cfg = poc_config();

    poc_log("=== CONSUME: " . basename($filePath) . " ===");

    $result = [
        'source' => $filePath,
        'source_name' => basename($filePath),
        'documents' => [],
        'split_required' => false,
        'split_confirmed' => null,
        'groups' => [],
    ];

    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    // Fichier non-PDF ou PDF simple -> traitement direct
    if ($ext !== 'pdf') {
        poc_log("Type: $ext (non-PDF) -> traitement direct");
        $result['documents'][] = process_single_document($filePath);
        return $result;
    }

    $pageCount = get_pdf_page_count($filePath);
    poc_log("PDF: $pageCount page(s)");

    if ($pageCount <= 1) {
        poc_log("PDF 1 page -> traitement direct");
        $result['documents'][] = process_single_document($filePath);
        return $result;
    }

    // PDF multi-pages -> extraction et analyse page par page
    poc_log("PDF multi-pages -> analyse pour split");

    $extraction = extract_pages($filePath);
    $pageAnalyses = [];

    // Analyser chaque page
    foreach ($extraction['pages'] as $page) {
        $analysis = analyze_page($page);
        $analysis['image_path'] = $page['image_path'];
        $analysis['ocr_used'] = $page['ocr_used'];
        $pageAnalyses[] = $analysis;
    }

    // Grouper les pages par document
    $groups = group_pages_by_document($pageAnalyses);
    $result['groups'] = $groups;

    if (count($groups) > 1) {
        $result['split_required'] = true;
        poc_log("Split détecté: " . count($groups) . " documents");

        // Auto-confirm ou attendre validation user
        $autoConfirm = $cfg['consume']['split_auto_confirm'] ?? false;
        $result['split_confirmed'] = $autoConfirm;

        if ($result['split_confirmed']) {
            // Splitter le PDF
            $splitFiles = split_pdf($filePath, $groups);
            foreach ($splitFiles as $splitFile) {
                $doc = process_single_document($splitFile['path'], $splitFile['analysis']);
                $doc['split_from'] = basename($filePath);
                $doc['page_range'] = $splitFile['page_range'];
                $result['documents'][] = $doc;
            }
        } else {
            // Stocker les infos pour validation UI
            poc_log("Split en attente de confirmation utilisateur");
            foreach ($groups as $idx => $group) {
                $result['documents'][] = [
                    'status' => 'pending_split_confirm',
                    'group_index' => $idx,
                    'pages' => array_column($group, 'page_num'),
                    'preview_analysis' => $group[0],
                ];
            }
        }
    } else {
        // Pas de split nécessaire
        poc_log("Pas de split nécessaire");
        $result['documents'][] = process_single_document($filePath);
    }

    // Nettoyer fichiers temporaires (images pages)
    if (isset($extraction['temp_dir']) && is_dir($extraction['temp_dir'])) {
        $files = glob($extraction['temp_dir'] . '/*');
        foreach ($files as $f) {
            @unlink($f);
        }
        @rmdir($extraction['temp_dir']);
    }

    return $result;
}

/**
 * Traite un document unique
 */
function process_single_document(string $filePath, ?array $preAnalysis = null): array {
    $cfg = poc_config();

    poc_log("Traitement: " . basename($filePath));

    $doc = [
        'file' => $filePath,
        'filename' => basename($filePath),
        'status' => 'processed',
        'text' => '',
        'extraction_method' => 'unknown',
        'suggestions' => [],
    ];

    // Extraction texte
    $extraction = extract_text($filePath);
    $doc['text'] = $extraction['text'] ?? '';
    $doc['extraction_method'] = $extraction['method'] ?? 'none';
    $doc['word_count'] = str_word_count($doc['text']);

    // Utiliser pré-analyse si disponible, sinon analyser
    if ($preAnalysis) {
        $doc['suggestions'] = $preAnalysis;
    } else {
        $doc['suggestions'] = [
            'categorie' => detect_document_type($doc['text']),
            'expediteur' => extract_sender($doc['text']),
            'date' => extract_date($doc['text']),
            'montant' => extract_amount($doc['text']),
            'reference' => extract_reference($doc['text']),
        ];
    }

    // Embedding sémantique (si Ollama dispo)
    if (ollama_available() && !empty($doc['text'])) {
        $embedding = generate_embedding($doc['text']);
        $doc['has_embedding'] = $embedding !== null;
    }

    // Miniature
    $thumbPath = $cfg['poc']['output_dir'] . '/thumb_' . md5($filePath) . '.jpg';
    if (function_exists('generate_thumbnail')) {
        $thumb = generate_thumbnail($filePath, $thumbPath);
        $doc['thumbnail'] = ($thumb['success'] ?? false) ? $thumbPath : null;
    }

    return $doc;
}

/**
 * Scanner le dossier consume
 */
function scan_consume_folder(): array {
    $cfg = poc_config();
    $consumePath = $cfg['paths']['consume'];

    if (!is_dir($consumePath)) {
        poc_log("Dossier consume non trouvé: $consumePath", 'ERROR');
        return [];
    }

    $files = [];
    $extensions = ['pdf', 'jpg', 'jpeg', 'png', 'tiff', 'doc', 'docx', 'odt'];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($consumePath, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $ext = strtolower($file->getExtension());
            if (in_array($ext, $extensions)) {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

// ============================================
// EXÉCUTION CLI
// ============================================

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($argv[0] ?? '')) {

    echo "\n";
    echo "================================================================\n";
    echo "  POC 06 - FLUX CONSUME (bulk scan + split)\n";
    echo "================================================================\n\n";

    $cfg = poc_config();

    // Mode: fichier unique ou scan dossier
    $testFile = $argv[1] ?? null;

    if ($testFile && file_exists($testFile)) {
        // Fichier unique
        echo "Mode: Fichier unique\n";
        echo "Fichier: $testFile\n\n";

        $result = process_consume_file($testFile);

        echo "\n--- RÉSULTAT ---\n";
        echo "Source: {$result['source_name']}\n";
        echo "Split requis: " . ($result['split_required'] ? 'OUI (' . count($result['groups']) . ' docs)' : 'NON') . "\n";
        echo "Split confirmé: " . ($result['split_confirmed'] === null ? 'N/A' : ($result['split_confirmed'] ? 'OUI' : 'NON')) . "\n";
        echo "Documents: " . count($result['documents']) . "\n\n";

        foreach ($result['documents'] as $i => $doc) {
            echo "--- Document " . ($i + 1) . " ---\n";
            if (isset($doc['status']) && $doc['status'] === 'pending_split_confirm') {
                echo "  Status: EN ATTENTE CONFIRMATION\n";
                echo "  Pages: " . implode(', ', $doc['pages']) . "\n";
                echo "  Type suggéré: " . ($doc['preview_analysis']['categorie'] ?? '?') . "\n";
            } else {
                echo "  Fichier: " . ($doc['filename'] ?? basename($doc['file'])) . "\n";
                echo "  Mots: " . ($doc['word_count'] ?? '?') . "\n";
                echo "  Extraction: " . ($doc['extraction_method'] ?? '?') . "\n";
                echo "  Type: " . ($doc['suggestions']['categorie'] ?? '?') . "\n";
                echo "  Expéditeur: " . ($doc['suggestions']['expediteur'] ?? '-') . "\n";
                echo "  Date: " . ($doc['suggestions']['date'] ?? '-') . "\n";
                echo "  Montant: " . ($doc['suggestions']['montant'] ?? '-') . "\n";
            }
            echo "\n";
        }

        // Sauvegarder
        $reportFile = $cfg['poc']['output_dir'] . '/06_consume_result.json';
        file_put_contents($reportFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "Rapport: $reportFile\n";

    } else {
        // Scan dossier consume
        echo "Mode: Scan dossier consume\n";
        echo "Dossier: {$cfg['paths']['consume']}\n\n";

        $files = scan_consume_folder();
        echo "Fichiers trouvés: " . count($files) . "\n\n";

        if (empty($files)) {
            echo "Aucun fichier à traiter.\n";
            echo "Placez des fichiers dans: {$cfg['paths']['consume']}\n";
        } else {
            $allResults = [];
            foreach ($files as $file) {
                echo "----------------------------------------\n";
                $result = process_consume_file($file);
                $allResults[] = $result;
            }

            // Résumé
            echo "\n========================================\n";
            echo "RÉSUMÉ\n";
            echo "========================================\n";
            $totalDocs = 0;
            $totalSplits = 0;
            foreach ($allResults as $r) {
                $totalDocs += count($r['documents']);
                if ($r['split_required']) $totalSplits++;
            }
            echo "Fichiers traités: " . count($allResults) . "\n";
            echo "Documents générés: $totalDocs\n";
            echo "Splits détectés: $totalSplits\n";

            // Sauvegarder
            $reportFile = $cfg['poc']['output_dir'] . '/06_consume_batch_result.json';
            file_put_contents($reportFile, json_encode($allResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            echo "\nRapport: $reportFile\n";
        }
    }

    echo "\n";
}

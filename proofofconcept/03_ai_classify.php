<?php
/**
 * 03_ai_classify.php - CLASSIFICATION IA (CLAUDE + OLLAMA)
 *
 * Équivalent POC de:
 * - AIClassifierService.php (classification Claude)
 * - AutoClassifierService.php (classification règles)
 * - ClassificationService.php (orchestrateur)
 *
 * LOGIQUE:
 *   1. Classification par règles (mots-clés)
 *   2. Classification IA (Claude si dispo, sinon Ollama)
 *   3. Fusion des résultats (mode auto)
 */

require_once __DIR__ . '/helpers.php';

// ============================================
// VÉRIFICATION DISPONIBILITÉ IA
// ============================================

/**
 * Vérifie si Claude API est configuré et disponible
 */
function claude_available(): bool {
    $cfg = poc_config();
    $apiKey = $cfg['claude']['api_key'] ?? '';
    return !empty($apiKey);
}

/**
 * Vérifie si Ollama chat est disponible
 */
function ollama_chat_available(): bool {
    $cfg = poc_config();
    $ollamaUrl = $cfg['ollama']['url'] ?? 'http://localhost:11434';

    $ch = curl_init($ollamaUrl . '/api/tags');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200;
}

// ============================================
// CLASSIFICATION PAR RÈGLES
// ============================================

/**
 * Classification par règles (mots-clés)
 * Équivalent à AutoClassifierService
 */
function classify_by_rules(string $text, string $filename = ''): array {
    $textLower = mb_strtolower($text . ' ' . $filename);

    // Types de documents
    $types = [
        'facture' => ['facture', 'invoice', 'rechnung', 'montant dû', 'total ttc', 'à payer', 'tva', 'ht'],
        'contrat' => ['contrat', 'convention', 'agreement', 'vertrag', 'signataire', 'parties', 'clause'],
        'courrier' => ['madame', 'monsieur', 'cher client', 'veuillez', 'cordialement', 'salutations'],
        'rapport' => ['rapport', 'report', 'bericht', 'analyse', 'conclusion', 'recommandation'],
        'devis' => ['devis', 'offre', 'quotation', 'angebot', 'estimation', 'proposition commerciale'],
        'bon_commande' => ['bon de commande', 'purchase order', 'bestellung', 'commande n°'],
        'releve' => ['relevé', 'statement', 'auszug', 'solde', 'mouvement', 'extrait de compte'],
        'attestation' => ['attestation', 'certificat', 'certificate', 'certifie', 'atteste'],
        'proces_verbal' => ['procès-verbal', 'pv', 'assemblée', 'réunion', 'séance'],
    ];

    $detectedType = 'autre';
    $maxScore = 0;
    foreach ($types as $type => $keywords) {
        $score = 0;
        foreach ($keywords as $kw) {
            $score += substr_count($textLower, $kw) * 2;
        }
        if ($score > $maxScore) {
            $maxScore = $score;
            $detectedType = $type;
        }
    }

    // Tags
    $tags = [];
    $tagKeywords = [
        'urgent' => ['urgent', 'prioritaire', 'asap', 'immédiat'],
        'confidentiel' => ['confidentiel', 'secret', 'privé', 'restricted'],
        'juridique' => ['tribunal', 'avocat', 'jugement', 'juridique', 'légal'],
        'fiscal' => ['impôt', 'taxe', 'fiscal', 'tva', 'déclaration'],
        'rh' => ['salaire', 'employé', 'contrat de travail', 'ressources humaines'],
        'technique' => ['technique', 'spécification', 'cahier des charges', 'schéma'],
    ];
    foreach ($tagKeywords as $tag => $keywords) {
        foreach ($keywords as $kw) {
            if (str_contains($textLower, $kw)) {
                $tags[] = $tag;
                break;
            }
        }
    }

    // Date
    $date = null;
    $datePatterns = [
        '/(\d{1,2})[\/\.-](\d{1,2})[\/\.-](\d{4})/',           // JJ/MM/AAAA
        '/(\d{4})-(\d{2})-(\d{2})/',                            // AAAA-MM-JJ
        '/(\d{1,2})\s+(janvier|février|mars|avril|mai|juin|juillet|août|septembre|octobre|novembre|décembre)\s+(\d{4})/i',
    ];
    foreach ($datePatterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            if (count($m) >= 4) {
                if (is_numeric($m[1]) && strlen($m[1]) === 4) {
                    // Format AAAA-MM-JJ
                    $date = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
                } else {
                    // Format JJ/MM/AAAA ou JJ mois AAAA
                    $months = ['janvier'=>1,'février'=>2,'mars'=>3,'avril'=>4,'mai'=>5,'juin'=>6,
                               'juillet'=>7,'août'=>8,'septembre'=>9,'octobre'=>10,'novembre'=>11,'décembre'=>12];
                    $month = is_numeric($m[2]) ? (int)$m[2] : ($months[strtolower($m[2])] ?? 1);
                    $year = (int)$m[3];
                    $day = (int)$m[1];
                    if ($year >= 2000 && $year <= 2030 && $month >= 1 && $month <= 12) {
                        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    }
                }
            }
            break;
        }
    }

    // Montant
    $amount = null;
    if (preg_match('/([\d\s\']+[.,]\d{2})\s*(?:chf|eur|€|fr\.?|usd|\$)/i', $text, $m)) {
        $amount = preg_replace('/[\s\']/', '', $m[1]);
        $amount = str_replace(',', '.', $amount);
    }

    // Correspondant (heuristique simple)
    $correspondent = null;
    $corrPatterns = [
        '/(?:De|From|Von|Expéditeur)\s*:\s*([^\n]+)/i',
        '/^([A-Z][A-Za-zÀ-ÿ\s&.-]+(?:SA|AG|GmbH|Sàrl|SARL|SAS|Ltd|Inc)?)\s*$/m',
    ];
    foreach ($corrPatterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $corr = trim($m[1]);
            if (strlen($corr) > 3 && strlen($corr) < 80) {
                $correspondent = $corr;
                break;
            }
        }
    }

    // Calcul confiance
    $confidence = 0.0;
    if ($detectedType !== 'autre') $confidence += 0.3;
    if ($date) $confidence += 0.2;
    if ($correspondent) $confidence += 0.2;
    if ($amount) $confidence += 0.15;
    if (!empty($tags)) $confidence += 0.15;

    return [
        'method' => 'rules',
        'document_type' => $detectedType,
        'correspondent' => $correspondent,
        'tags' => $tags,
        'date' => $date,
        'amount' => $amount,
        'confidence' => min(1.0, $confidence),
    ];
}

// ============================================
// CLASSIFICATION IA (CLAUDE)
// ============================================

/**
 * Appel API Claude
 */
function call_claude(string $prompt, string $systemPrompt = ''): ?array {
    $cfg = poc_config();
    $apiKey = $cfg['claude']['api_key'] ?? '';
    $model = $cfg['claude']['model'] ?? 'claude-sonnet-4-20250514';
    $timeout = $cfg['claude']['timeout'] ?? 60;

    if (empty($apiKey)) {
        return null;
    }

    $messages = [['role' => 'user', 'content' => $prompt]];

    $payload = [
        'model' => $model,
        'max_tokens' => 2048,
        'messages' => $messages,
    ];

    if (!empty($systemPrompt)) {
        $payload['system'] = $systemPrompt;
    }

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => $timeout,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        poc_log("Claude curl error: $curlError", 'ERROR');
        return null;
    }

    if ($httpCode !== 200) {
        poc_log("Claude HTTP $httpCode: $response", 'ERROR');
        return null;
    }

    $data = json_decode($response, true);

    if (!isset($data['content'][0]['text'])) {
        poc_log("Claude: réponse invalide", 'ERROR');
        return null;
    }

    return $data;
}

/**
 * Classification via Claude
 */
function classify_with_claude(string $text, string $filename = ''): ?array {
    if (!claude_available()) {
        return null;
    }

    $systemPrompt = <<<PROMPT
Tu es un assistant spécialisé dans la classification de documents.
Tu dois analyser le contenu d'un document et suggérer :
- Un type de document (facture, contrat, courrier, rapport, devis, attestation, etc.)
- Un correspondant (expéditeur/émetteur)
- Des tags pertinents
- La date du document
- Un montant (si applicable)
- Une synthèse courte (2-3 phrases)

Réponds UNIQUEMENT en JSON valide avec cette structure :
{
    "document_type": "type suggéré",
    "correspondent": "nom ou null",
    "tags": ["tag1", "tag2"],
    "date": "YYYY-MM-DD ou null",
    "amount": 123.45 ou null,
    "summary": "Synthèse courte du document",
    "confidence": 0.0 à 1.0
}
PROMPT;

    $prompt = <<<PROMPT
Analyse et classifie ce document.

NOM DU FICHIER: $filename

CONTENU:
$text

Réponds uniquement en JSON.
PROMPT;

    // Tronquer si trop long
    if (mb_strlen($prompt) > 15000) {
        $prompt = mb_substr($prompt, 0, 15000) . "\n...[tronqué]";
    }

    $response = call_claude($prompt, $systemPrompt);
    if (!$response) {
        return null;
    }

    $responseText = $response['content'][0]['text'] ?? '';

    // Nettoyer le JSON
    $responseText = preg_replace('/^```json\s*/', '', $responseText);
    $responseText = preg_replace('/\s*```$/', '', $responseText);
    $responseText = trim($responseText);

    $result = json_decode($responseText, true);
    if (!$result || json_last_error() !== JSON_ERROR_NONE) {
        poc_log("Claude: JSON invalide - $responseText", 'ERROR');
        return null;
    }

    $result['method'] = 'claude';

    return $result;
}

// ============================================
// CLASSIFICATION IA (OLLAMA FALLBACK)
// ============================================

/**
 * Classification via Ollama (fallback si Claude non dispo)
 */
function classify_with_ollama(string $text, string $filename = ''): ?array {
    if (!ollama_chat_available()) {
        return null;
    }

    $cfg = poc_config();
    $model = $cfg['ollama']['chat_model'] ?? 'llama3.2';
    $url = rtrim($cfg['ollama']['url'], '/') . '/api/generate';

    $prompt = <<<PROMPT
Analyse ce document et réponds en JSON avec cette structure exacte:
{
    "document_type": "facture|contrat|courrier|rapport|devis|autre",
    "correspondent": "nom de l'expéditeur ou null",
    "tags": ["tag1", "tag2"],
    "date": "YYYY-MM-DD ou null",
    "amount": nombre ou null,
    "summary": "résumé court",
    "confidence": nombre entre 0 et 1
}

Fichier: $filename

Contenu:
$text

Réponds UNIQUEMENT en JSON valide, sans autre texte.
PROMPT;

    // Tronquer si trop long
    if (mb_strlen($prompt) > 6000) {
        $prompt = mb_substr($prompt, 0, 6000) . "\n...[tronqué]";
    }

    $payload = [
        'model' => $model,
        'prompt' => $prompt,
        'stream' => false,
        'format' => 'json',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 120,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        poc_log("Ollama classify: HTTP $httpCode", 'WARN');
        return null;
    }

    $data = json_decode($response, true);
    $responseText = $data['response'] ?? '';

    $result = json_decode($responseText, true);
    if (!$result || json_last_error() !== JSON_ERROR_NONE) {
        poc_log("Ollama classify: JSON invalide", 'WARN');
        return null;
    }

    $result['method'] = 'ollama';

    return $result;
}

// ============================================
// ORCHESTRATEUR (COMME ClassificationService)
// ============================================

/**
 * Classification complète (règles + IA)
 * Équivalent à ClassificationService::classify()
 */
function classify_document(string $text, string $filename = '', string $method = 'auto'): array {
    poc_log("Classification: méthode=$method");

    $result = [
        'method_used' => $method,
        'rules_result' => null,
        'ai_result' => null,
        'final' => null,
        'confidence' => 0,
    ];

    // ========== RÈGLES ==========
    if ($method === 'rules' || $method === 'auto') {
        $result['rules_result'] = classify_by_rules($text, $filename);
        $result['final'] = $result['rules_result'];
        $result['confidence'] = $result['rules_result']['confidence'] ?? 0;
        poc_log("  Règles: type={$result['rules_result']['document_type']}, conf=" . round($result['confidence'], 2));
    }

    // ========== IA (si demandé) ==========
    if ($method === 'ai' || $method === 'auto') {
        $aiResult = null;

        // Essayer Claude d'abord
        if (claude_available()) {
            poc_log("  Tentative Claude...");
            $aiResult = classify_with_claude($text, $filename);
        }

        // Fallback Ollama si Claude non dispo
        if (!$aiResult && ollama_chat_available()) {
            poc_log("  Fallback Ollama...");
            $aiResult = classify_with_ollama($text, $filename);
        }

        if ($aiResult) {
            $result['ai_result'] = $aiResult;
            poc_log("  IA ({$aiResult['method']}): type={$aiResult['document_type']}, conf=" . round($aiResult['confidence'] ?? 0.7, 2));

            if ($method === 'ai') {
                $result['final'] = $aiResult;
                $result['confidence'] = $aiResult['confidence'] ?? 0.7;
                $result['method_used'] = $aiResult['method'];
            } else {
                // Mode auto: fusionner
                $result['final'] = merge_classifications($result['rules_result'], $aiResult);
                $result['confidence'] = ($result['confidence'] + ($aiResult['confidence'] ?? 0.7)) / 2;
                $result['method_used'] = 'auto_merged';
            }
        } elseif ($method === 'ai') {
            // IA demandée mais non disponible, fallback règles
            $result['rules_result'] = classify_by_rules($text, $filename);
            $result['final'] = $result['rules_result'];
            $result['confidence'] = $result['rules_result']['confidence'] ?? 0;
            $result['method_used'] = 'rules_fallback';
        }
    }

    return $result;
}

/**
 * Fusionne les résultats règles + IA
 */
function merge_classifications(array $rules, array $ai): array {
    $merged = $rules;

    // Prendre les valeurs IA si règles vides
    foreach (['correspondent', 'date', 'amount', 'summary'] as $field) {
        if (empty($merged[$field]) && !empty($ai[$field])) {
            $merged[$field] = $ai[$field];
            $merged[$field . '_source'] = 'ai';
        }
    }

    // Utiliser le type IA si confiance plus haute
    if (($ai['confidence'] ?? 0) > ($rules['confidence'] ?? 0)) {
        $merged['document_type'] = $ai['document_type'];
        $merged['document_type_source'] = 'ai';
    }

    // Fusionner les tags
    $merged['tags'] = array_unique(array_merge($rules['tags'] ?? [], $ai['tags'] ?? []));

    return $merged;
}

// ============================================
// EXÉCUTION CLI
// ============================================

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($argv[0] ?? '')) {

    echo "\n";
    echo "================================================================\n";
    echo "  POC 03 - CLASSIFICATION IA (Claude + Ollama + Règles)\n";
    echo "================================================================\n\n";

    $cfg = poc_config();

    echo "--- PROVIDERS ---\n";
    echo "  Claude API: " . (claude_available() ? "OK" : "Non configuré") . "\n";
    echo "  Ollama Chat: " . (ollama_chat_available() ? "OK" : "Non disponible") . "\n";
    echo "  Méthode config: " . ($cfg['classification']['method'] ?? 'auto') . "\n";

    $testFile = $argv[1] ?? null;

    if ($testFile && file_exists($testFile)) {
        echo "\n--- TEST: $testFile ---\n";

        // Charger l'extraction
        require_once __DIR__ . '/02_ocr_extract.php';

        $extraction = extract_text($testFile);
        if (empty($extraction['text'])) {
            echo "Erreur: pas de texte extrait\n";
            exit(1);
        }

        echo "Texte extrait: " . $extraction['word_count'] . " mots\n\n";

        // Classification
        $result = classify_document($extraction['text'], basename($testFile), $cfg['classification']['method'] ?? 'auto');

        echo "--- RÉSULTAT ---\n";
        echo "Méthode: {$result['method_used']}\n";
        echo "Confiance: " . round($result['confidence'] * 100) . "%\n\n";

        if ($result['final']) {
            echo "Type: " . ($result['final']['document_type'] ?? '?') . "\n";
            echo "Correspondant: " . ($result['final']['correspondent'] ?? '-') . "\n";
            echo "Date: " . ($result['final']['date'] ?? '-') . "\n";
            echo "Montant: " . ($result['final']['amount'] ?? '-') . "\n";
            echo "Tags: " . implode(', ', $result['final']['tags'] ?? []) . "\n";
            if (!empty($result['final']['summary'])) {
                echo "Synthèse: " . $result['final']['summary'] . "\n";
            }
        }

        // Sauvegarder
        $outputFile = $cfg['poc']['output_dir'] . '/03_classify_result.json';
        file_put_contents($outputFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "\nRapport: $outputFile\n";

    } else {
        echo "\nUsage: php 03_ai_classify.php <chemin_fichier>\n";
        echo "\nExemples:\n";
        echo "  php 03_ai_classify.php samples/facture.pdf\n";
        echo "  php 03_ai_classify.php samples/contrat.docx\n";
    }

    echo "\n";
}

return 'classify_document';

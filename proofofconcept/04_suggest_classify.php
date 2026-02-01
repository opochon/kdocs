<?php
/**
 * 04_suggest_classify.php - CLASSIFICATION CASCADE
 *
 * Cascade : Anthropic (Claude) → Ollama → Règles
 *
 * USAGE:
 *   php 04_suggest_classify.php                 # Texte de démo
 *   php 04_suggest_classify.php "texte..."      # Texte direct
 *   php 04_suggest_classify.php fichier.pdf    # Depuis fichier
 */

require_once __DIR__ . '/helpers.php';

// ============================================
// PROMPT IA POUR CLASSIFICATION
// ============================================

function get_classification_prompt(string $text): string {
    // Tronquer si trop long
    $maxChars = 4000;
    $truncatedText = mb_strlen($text) > $maxChars
        ? mb_substr($text, 0, $maxChars) . "\n[... tronqué ...]"
        : $text;

    return <<<PROMPT
Analyse ce document et extrais les informations suivantes. Réponds UNIQUEMENT en JSON valide, sans texte avant ni après.

Format de réponse attendu :
{
  "type": "facture|contrat|courrier|rapport|releve|attestation|devis|autre",
  "confidence": 0.0 à 1.0,
  "correspondent": "Nom de l'entreprise/expéditeur ou null",
  "tags": ["tag1", "tag2", "tag3"],
  "fields": {
    "montant": 1234.56 ou null,
    "date_document": "YYYY-MM-DD" ou null,
    "reference": "numéro/référence" ou null,
    "iban": "IBAN détecté" ou null
  },
  "summary": "Résumé en 1-2 phrases"
}

Types possibles :
- facture : document de facturation, demande de paiement
- contrat : accord, convention, conditions générales
- courrier : lettre, correspondance, communication
- rapport : compte-rendu, analyse, étude
- releve : relevé bancaire, relevé de compte
- attestation : certificat, attestation officielle
- devis : offre de prix, proposition commerciale
- autre : si aucun type ne correspond

Document à analyser :
$truncatedText
PROMPT;
}

// ============================================
// CLASSIFICATION VIA ANTHROPIC (CLAUDE)
// ============================================

function classify_with_anthropic(string $text): ?array {
    if (!anthropic_available()) {
        poc_log("Anthropic non configuré, skip", 'DEBUG');
        return null;
    }

    poc_log("Classification via Anthropic...", 'INFO');
    $startTime = microtime(true);

    $prompt = get_classification_prompt($text);
    $response = anthropic_call($prompt);

    $elapsed = round((microtime(true) - $startTime) * 1000);

    if (!$response) {
        poc_log("Anthropic: pas de réponse ({$elapsed}ms)", 'WARN');
        return null;
    }

    $data = parse_ai_json($response);

    if (!$data || !isset($data['type'])) {
        poc_log("Anthropic: JSON invalide ({$elapsed}ms)", 'WARN');
        return null;
    }

    poc_log("Anthropic: OK - {$data['type']} ({$elapsed}ms)", 'INFO');

    return [
        'type' => $data['type'],
        'confidence' => (float)($data['confidence'] ?? 0.8),
        'method' => 'anthropic',
        'correspondent' => $data['correspondent'] ?? null,
        'tags' => $data['tags'] ?? [],
        'fields' => $data['fields'] ?? [],
        'summary' => $data['summary'] ?? null,
        'elapsed_ms' => $elapsed,
    ];
}

// ============================================
// CLASSIFICATION VIA OLLAMA
// ============================================

function classify_with_ollama(string $text): ?array {
    if (!ollama_available()) {
        poc_log("Ollama non disponible, skip", 'DEBUG');
        return null;
    }

    poc_log("Classification via Ollama...", 'INFO');
    $startTime = microtime(true);

    $prompt = get_classification_prompt($text);
    $response = ollama_generate($prompt);

    $elapsed = round((microtime(true) - $startTime) * 1000);

    if (!$response) {
        poc_log("Ollama: pas de réponse ({$elapsed}ms)", 'WARN');
        return null;
    }

    $data = parse_ai_json($response);

    if (!$data || !isset($data['type'])) {
        poc_log("Ollama: JSON invalide ({$elapsed}ms)", 'WARN');
        return null;
    }

    poc_log("Ollama: OK - {$data['type']} ({$elapsed}ms)", 'INFO');

    return [
        'type' => $data['type'],
        'confidence' => (float)($data['confidence'] ?? 0.7),
        'method' => 'ollama',
        'correspondent' => $data['correspondent'] ?? null,
        'tags' => $data['tags'] ?? [],
        'fields' => $data['fields'] ?? [],
        'summary' => $data['summary'] ?? null,
        'elapsed_ms' => $elapsed,
    ];
}

// ============================================
// CLASSIFICATION PAR RÈGLES
// ============================================

function classify_with_rules(string $text): array {
    poc_log("Classification via règles...", 'INFO');
    $startTime = microtime(true);

    $textLower = mb_strtolower($text);

    // Détection du type
    $typePatterns = [
        'facture' => ['facture', 'invoice', 'rechnung', 'total à payer', 'montant dû', 'n° facture', 'numéro de facture', 'à régler'],
        'contrat' => ['contrat', 'contract', 'vertrag', 'convention', 'accord', 'conditions générales', 'partie prenante'],
        'courrier' => ['madame', 'monsieur', 'cher', 'chère', 'cordialement', 'salutations', 'veuillez agréer'],
        'rapport' => ['rapport', 'report', 'analyse', 'étude', 'conclusion', 'recommandation'],
        'releve' => ['relevé', 'solde', 'extrait de compte', 'mouvement', 'débit', 'crédit'],
        'attestation' => ['atteste', 'certifie', 'attestation', 'certificat'],
        'devis' => ['devis', 'offre de prix', 'proposition', 'estimation', 'quote'],
    ];

    $detectedType = 'autre';
    $maxScore = 0;

    foreach ($typePatterns as $type => $patterns) {
        $score = 0;
        foreach ($patterns as $pattern) {
            if (mb_strpos($textLower, $pattern) !== false) {
                $score++;
            }
        }
        if ($score > $maxScore) {
            $maxScore = $score;
            $detectedType = $type;
        }
    }

    // Extraction champs
    $fields = [];

    // Montant
    $amountPatterns = [
        '/CHF\s*([\d\']+[\.,]\d{2})/',
        '/(?:Total|Montant|Amount)[:\s]*([\d\'\.]+[\.,]\d{2})/',
        '/([\d\.]+,\d{2})\s*(?:EUR|€|CHF)/',
    ];
    foreach ($amountPatterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $amount = str_replace(["'", " "], "", $m[1]);
            $amount = str_replace(",", ".", $amount);
            $fields['montant'] = (float)$amount;
            break;
        }
    }

    // Date
    $datePatterns = [
        '/(\d{1,2})[\/\.\-](\d{1,2})[\/\.\-](\d{4})/' => function($m) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        },
        '/(\d{4})-(\d{2})-(\d{2})/' => function($m) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        },
    ];
    foreach ($datePatterns as $pattern => $formatter) {
        if (preg_match($pattern, $text, $m)) {
            $fields['date_document'] = $formatter($m);
            break;
        }
    }

    // IBAN
    if (preg_match('/([A-Z]{2}\d{2}[\sA-Z0-9]{10,30})/', $text, $m)) {
        $iban = preg_replace('/\s+/', '', $m[1]);
        if (strlen($iban) >= 15 && strlen($iban) <= 34) {
            $fields['iban'] = $iban;
        }
    }

    // Référence
    if (preg_match('/(?:Réf|Ref|N°|No|Numéro)[.:\s]*([A-Z0-9\-\/]+)/i', $text, $m)) {
        $fields['reference'] = $m[1];
    }

    // Correspondant (patterns courants)
    $correspondent = null;
    $correspondentPatterns = [
        '/(?:^|\n)([A-Z][A-Za-zÀ-ÿ\s]+(?:SA|AG|Sàrl|GmbH|SAS|SARL|Ltd|Inc))/m',
        '/De\s*:\s*([A-Z][A-Za-zÀ-ÿ\s]+)/m',
    ];
    foreach ($correspondentPatterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $correspondent = trim($m[1]);
            break;
        }
    }

    // Tags auto
    $tags = [];
    $tagKeywords = [
        'urgent' => ['urgent', 'urgence', 'prioritaire'],
        'fiscal' => ['impôt', 'tva', 'fiscal', 'taxe'],
        'bancaire' => ['banque', 'iban', 'virement', 'compte'],
        'assurance' => ['assurance', 'police', 'sinistre', 'prime'],
        'immobilier' => ['loyer', 'bail', 'appartement', 'immeuble'],
    ];
    foreach ($tagKeywords as $tag => $keywords) {
        foreach ($keywords as $kw) {
            if (mb_strpos($textLower, $kw) !== false) {
                $tags[] = $tag;
                break;
            }
        }
    }

    $elapsed = round((microtime(true) - $startTime) * 1000);

    poc_log("Règles: OK - $detectedType ({$elapsed}ms)", 'INFO');

    return [
        'type' => $detectedType,
        'confidence' => min(0.6, 0.2 + $maxScore * 0.1),
        'method' => 'rules',
        'correspondent' => $correspondent,
        'tags' => array_slice($tags, 0, 5),
        'fields' => $fields,
        'summary' => null,
        'elapsed_ms' => $elapsed,
    ];
}

// ============================================
// FONCTION PRINCIPALE - CASCADE
// ============================================

/**
 * Classifie un document avec cascade : Anthropic → Ollama → Règles
 */
function classify_document(string $text, array $options = []): array {
    if (empty(trim($text))) {
        return [
            'type' => 'autre',
            'confidence' => 0.0,
            'method' => 'none',
            'error' => 'Texte vide',
        ];
    }

    $cfg = poc_config();
    $cascade = $cfg['ai']['cascade']['classification'] ?? ['anthropic', 'ollama', 'rules'];

    // Vérifier training en premier
    if (($cfg['ai']['training']['enabled'] ?? false) && function_exists('get_trained_classification')) {
        $trained = get_trained_classification($text);
        if ($trained) {
            poc_log("Classification via training (similarité haute)", 'INFO');
            return array_merge($trained, ['method' => 'training']);
        }
    }

    // Exécuter la cascade
    foreach ($cascade as $method) {
        $result = null;

        switch ($method) {
            case 'anthropic':
                $result = classify_with_anthropic($text);
                break;

            case 'ollama':
                $result = classify_with_ollama($text);
                break;

            case 'rules':
                $result = classify_with_rules($text);
                break;
        }

        if ($result && isset($result['type'])) {
            return $result;
        }
    }

    // Fallback absolu
    return classify_with_rules($text);
}

// ============================================
// UTILITAIRES EXTRACTION (compatibilité)
// ============================================

function extract_date(string $text): ?string {
    $patterns = [
        '/(\d{1,2})[\/\.\-](\d{1,2})[\/\.\-](\d{4})/' => fn($m) => sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]),
        '/(\d{4})-(\d{2})-(\d{2})/' => fn($m) => sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]),
    ];

    foreach ($patterns as $pattern => $formatter) {
        if (preg_match($pattern, $text, $m)) {
            return $formatter($m);
        }
    }
    return null;
}

function extract_amount(string $text): ?float {
    $patterns = [
        '/CHF\s*([\d\']+[\.,]\d{2})/',
        '/(?:Total|Montant)[:\s]*([\d\'\.]+[\.,]\d{2})/',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $amount = str_replace(["'", " ", ","], ["", "", "."], $m[1]);
            return (float)$amount;
        }
    }
    return null;
}

// ============================================
// EXÉCUTION CLI
// ============================================

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($argv[0] ?? '')) {

    echo "\n";
    echo "================================================================\n";
    echo "  POC 04 - CLASSIFICATION CASCADE\n";
    echo "  Anthropic -> Ollama -> Règles\n";
    echo "================================================================\n\n";

    $cfg = poc_config();

    // Status providers
    echo "--- PROVIDERS ---\n";
    poc_result("Anthropic (Claude)", anthropic_available(),
        anthropic_available() ? $cfg['ai']['anthropic']['model'] : 'Non configuré');
    poc_result("Ollama", ollama_available(),
        ollama_available() ? ($cfg['ai']['ollama']['model_generate'] ?? 'llama3.2') : 'Non disponible');
    echo "  Règles: Toujours disponible\n";

    // Texte de test
    $testText = $argv[1] ?? null;

    if ($testText && file_exists($testText)) {
        require_once __DIR__ . '/02_ocr_extract.php';
        $extraction = extract_text($testText);
        $text = $extraction['text'];
        $filename = basename($testText);
        echo "\n--- SOURCE ---\n";
        echo "  Fichier: $filename\n";
    } elseif ($testText) {
        $text = $testText;
        echo "\n--- SOURCE ---\n";
        echo "  Texte direct\n";
    } else {
        $text = <<<TEXT
FACTURE N° 2024-001

Swisscom (Suisse) SA
Case postale
3050 Berne

Date: 15 janvier 2024

Objet: Services de télécommunications - Janvier 2024

Détail des prestations:
- Abonnement mobile illimité: CHF 49.00
- Roaming données (5 Go): CHF 25.50
- Options supplémentaires: CHF 51.00

Sous-total: CHF 125.50
TVA 8.1%: CHF 10.17

TOTAL À PAYER: CHF 135.67

Paiement à 30 jours sur compte:
IBAN: CH93 0076 2011 6238 5295 7

Merci de votre confiance.
TEXT;
        echo "\n--- SOURCE ---\n";
        echo "  Texte de démonstration (facture)\n";
    }

    echo "\n--- TEXTE (extrait) ---\n";
    echo mb_substr($text, 0, 300) . (mb_strlen($text) > 300 ? '...' : '') . "\n";

    // Classification
    echo "\n--- CLASSIFICATION ---\n";
    $result = classify_document($text);

    echo "\n";
    poc_result("Type", true, "{$result['type']} (confiance: " . round($result['confidence'] * 100) . "%)");
    poc_result("Méthode", true, $result['method']);

    if (!empty($result['correspondent'])) {
        poc_result("Correspondant", true, $result['correspondent']);
    }

    if (!empty($result['tags'])) {
        poc_result("Tags", true, implode(', ', $result['tags']));
    }

    if (!empty($result['fields'])) {
        echo "\n--- CHAMPS EXTRAITS ---\n";
        foreach ($result['fields'] as $key => $value) {
            if ($value !== null) {
                $display = is_numeric($value) ? number_format($value, 2) : $value;
                poc_result($key, true, $display);
            }
        }
    }

    if (!empty($result['summary'])) {
        echo "\n--- RÉSUMÉ ---\n";
        echo "  " . $result['summary'] . "\n";
    }

    // Sauvegarder
    $outputFile = $cfg['poc']['output_dir'] . '/04_classification_result.json';
    file_put_contents($outputFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "\nRapport: $outputFile\n";

    echo "\n";
}

return 'classify_document';

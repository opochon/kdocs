<?php
/**
 * POC Helpers - Fonctions utilitaires partagées
 */

/**
 * Charge la config POC
 */
function poc_config(): array {
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    return $config;
}

/**
 * Connexion DB POC (PDO)
 */
function poc_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $cfg = poc_config()['db'];
        $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset={$cfg['charset']}";
        $pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

/**
 * Nettoie et force l'encodage UTF-8
 */
function ensure_utf8(string $text): string {
    if (empty($text)) return '';

    // Détecter l'encodage actuel
    $encoding = mb_detect_encoding($text, ['UTF-8', 'ISO-8859-1', 'ISO-8859-15', 'Windows-1252', 'ASCII'], true);

    // Convertir vers UTF-8 si nécessaire
    if ($encoding && $encoding !== 'UTF-8') {
        $text = mb_convert_encoding($text, 'UTF-8', $encoding);
    }

    // Si toujours pas valide, forcer conversion depuis Windows-1252 (courant sur Windows)
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }

    // Nettoyer les caractères de contrôle (sauf newlines et tabs)
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

    return $text;
}

/**
 * Log POC
 */
function poc_log(string $message, string $level = 'INFO'): void {
    $cfg = poc_config();
    $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $message);
    
    if ($cfg['poc']['verbose']) {
        echo $line;
    }
    
    file_put_contents($cfg['poc']['log_file'], $line, FILE_APPEND);
}

/**
 * Vérifie si un outil externe existe
 */
function poc_tool_exists(string $tool): bool {
    $cfg = poc_config();
    $path = $cfg['tools'][$tool] ?? null;
    return $path && file_exists($path);
}

/**
 * Exécute une commande et retourne le résultat
 */
function poc_exec(string $cmd, ?int &$returnCode = null): string {
    exec($cmd . ' 2>&1', $output, $returnCode);
    return implode("\n", $output);
}

/**
 * Hash MD5 d'un fichier (pour détection changements)
 */
function poc_file_hash(string $path): ?string {
    if (!file_exists($path)) return null;
    return md5_file($path);
}

/**
 * Métadonnées d'un fichier
 */
function poc_file_meta(string $path): ?array {
    if (!file_exists($path)) return null;
    
    $stat = stat($path);
    return [
        'path' => $path,
        'filename' => basename($path),
        'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
        'size' => $stat['size'],
        'mtime' => $stat['mtime'],
        'mtime_iso' => date('Y-m-d H:i:s', $stat['mtime']),
        'hash' => poc_file_hash($path),
    ];
}

/**
 * Appel API Ollama avec retry et gestion erreurs
 */
function poc_ollama_call(string $endpoint, array $data, int $maxRetries = 2): ?array {
    $cfg = poc_config();
    $url = rtrim($cfg['ollama']['url'], '/') . $endpoint;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => $cfg['ollama']['timeout'] ?? 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200) {
            $decoded = json_decode($response, true);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        // Log erreur
        if ($httpCode === 500) {
            poc_log("Ollama HTTP 500 (attempt $attempt/$maxRetries) - serveur surcharge", 'WARN');
        } elseif ($curlError) {
            poc_log("Ollama curl error: $curlError", 'WARN');
        } elseif ($httpCode !== 200) {
            poc_log("Ollama HTTP $httpCode (attempt $attempt/$maxRetries)", 'WARN');
        }

        // Attendre avant retry
        if ($attempt < $maxRetries) {
            usleep(500000); // 500ms
        }
    }

    poc_log("Ollama: echec apres $maxRetries tentatives", 'ERROR');
    return null;
}

/**
 * Affiche un résultat de test
 */
function poc_result(string $name, bool $success, string $detail = ''): void {
    $icon = $success ? "\033[32m✓\033[0m" : "\033[31m✗\033[0m";
    echo "$icon $name";
    if ($detail) echo " - $detail";
    echo "\n";
}

/**
 * Vérifie si Ollama est disponible
 */
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

/**
 * Vérifie si OpenAI est configuré
 */
function openai_available(): bool {
    $cfg = poc_config();
    return !empty($cfg['openai']['api_key']);
}

/**
 * Génère embedding via OpenAI
 */
function generate_embedding_openai(string $text): ?array {
    $cfg = poc_config();
    $apiKey = $cfg['openai']['api_key'] ?? '';

    if (empty($apiKey)) {
        return null;
    }

    // Tronquer si trop long (~8000 tokens * 4 chars = 32000 chars)
    $maxChars = 30000;
    $truncatedText = mb_substr($text, 0, $maxChars);

    $payload = [
        'model' => $cfg['openai']['model'] ?? 'text-embedding-3-small',
        'input' => $truncatedText,
    ];

    // Dimensions pour les modèles v3
    if (str_contains($payload['model'], 'text-embedding-3')) {
        $payload['dimensions'] = $cfg['openai']['dimensions'] ?? 1536;
    }

    $ch = curl_init('https://api.openai.com/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => $cfg['openai']['timeout'] ?? 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        poc_log("OpenAI curl error: $curlError", 'ERROR');
        return null;
    }

    if ($httpCode !== 200) {
        $error = json_decode($response, true);
        poc_log("OpenAI HTTP $httpCode: " . ($error['error']['message'] ?? $response), 'ERROR');
        return null;
    }

    $data = json_decode($response, true);

    if (!isset($data['data'][0]['embedding'])) {
        poc_log("OpenAI: réponse invalide", 'ERROR');
        return null;
    }

    return $data['data'][0]['embedding'];
}

/**
 * Génère embedding via Ollama
 */
function generate_embedding_ollama(string $text): ?array {
    if (empty(trim($text))) return null;
    $cfg = poc_config();

    // Tronquer si trop long (limite Ollama ~8000 tokens ≈ 6000 chars pour nomic-embed-text)
    $maxChars = $cfg['ollama']['max_chars'] ?? 6000;
    $truncatedText = mb_substr($text, 0, $maxChars);

    $result = poc_ollama_call('/api/embeddings', [
        'model' => $cfg['ollama']['model'],
        'prompt' => $truncatedText,
    ]);
    return $result['embedding'] ?? null;
}

/**
 * Génère embedding (auto-sélection provider)
 * Équivalent à EmbeddingService::embed()
 */
function generate_embedding(string $text): ?array {
    if (empty(trim($text))) return null;

    $cfg = poc_config();
    $provider = $cfg['embeddings']['provider'] ?? 'ollama';
    $fallback = $cfg['embeddings']['fallback'] ?? true;

    // Provider principal
    if ($provider === 'openai' && openai_available()) {
        $embedding = generate_embedding_openai($text);
        if ($embedding) {
            return $embedding;
        }
        // Fallback Ollama si OpenAI échoue
        if ($fallback && ollama_available()) {
            poc_log("OpenAI échoué, fallback Ollama", 'WARN');
            return generate_embedding_ollama($text);
        }
    } else {
        // Provider Ollama (ou fallback)
        if (ollama_available()) {
            $embedding = generate_embedding_ollama($text);
            if ($embedding) {
                return $embedding;
            }
        }
        // Fallback OpenAI si Ollama échoue
        if ($fallback && openai_available()) {
            poc_log("Ollama échoué, fallback OpenAI", 'WARN');
            return generate_embedding_openai($text);
        }
    }

    return null;
}

/**
 * Retourne les infos du provider d'embedding actuel
 */
function get_embedding_provider_info(): array {
    $cfg = poc_config();
    $provider = $cfg['embeddings']['provider'] ?? 'ollama';

    if ($provider === 'openai' && openai_available()) {
        return [
            'provider' => 'openai',
            'model' => $cfg['openai']['model'] ?? 'text-embedding-3-small',
            'dimensions' => $cfg['openai']['dimensions'] ?? 1536,
            'available' => true,
        ];
    }

    if (ollama_available()) {
        return [
            'provider' => 'ollama',
            'model' => $cfg['ollama']['model'] ?? 'nomic-embed-text',
            'dimensions' => $cfg['ollama']['dimensions'] ?? 768,
            'available' => true,
        ];
    }

    return [
        'provider' => 'none',
        'model' => null,
        'dimensions' => 0,
        'available' => false,
    ];
}

/**
 * Similarité cosinus entre deux vecteurs
 */
function cosine_similarity(array $a, array $b): float {
    if (count($a) !== count($b) || empty($a)) return 0.0;

    $dot = 0.0;
    $normA = 0.0;
    $normB = 0.0;

    for ($i = 0; $i < count($a); $i++) {
        $dot += $a[$i] * $b[$i];
        $normA += $a[$i] * $a[$i];
        $normB += $b[$i] * $b[$i];
    }

    $normA = sqrt($normA);
    $normB = sqrt($normB);

    return ($normA > 0 && $normB > 0) ? $dot / ($normA * $normB) : 0.0;
}

/**
 * Vérifie si Anthropic (Claude) est configuré et activé
 */
function anthropic_available(): bool {
    $cfg = poc_config();
    $aiCfg = $cfg['ai']['anthropic'] ?? [];
    return ($aiCfg['enabled'] ?? false) && !empty($aiCfg['api_key']);
}

/**
 * Appel API Anthropic (Claude)
 */
function anthropic_call(string $prompt, array $options = []): ?string {
    $cfg = poc_config();
    $aiCfg = $cfg['ai']['anthropic'] ?? [];

    if (!anthropic_available()) {
        return null;
    }

    $apiKey = $aiCfg['api_key'];
    $model = $options['model'] ?? $aiCfg['model'] ?? 'claude-sonnet-4-20250514';
    $maxTokens = $options['max_tokens'] ?? $aiCfg['max_tokens'] ?? 2000;
    $timeout = $options['timeout'] ?? $aiCfg['timeout'] ?? 60;

    $payload = [
        'model' => $model,
        'max_tokens' => $maxTokens,
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
    ];

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
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        poc_log("Anthropic curl error: $curlError", 'ERROR');
        return null;
    }

    if ($httpCode !== 200) {
        $error = json_decode($response, true);
        poc_log("Anthropic HTTP $httpCode: " . ($error['error']['message'] ?? $response), 'ERROR');
        return null;
    }

    $data = json_decode($response, true);

    if (!isset($data['content'][0]['text'])) {
        poc_log("Anthropic: réponse invalide", 'ERROR');
        return null;
    }

    return $data['content'][0]['text'];
}

/**
 * Appel Ollama pour génération de texte (chat/generate)
 */
function ollama_generate(string $prompt, array $options = []): ?string {
    $cfg = poc_config();
    $aiCfg = $cfg['ai']['ollama'] ?? $cfg['ollama'] ?? [];

    if (!ollama_available()) {
        return null;
    }

    $model = $options['model'] ?? $aiCfg['model_generate'] ?? 'llama3.2';
    $timeout = $options['timeout'] ?? $aiCfg['timeout'] ?? 30;

    $response = poc_ollama_call('/api/generate', [
        'model' => $model,
        'prompt' => $prompt,
        'stream' => false,
    ]);

    return $response['response'] ?? null;
}

/**
 * Parse une réponse AI en JSON, avec nettoyage
 */
function parse_ai_json(string $text): ?array {
    // Nettoyer la réponse
    $text = trim($text);

    // Extraire le JSON si entouré de markdown code blocks
    if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $text, $matches)) {
        $text = trim($matches[1]);
    }

    // Essayer de parser directement
    $data = @json_decode($text, true);
    if ($data !== null) {
        return $data;
    }

    // Chercher un objet JSON dans le texte
    if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
        $data = @json_decode($matches[0], true);
        if ($data !== null) {
            return $data;
        }
    }

    poc_log("parse_ai_json: impossible de parser la réponse", 'WARN');
    return null;
}

/**
 * Nettoie les dossiers temporaires orphelins
 */
function poc_cleanup_temp(): int {
    $cfg = poc_config();
    $outputDir = $cfg['poc']['output_dir'];
    $cleaned = 0;

    // Patterns de dossiers temp à nettoyer
    $patterns = ['lo_*', 'pages_*', 'ocr_*', 'thumb_temp_*', 'temp_*'];

    foreach ($patterns as $pattern) {
        $dirs = glob($outputDir . '/' . $pattern, GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            // Supprimer contenu
            $files = glob($dir . '/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            // Supprimer dossier
            if (@rmdir($dir)) {
                $cleaned++;
            }
        }
    }

    return $cleaned;
}

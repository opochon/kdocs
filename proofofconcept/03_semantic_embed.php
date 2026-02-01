<?php
/**
 * 03_semantic_embed.php
 * 
 * OBJECTIF: Générer embedding sémantique via Ollama
 * 
 * ORIGINE: Logique inspirée de EmbeddingService.php
 * CIBLE: Services/EmbeddingService.php (amélioration après validation POC)
 * 
 * ENTRÉE: 
 *   - Texte à vectoriser
 * 
 * SORTIE:
 *   - {embedding: float[768], model: string, dimensions: int}
 * 
 * STOCKAGE:
 *   - MySQL BLOB (documents.embedding)
 *   - Format: pack('f*', ...$vector) pour efficacité
 * 
 * SIDE EFFECTS POTENTIELS:
 *   - Requête HTTP vers Ollama (localhost)
 */

require_once __DIR__ . '/helpers.php';

// ============================================
// FONCTIONS
// ============================================

/**
 * Vérifie si Ollama est disponible
 */
function ollama_available(): bool {
    $cfg = poc_config();
    $ch = curl_init($cfg['ollama']['url'] . '/api/tags');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode === 200;
}

/**
 * Vérifie si le modèle est installé
 */
function ollama_model_available(): bool {
    $cfg = poc_config();
    $response = poc_ollama_call('/api/tags', []);
    
    if (!$response || !isset($response['models'])) {
        return false;
    }
    
    $model = $cfg['ollama']['model'];
    foreach ($response['models'] as $m) {
        if (strpos($m['name'], $model) !== false) {
            return true;
        }
    }
    
    return false;
}

/**
 * Génère un embedding pour un texte
 */
function generate_embedding(string $text): ?array {
    $cfg = poc_config();
    
    if (empty(trim($text))) {
        poc_log("Texte vide, pas d'embedding", 'WARN');
        return null;
    }
    
    // Tronquer si trop long (limite modèle ~8000 tokens)
    $maxChars = 30000;
    if (mb_strlen($text) > $maxChars) {
        $text = mb_substr($text, 0, $maxChars);
        poc_log("Texte tronqué à $maxChars caractères", 'INFO');
    }
    
    $startTime = microtime(true);
    
    $response = poc_ollama_call('/api/embeddings', [
        'model' => $cfg['ollama']['model'],
        'prompt' => $text,
    ]);
    
    $elapsed = round((microtime(true) - $startTime) * 1000);
    
    if (!$response || !isset($response['embedding'])) {
        poc_log("Ollama embedding failed", 'ERROR');
        return null;
    }
    
    $embedding = $response['embedding'];
    
    return [
        'embedding' => $embedding,
        'model' => $cfg['ollama']['model'],
        'dimensions' => count($embedding),
        'elapsed_ms' => $elapsed,
    ];
}

/**
 * Convertit embedding en BLOB pour MySQL
 */
function embedding_to_blob(array $embedding): string {
    return pack('f*', ...$embedding);
}

/**
 * Convertit BLOB MySQL en embedding
 */
function blob_to_embedding(string $blob): array {
    return array_values(unpack('f*', $blob));
}

/**
 * Calcule similarité cosinus entre deux vecteurs
 */
function cosine_similarity(array $a, array $b): float {
    if (count($a) !== count($b) || count($a) === 0) {
        return 0.0;
    }
    
    $dotProduct = 0.0;
    $normA = 0.0;
    $normB = 0.0;
    
    for ($i = 0; $i < count($a); $i++) {
        $dotProduct += $a[$i] * $b[$i];
        $normA += $a[$i] * $a[$i];
        $normB += $b[$i] * $b[$i];
    }
    
    $normA = sqrt($normA);
    $normB = sqrt($normB);
    
    if ($normA == 0 || $normB == 0) {
        return 0.0;
    }
    
    return $dotProduct / ($normA * $normB);
}

/**
 * Recherche sémantique dans les embeddings stockés
 */
function semantic_search(array $queryEmbedding, int $limit = 10): array {
    $db = poc_db();
    
    // Récupérer tous les documents avec embedding
    $stmt = $db->query("
        SELECT id, title, embedding 
        FROM documents 
        WHERE embedding IS NOT NULL AND deleted_at IS NULL
    ");
    
    $results = [];
    
    while ($row = $stmt->fetch()) {
        if (empty($row['embedding'])) continue;
        
        $docEmbedding = blob_to_embedding($row['embedding']);
        $similarity = cosine_similarity($queryEmbedding, $docEmbedding);
        
        $results[] = [
            'id' => $row['id'],
            'title' => $row['title'],
            'similarity' => $similarity,
        ];
    }
    
    // Trier par similarité décroissante
    usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
    
    return array_slice($results, 0, $limit);
}

// ============================================
// EXÉCUTION (si appelé directement)
// ============================================

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($argv[0] ?? '')) {
    
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════╗\n";
    echo "║  POC 03 - EMBEDDING SÉMANTIQUE                               ║\n";
    echo "╚══════════════════════════════════════════════════════════════╝\n\n";
    
    $cfg = poc_config();
    
    // Vérifier Ollama
    echo "--- VÉRIFICATION OLLAMA ---\n";
    poc_result("Ollama accessible", ollama_available(), $cfg['ollama']['url']);
    poc_result("Modèle installé", ollama_model_available(), $cfg['ollama']['model']);
    
    if (!ollama_available()) {
        echo "\nOllama non accessible. Lancez: ollama serve\n";
        exit(1);
    }
    
    if (!ollama_model_available()) {
        echo "\nModèle non installé. Lancez: ollama pull {$cfg['ollama']['model']}\n";
        exit(1);
    }
    
    // Test embedding
    echo "\n--- TEST EMBEDDING ---\n";
    
    $testText = $argv[1] ?? "Ceci est un document de test pour vérifier le fonctionnement des embeddings sémantiques.";
    
    if (file_exists($testText)) {
        // Si c'est un fichier, lire le contenu
        $testText = file_get_contents($testText);
    }
    
    poc_log("Texte: " . mb_substr($testText, 0, 100) . "...");
    
    $result = generate_embedding($testText);
    
    if ($result) {
        poc_result("Embedding généré", true, "{$result['dimensions']} dimensions en {$result['elapsed_ms']}ms");
        
        // Afficher quelques valeurs
        echo "Premiers éléments: [" . implode(', ', array_map(fn($v) => round($v, 4), array_slice($result['embedding'], 0, 5))) . "...]\n";
        
        // Test stockage BLOB
        $blob = embedding_to_blob($result['embedding']);
        $restored = blob_to_embedding($blob);
        $blobOk = count($restored) === $result['dimensions'];
        poc_result("Conversion BLOB", $blobOk, strlen($blob) . " bytes");
        
        // Test similarité avec lui-même (doit être 1.0)
        $selfSim = cosine_similarity($result['embedding'], $result['embedding']);
        poc_result("Auto-similarité", abs($selfSim - 1.0) < 0.001, round($selfSim, 4));
        
        // Sauvegarder
        $outputFile = poc_config()['poc']['output_dir'] . '/03_embedding_result.json';
        file_put_contents($outputFile, json_encode([
            'text_sample' => mb_substr($testText, 0, 500),
            'model' => $result['model'],
            'dimensions' => $result['dimensions'],
            'elapsed_ms' => $result['elapsed_ms'],
            'embedding_sample' => array_slice($result['embedding'], 0, 10),
        ], JSON_PRETTY_PRINT));
        echo "\nRapport: $outputFile\n";
        
        // Test recherche sémantique si des embeddings existent en DB
        echo "\n--- TEST RECHERCHE SÉMANTIQUE ---\n";
        $searchResults = semantic_search($result['embedding'], 5);
        
        if (empty($searchResults)) {
            echo "Aucun document avec embedding en DB (normal si dry_run)\n";
        } else {
            foreach ($searchResults as $i => $sr) {
                echo sprintf("%d. [%.3f] %s (ID:%d)\n", $i + 1, $sr['similarity'], $sr['title'], $sr['id']);
            }
        }
        
    } else {
        poc_result("Embedding généré", false, "Échec");
    }
    
    echo "\n✓ POC 03 terminé\n\n";
}

// Export pour chaînage
return 'generate_embedding';

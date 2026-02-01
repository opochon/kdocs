<?php
/**
 * 04_search.php - RECHERCHE FULLTEXT + SÉMANTIQUE
 *
 * Équivalent POC de:
 * - SearchService.php (recherche hybride)
 * - VectorSearchService.php (recherche vectorielle)
 *
 * LOGIQUE:
 *   1. Recherche FULLTEXT MySQL (rapide, mots-clés)
 *   2. Recherche sémantique via embeddings (intelligente)
 *   3. Mode hybride (combinaison des deux)
 */

require_once __DIR__ . '/helpers.php';

// ============================================
// RECHERCHE FULLTEXT MYSQL
// ============================================

/**
 * Recherche FULLTEXT sur la table documents
 * Équivalent à SearchService::search()
 */
function search_fulltext(string $query, int $limit = 20): array {
    $cfg = poc_config();

    if (!($cfg['search']['fulltext_enabled'] ?? true)) {
        return [];
    }

    try {
        $pdo = poc_db();

        // Échapper la requête pour MATCH AGAINST
        $searchTerm = $query;

        // Mode boolean pour recherche avancée
        // Index FULLTEXT sur (title, ocr_text, content)
        $stmt = $pdo->prepare("
            SELECT
                id,
                title,
                original_filename,
                MATCH(title, ocr_text, content) AGAINST(:query IN NATURAL LANGUAGE MODE) AS relevance
            FROM documents
            WHERE deleted_at IS NULL
            AND MATCH(title, ocr_text, content) AGAINST(:query2 IN NATURAL LANGUAGE MODE)
            ORDER BY relevance DESC
            LIMIT :limit
        ");

        $stmt->bindValue(':query', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(':query2', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function($row) {
            return [
                'id' => $row['id'],
                'title' => $row['title'],
                'filename' => $row['original_filename'],
                'score' => (float) $row['relevance'],
                'method' => 'fulltext',
            ];
        }, $results);

    } catch (PDOException $e) {
        poc_log("FULLTEXT search error: " . $e->getMessage(), 'ERROR');
        return [];
    }
}

/**
 * Recherche FULLTEXT simple (LIKE fallback si pas d'index FULLTEXT)
 */
function search_like(string $query, int $limit = 20): array {
    try {
        $pdo = poc_db();

        $searchTerm = '%' . $query . '%';

        $stmt = $pdo->prepare("
            SELECT
                id,
                title,
                original_filename
            FROM documents
            WHERE deleted_at IS NULL
            AND (title LIKE :query OR ocr_text LIKE :query2 OR content LIKE :query3 OR original_filename LIKE :query4)
            LIMIT :limit
        ");

        $stmt->bindValue(':query', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(':query2', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(':query3', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(':query4', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function($row) {
            return [
                'id' => $row['id'],
                'title' => $row['title'],
                'filename' => $row['original_filename'],
                'score' => 1.0,
                'method' => 'like',
            ];
        }, $results);

    } catch (PDOException $e) {
        poc_log("LIKE search error: " . $e->getMessage(), 'ERROR');
        return [];
    }
}

// ============================================
// RECHERCHE SÉMANTIQUE (EMBEDDINGS)
// ============================================

/**
 * Recherche sémantique via embeddings
 * Équivalent à VectorSearchService::search()
 */
function search_semantic(string $query, int $limit = 10): array {
    $cfg = poc_config();

    if (!($cfg['search']['semantic_enabled'] ?? true)) {
        return [];
    }

    // Générer embedding de la requête
    $queryEmbedding = generate_embedding($query);
    if (!$queryEmbedding) {
        poc_log("Semantic search: impossible de générer embedding requête", 'WARN');
        return [];
    }

    // Récupérer les embeddings des documents depuis la DB
    try {
        $pdo = poc_db();

        $stmt = $pdo->query("
            SELECT id, title, original_filename, embedding
            FROM documents
            WHERE deleted_at IS NULL
            AND embedding IS NOT NULL
            AND LENGTH(embedding) > 10
        ");

        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($documents)) {
            poc_log("Semantic search: aucun document avec embedding", 'WARN');
            return [];
        }

        // Calculer similarité pour chaque document
        $results = [];
        $threshold = $cfg['search']['semantic_threshold'] ?? 0.5;

        foreach ($documents as $doc) {
            $docEmbedding = unserialize_embedding($doc['embedding']);
            if (!$docEmbedding) continue;

            $similarity = cosine_similarity($queryEmbedding, $docEmbedding);

            if ($similarity >= $threshold) {
                $results[] = [
                    'id' => $doc['id'],
                    'title' => $doc['title'],
                    'filename' => $doc['original_filename'],
                    'score' => $similarity,
                    'method' => 'semantic',
                ];
            }
        }

        // Trier par similarité décroissante
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

        // Limiter les résultats
        return array_slice($results, 0, $limit);

    } catch (PDOException $e) {
        poc_log("Semantic search error: " . $e->getMessage(), 'ERROR');
        return [];
    }
}

/**
 * Désérialise un embedding stocké en DB
 */
function unserialize_embedding($data): ?array {
    if (empty($data)) return null;

    // Format JSON
    $decoded = @json_decode($data, true);
    if (is_array($decoded) && !empty($decoded)) {
        return $decoded;
    }

    // Format PHP serialize
    $unserialized = @unserialize($data);
    if (is_array($unserialized) && !empty($unserialized)) {
        return $unserialized;
    }

    // Format binaire (BLOB de floats)
    if (strlen($data) > 100) {
        $floats = unpack('f*', $data);
        if ($floats && count($floats) > 100) {
            return array_values($floats);
        }
    }

    return null;
}

// ============================================
// RECHERCHE HYBRIDE
// ============================================

/**
 * Recherche hybride (FULLTEXT + sémantique)
 * Équivalent à SearchService avec hybrid_mode
 */
function search_hybrid(string $query, int $limit = 20): array {
    $cfg = poc_config();

    $fulltextResults = [];
    $semanticResults = [];

    // 1. Recherche FULLTEXT
    if ($cfg['search']['fulltext_enabled'] ?? true) {
        $fulltextResults = search_fulltext($query, $limit);
        if (empty($fulltextResults)) {
            // Fallback LIKE si pas de résultats FULLTEXT
            $fulltextResults = search_like($query, $limit);
        }
    }

    // 2. Recherche sémantique (si < 3 résultats FULLTEXT ou mode hybride forcé)
    if ($cfg['search']['semantic_enabled'] ?? true) {
        if (count($fulltextResults) < 3 || ($cfg['search']['hybrid_mode'] ?? false)) {
            $semanticResults = search_semantic($query, $limit);
        }
    }

    // 3. Fusionner et dédupliquer
    $merged = [];
    $seenIds = [];

    // Ajouter résultats FULLTEXT d'abord
    foreach ($fulltextResults as $result) {
        if (!in_array($result['id'], $seenIds)) {
            $merged[] = $result;
            $seenIds[] = $result['id'];
        }
    }

    // Ajouter résultats sémantiques
    foreach ($semanticResults as $result) {
        if (!in_array($result['id'], $seenIds)) {
            $merged[] = $result;
            $seenIds[] = $result['id'];
        }
    }

    // Limiter
    return array_slice($merged, 0, $limit);
}

// ============================================
// UTILITAIRES
// ============================================

/**
 * Compte le nombre de documents avec embedding
 */
function count_documents_with_embedding(): int {
    try {
        $pdo = poc_db();
        $stmt = $pdo->query("
            SELECT COUNT(*) FROM documents
            WHERE deleted_at IS NULL
            AND embedding IS NOT NULL
            AND LENGTH(embedding) > 10
        ");
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Vérifie si l'index FULLTEXT existe
 */
function fulltext_index_exists(): bool {
    try {
        $pdo = poc_db();
        $stmt = $pdo->query("
            SHOW INDEX FROM documents
            WHERE Index_type = 'FULLTEXT'
        ");
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// ============================================
// EXÉCUTION CLI
// ============================================

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($argv[0] ?? '')) {

    echo "\n";
    echo "================================================================\n";
    echo "  POC 04 - RECHERCHE FULLTEXT + SÉMANTIQUE\n";
    echo "================================================================\n\n";

    $cfg = poc_config();

    echo "--- CONFIGURATION ---\n";
    echo "  FULLTEXT: " . (($cfg['search']['fulltext_enabled'] ?? true) ? 'Activé' : 'Désactivé') . "\n";
    echo "  Index FULLTEXT: " . (fulltext_index_exists() ? 'OK' : 'Absent') . "\n";
    echo "  Sémantique: " . (($cfg['search']['semantic_enabled'] ?? true) ? 'Activé' : 'Désactivé') . "\n";
    echo "  Documents avec embedding: " . count_documents_with_embedding() . "\n";
    echo "  Mode hybride: " . (($cfg['search']['hybrid_mode'] ?? false) ? 'Activé' : 'Désactivé') . "\n";

    $providerInfo = get_embedding_provider_info();
    echo "  Provider embedding: {$providerInfo['provider']} ({$providerInfo['model']})\n";

    $query = $argv[1] ?? 'facture';

    echo "\n--- RECHERCHE: \"$query\" ---\n\n";

    // Test FULLTEXT
    echo "1. FULLTEXT:\n";
    $start = microtime(true);
    $fulltextResults = search_fulltext($query, 5);
    $elapsed = round((microtime(true) - $start) * 1000);
    echo "   Résultats: " . count($fulltextResults) . " ({$elapsed}ms)\n";
    foreach (array_slice($fulltextResults, 0, 3) as $r) {
        echo "   - [{$r['id']}] {$r['title']} (score: " . round($r['score'], 2) . ")\n";
    }

    // Test sémantique
    echo "\n2. SÉMANTIQUE:\n";
    $start = microtime(true);
    $semanticResults = search_semantic($query, 5);
    $elapsed = round((microtime(true) - $start) * 1000);
    echo "   Résultats: " . count($semanticResults) . " ({$elapsed}ms)\n";
    foreach (array_slice($semanticResults, 0, 3) as $r) {
        echo "   - [{$r['id']}] {$r['title']} (sim: " . round($r['score'], 3) . ")\n";
    }

    // Test hybride
    echo "\n3. HYBRIDE:\n";
    $start = microtime(true);
    $hybridResults = search_hybrid($query, 10);
    $elapsed = round((microtime(true) - $start) * 1000);
    echo "   Résultats: " . count($hybridResults) . " ({$elapsed}ms)\n";
    foreach (array_slice($hybridResults, 0, 5) as $r) {
        echo "   - [{$r['id']}] {$r['title']} ({$r['method']}, score: " . round($r['score'], 3) . ")\n";
    }

    // Sauvegarder
    $report = [
        'query' => $query,
        'fulltext' => $fulltextResults,
        'semantic' => $semanticResults,
        'hybrid' => $hybridResults,
    ];
    $outputFile = $cfg['poc']['output_dir'] . '/04_search_result.json';
    file_put_contents($outputFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "\nRapport: $outputFile\n";

    echo "\n";
}

return 'search_hybrid';

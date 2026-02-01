<?php
/**
 * 08_batch_embeddings.php - GÉNÉRATION EMBEDDINGS EN BATCH
 *
 * Génère les embeddings pour tous les documents de la base
 * qui n'en ont pas encore.
 *
 * USAGE:
 *   php 08_batch_embeddings.php          # Génère pour tous
 *   php 08_batch_embeddings.php 10       # Limite à 10 documents
 *   php 08_batch_embeddings.php --dry    # Mode simulation
 */

require_once __DIR__ . '/helpers.php';

/**
 * Convertit embedding en BLOB pour MySQL
 */
function embedding_to_blob(array $embedding): string {
    return pack('f*', ...$embedding);
}

/**
 * Récupère les documents sans embedding
 */
function get_documents_without_embedding(int $limit = 0): array {
    $pdo = poc_db();

    $sql = "
        SELECT id, title, original_filename, ocr_text, content
        FROM documents
        WHERE deleted_at IS NULL
        AND (embedding IS NULL OR LENGTH(embedding) < 10)
        ORDER BY id ASC
    ";

    if ($limit > 0) {
        $sql .= " LIMIT " . (int)$limit;
    }

    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Récupère le texte indexable d'un document
 */
function get_document_text(array $doc): string {
    $parts = [];

    // Titre
    if (!empty($doc['title'])) {
        $parts[] = $doc['title'];
    }

    // Texte OCR
    if (!empty($doc['ocr_text'])) {
        $parts[] = $doc['ocr_text'];
    }

    // Contenu
    if (!empty($doc['content'])) {
        $parts[] = $doc['content'];
    }

    $text = implode("\n\n", $parts);
    return ensure_utf8(trim($text));
}

/**
 * Stocke l'embedding dans la base
 */
function store_embedding(int $docId, array $embedding, string $model): bool {
    $pdo = poc_db();

    $blob = embedding_to_blob($embedding);

    $stmt = $pdo->prepare("
        UPDATE documents
        SET
            embedding = :embedding,
            embedding_model = :model,
            embedding_status = 'completed',
            embedding_updated_at = NOW(),
            embedding_error = NULL
        WHERE id = :id
    ");

    return $stmt->execute([
        ':embedding' => $blob,
        ':model' => $model,
        ':id' => $docId,
    ]);
}

/**
 * Marque un document comme échoué
 */
function mark_embedding_failed(int $docId, string $error): bool {
    $pdo = poc_db();

    $stmt = $pdo->prepare("
        UPDATE documents
        SET
            embedding_status = 'failed',
            embedding_error = :error,
            embedding_updated_at = NOW()
        WHERE id = :id
    ");

    return $stmt->execute([
        ':error' => $error,
        ':id' => $docId,
    ]);
}

// ============================================
// EXÉCUTION CLI
// ============================================

if (php_sapi_name() === 'cli') {

    echo "\n";
    echo "================================================================\n";
    echo "  POC 08 - GÉNÉRATION EMBEDDINGS EN BATCH\n";
    echo "================================================================\n\n";

    $cfg = poc_config();
    $dryRun = in_array('--dry', $argv);
    $limit = 0;

    // Chercher limite numérique dans les arguments
    foreach ($argv as $arg) {
        if (is_numeric($arg)) {
            $limit = (int) $arg;
        }
    }

    // Vérifier provider
    $providerInfo = get_embedding_provider_info();
    echo "--- CONFIGURATION ---\n";
    echo "  Provider: {$providerInfo['provider']}\n";
    echo "  Modèle: {$providerInfo['model']}\n";
    echo "  Dimensions: {$providerInfo['dimensions']}\n";
    echo "  Disponible: " . ($providerInfo['available'] ? 'Oui' : 'Non') . "\n";
    echo "  Mode: " . ($dryRun ? 'SIMULATION' : 'RÉEL') . "\n";
    if ($limit > 0) {
        echo "  Limite: $limit documents\n";
    }

    if (!$providerInfo['available']) {
        echo "\n[ERREUR] Aucun provider d'embedding disponible!\n";
        echo "  - Vérifiez qu'Ollama est lancé (ollama serve)\n";
        echo "  - Ou configurez une clé OpenAI\n\n";
        exit(1);
    }

    // Récupérer documents
    echo "\n--- DOCUMENTS SANS EMBEDDING ---\n";
    $documents = get_documents_without_embedding($limit);
    $total = count($documents);

    if ($total === 0) {
        echo "  Tous les documents ont déjà un embedding!\n\n";
        exit(0);
    }

    echo "  $total documents à traiter\n\n";

    // Traitement
    echo "--- GÉNÉRATION ---\n";
    $success = 0;
    $failed = 0;
    $skipped = 0;

    foreach ($documents as $i => $doc) {
        $num = $i + 1;
        $docId = $doc['id'];
        $title = mb_substr($doc['title'] ?? $doc['original_filename'] ?? "Doc #$docId", 0, 50);

        echo sprintf("  [%d/%d] #%d - %s... ", $num, $total, $docId, $title);

        // Récupérer texte
        $text = get_document_text($doc);

        if (empty($text)) {
            echo "SKIP (pas de texte)\n";
            $skipped++;
            continue;
        }

        $textLen = mb_strlen($text);
        echo "({$textLen} chars) ";

        if ($dryRun) {
            echo "DRY-RUN OK\n";
            $success++;
            continue;
        }

        // Générer embedding
        $startTime = microtime(true);
        $embedding = generate_embedding($text);
        $elapsed = round((microtime(true) - $startTime) * 1000);

        if ($embedding && count($embedding) > 100) {
            // Stocker
            if (store_embedding($docId, $embedding, $providerInfo['model'])) {
                $dims = count($embedding);
                echo "OK ({$dims}d, {$elapsed}ms)\n";
                $success++;
            } else {
                echo "ERREUR DB\n";
                $failed++;
            }
        } else {
            $error = "Embedding invalide ou vide";
            mark_embedding_failed($docId, $error);
            echo "ÉCHEC ({$elapsed}ms)\n";
            $failed++;
        }

        // Pause pour ne pas surcharger Ollama
        usleep(100000); // 100ms
    }

    // Résumé
    echo "\n--- RÉSUMÉ ---\n";
    echo "  Traités: $total\n";
    echo "  Succès: $success\n";
    echo "  Échecs: $failed\n";
    echo "  Ignorés: $skipped\n";

    // Vérification finale
    echo "\n--- VÉRIFICATION ---\n";
    $pdo = poc_db();
    $stmt = $pdo->query("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN embedding IS NOT NULL AND LENGTH(embedding) > 10 THEN 1 ELSE 0 END) as with_emb
        FROM documents
        WHERE deleted_at IS NULL
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "  Documents total: {$stats['total']}\n";
    echo "  Avec embedding: {$stats['with_emb']}\n";

    echo "\n";
    exit($failed > 0 ? 1 : 0);
}

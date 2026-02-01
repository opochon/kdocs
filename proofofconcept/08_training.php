<?php
/**
 * 08_training.php - APPRENTISSAGE ET CORRECTIONS
 *
 * Stocke les corrections utilisateur et les réutilise
 * pour améliorer la classification.
 *
 * USAGE:
 *   php 08_training.php                    # Stats training
 *   php 08_training.php store "hash" ...   # Stocker correction
 *   php 08_training.php search "texte"     # Chercher similaire
 */

require_once __DIR__ . '/helpers.php';

// ============================================
// GESTION DU FICHIER TRAINING
// ============================================

/**
 * Charge les données de training
 */
function load_training_data(): array {
    $cfg = poc_config();
    $file = $cfg['ai']['training']['file'] ?? __DIR__ . '/output/training.json';

    if (!file_exists($file)) {
        return [
            'corrections' => [],
            'learned_rules' => [],
            'stats' => [
                'total_corrections' => 0,
                'last_update' => null,
            ],
        ];
    }

    $data = json_decode(file_get_contents($file), true);
    return $data ?: [
        'corrections' => [],
        'learned_rules' => [],
        'stats' => ['total_corrections' => 0, 'last_update' => null],
    ];
}

/**
 * Sauvegarde les données de training
 */
function save_training_data(array $data): bool {
    $cfg = poc_config();
    $file = $cfg['ai']['training']['file'] ?? __DIR__ . '/output/training.json';

    // S'assurer que le dossier existe
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $data['stats']['last_update'] = date('Y-m-d H:i:s');

    return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

// ============================================
// STOCKAGE DES CORRECTIONS
// ============================================

/**
 * Stocke une correction utilisateur
 */
function store_correction(string $docHash, string $textSample, array $original, array $corrected): bool {
    $cfg = poc_config();

    if (!($cfg['ai']['training']['enabled'] ?? false)) {
        poc_log("Training désactivé, correction non stockée", 'INFO');
        return false;
    }

    $data = load_training_data();

    // Générer embedding du texte si possible
    $embedding = null;
    if (ollama_available()) {
        $embedding = generate_embedding_ollama($textSample);
    }

    // Créer l'entrée de correction
    $correction = [
        'hash' => $docHash,
        'text_sample' => mb_substr($textSample, 0, 500),
        'embedding' => $embedding,
        'original' => [
            'type' => $original['type'] ?? null,
            'confidence' => $original['confidence'] ?? 0,
            'method' => $original['method'] ?? null,
        ],
        'corrected' => [
            'type' => $corrected['type'] ?? null,
            'correspondent' => $corrected['correspondent'] ?? null,
            'tags' => $corrected['tags'] ?? [],
        ],
        'date' => date('Y-m-d H:i:s'),
    ];

    // Vérifier si correction existe déjà pour ce hash
    $found = false;
    foreach ($data['corrections'] as $i => $existing) {
        if ($existing['hash'] === $docHash) {
            $data['corrections'][$i] = $correction;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $data['corrections'][] = $correction;
        $data['stats']['total_corrections']++;
    }

    // Auto-apprentissage des règles si activé
    if ($cfg['ai']['training']['auto_learn_rules'] ?? false) {
        learn_from_correction($data, $correction);
    }

    $success = save_training_data($data);

    if ($success) {
        poc_log("Correction stockée: {$corrected['type']} (hash: " . substr($docHash, 0, 8) . ")", 'INFO');
    }

    return $success;
}

/**
 * Apprend des patterns depuis une correction
 */
function learn_from_correction(array &$data, array $correction): void {
    $text = mb_strtolower($correction['text_sample']);
    $type = $correction['corrected']['type'] ?? null;

    if (!$type) return;

    // Extraire mots significatifs (> 4 chars, pas de stopwords)
    $stopwords = ['pour', 'dans', 'avec', 'cette', 'votre', 'notre', 'vous', 'nous', 'être', 'avoir'];
    $words = preg_split('/\s+/', $text);
    $significant = [];

    foreach ($words as $word) {
        $word = trim($word, '.,;:!?()[]{}');
        if (mb_strlen($word) > 4 && !in_array($word, $stopwords)) {
            $significant[] = $word;
        }
    }

    // Compter les occurrences de patterns existants
    foreach ($significant as $word) {
        $found = false;
        foreach ($data['learned_rules'] as $i => $rule) {
            if ($rule['pattern'] === $word && $rule['type'] === $type) {
                $data['learned_rules'][$i]['count']++;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $data['learned_rules'][] = [
                'pattern' => $word,
                'type' => $type,
                'count' => 1,
            ];
        }
    }

    // Apprendre le correspondant aussi
    $correspondent = $correction['corrected']['correspondent'] ?? null;
    if ($correspondent) {
        $corpPattern = mb_strtolower($correspondent);
        $found = false;
        foreach ($data['learned_rules'] as $i => $rule) {
            if (($rule['correspondent'] ?? null) === $correspondent) {
                $data['learned_rules'][$i]['count']++;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $data['learned_rules'][] = [
                'pattern' => $corpPattern,
                'correspondent' => $correspondent,
                'count' => 1,
            ];
        }
    }

    // Nettoyer les règles à faible occurrence (garder top 100)
    usort($data['learned_rules'], fn($a, $b) => $b['count'] <=> $a['count']);
    $data['learned_rules'] = array_slice($data['learned_rules'], 0, 100);
}

// ============================================
// RECHERCHE PAR SIMILARITÉ
// ============================================

/**
 * Cherche une classification existante par similarité
 */
function get_trained_classification(string $text): ?array {
    $cfg = poc_config();

    if (!($cfg['ai']['training']['enabled'] ?? false)) {
        return null;
    }

    $minSimilarity = $cfg['ai']['training']['min_similarity'] ?? 0.85;
    $data = load_training_data();

    if (empty($data['corrections'])) {
        return null;
    }

    // Générer embedding du texte
    $queryEmbedding = null;
    if (ollama_available()) {
        $queryEmbedding = generate_embedding_ollama(mb_substr($text, 0, 500));
    }

    $bestMatch = null;
    $bestSimilarity = 0;

    foreach ($data['corrections'] as $correction) {
        $similarity = 0;

        // Similarité par embedding si disponible
        if ($queryEmbedding && !empty($correction['embedding'])) {
            $similarity = cosine_similarity($queryEmbedding, $correction['embedding']);
        }
        // Fallback: similarité textuelle simple
        else {
            $sample = mb_strtolower($correction['text_sample']);
            $query = mb_strtolower(mb_substr($text, 0, 500));
            similar_text($sample, $query, $percent);
            $similarity = $percent / 100;
        }

        if ($similarity > $bestSimilarity && $similarity >= $minSimilarity) {
            $bestSimilarity = $similarity;
            $bestMatch = $correction;
        }
    }

    if ($bestMatch) {
        poc_log("Training match: similarité " . round($bestSimilarity, 3) . " >= $minSimilarity", 'INFO');
        return [
            'type' => $bestMatch['corrected']['type'],
            'confidence' => $bestSimilarity,
            'correspondent' => $bestMatch['corrected']['correspondent'] ?? null,
            'tags' => $bestMatch['corrected']['tags'] ?? [],
            'fields' => [],
            'summary' => null,
            'training_match' => [
                'hash' => $bestMatch['hash'],
                'similarity' => $bestSimilarity,
            ],
        ];
    }

    return null;
}

/**
 * Applique les règles apprises à un texte
 */
function apply_learned_rules(string $text): array {
    $data = load_training_data();
    $textLower = mb_strtolower($text);

    $typeScores = [];
    $correspondent = null;

    foreach ($data['learned_rules'] as $rule) {
        if (mb_strpos($textLower, $rule['pattern']) !== false) {
            // Règle de type
            if (isset($rule['type'])) {
                $type = $rule['type'];
                $typeScores[$type] = ($typeScores[$type] ?? 0) + $rule['count'];
            }

            // Règle de correspondant
            if (isset($rule['correspondent']) && !$correspondent) {
                $correspondent = $rule['correspondent'];
            }
        }
    }

    // Trouver le type avec le meilleur score
    $bestType = null;
    $bestScore = 0;
    foreach ($typeScores as $type => $score) {
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestType = $type;
        }
    }

    return [
        'type' => $bestType,
        'type_score' => $bestScore,
        'correspondent' => $correspondent,
    ];
}

// ============================================
// STATISTIQUES ET MAINTENANCE
// ============================================

/**
 * Retourne les statistiques du training
 */
function get_training_stats(): array {
    $data = load_training_data();

    // Compter par type
    $byType = [];
    foreach ($data['corrections'] as $c) {
        $type = $c['corrected']['type'] ?? 'unknown';
        $byType[$type] = ($byType[$type] ?? 0) + 1;
    }

    // Top règles
    $topRules = array_slice($data['learned_rules'], 0, 10);

    return [
        'total_corrections' => count($data['corrections']),
        'total_rules' => count($data['learned_rules']),
        'by_type' => $byType,
        'top_rules' => $topRules,
        'last_update' => $data['stats']['last_update'] ?? null,
    ];
}

/**
 * Nettoie les données de training obsolètes
 */
function cleanup_training(int $maxAge = 365): int {
    $data = load_training_data();
    $cutoff = strtotime("-$maxAge days");
    $removed = 0;

    $data['corrections'] = array_filter($data['corrections'], function($c) use ($cutoff, &$removed) {
        $date = strtotime($c['date'] ?? '2000-01-01');
        if ($date < $cutoff) {
            $removed++;
            return false;
        }
        return true;
    });

    if ($removed > 0) {
        save_training_data($data);
        poc_log("Training cleanup: $removed corrections supprimées", 'INFO');
    }

    return $removed;
}

// ============================================
// EXÉCUTION CLI
// ============================================

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($argv[0] ?? '')) {

    echo "\n";
    echo "================================================================\n";
    echo "  POC 08 - TRAINING / APPRENTISSAGE\n";
    echo "================================================================\n\n";

    $cfg = poc_config();
    $command = $argv[1] ?? 'stats';

    echo "--- CONFIGURATION ---\n";
    poc_result("Training activé", $cfg['ai']['training']['enabled'] ?? false);
    poc_result("Seuil similarité", true, ($cfg['ai']['training']['min_similarity'] ?? 0.85));
    poc_result("Auto-learn rules", $cfg['ai']['training']['auto_learn_rules'] ?? false);

    switch ($command) {
        case 'stats':
            echo "\n--- STATISTIQUES ---\n";
            $stats = get_training_stats();
            echo "  Corrections stockées: {$stats['total_corrections']}\n";
            echo "  Règles apprises: {$stats['total_rules']}\n";

            if (!empty($stats['by_type'])) {
                echo "\n  Par type:\n";
                foreach ($stats['by_type'] as $type => $count) {
                    echo "    - $type: $count\n";
                }
            }

            if (!empty($stats['top_rules'])) {
                echo "\n  Top règles:\n";
                foreach ($stats['top_rules'] as $rule) {
                    $info = isset($rule['type']) ? "→ {$rule['type']}" : "→ {$rule['correspondent']}";
                    echo "    - \"{$rule['pattern']}\" $info ({$rule['count']}x)\n";
                }
            }

            if ($stats['last_update']) {
                echo "\n  Dernière mise à jour: {$stats['last_update']}\n";
            }
            break;

        case 'store':
            // php 08_training.php store "hash" "type_original" "type_corrigé" "correspondant"
            $hash = $argv[2] ?? md5(time());
            $originalType = $argv[3] ?? 'autre';
            $correctedType = $argv[4] ?? 'facture';
            $correspondent = $argv[5] ?? null;

            $testText = "Exemple de document pour test de training avec type $correctedType";

            $success = store_correction(
                $hash,
                $testText,
                ['type' => $originalType, 'confidence' => 0.5, 'method' => 'rules'],
                ['type' => $correctedType, 'correspondent' => $correspondent, 'tags' => []]
            );

            echo "\n--- STOCKAGE ---\n";
            poc_result("Correction stockée", $success, $hash);
            break;

        case 'search':
            // php 08_training.php search "texte à chercher"
            $searchText = $argv[2] ?? "facture téléphone";

            echo "\n--- RECHERCHE SIMILARITÉ ---\n";
            echo "  Texte: $searchText\n\n";

            $result = get_trained_classification($searchText);

            if ($result) {
                poc_result("Match trouvé", true, "{$result['type']} (sim: " . round($result['confidence'], 3) . ")");
                if ($result['correspondent']) {
                    echo "  Correspondant: {$result['correspondent']}\n";
                }
            } else {
                poc_result("Match trouvé", false, "Aucune correction similaire");
            }
            break;

        case 'cleanup':
            echo "\n--- NETTOYAGE ---\n";
            $removed = cleanup_training();
            poc_result("Corrections supprimées", true, $removed);
            break;

        case 'demo':
            echo "\n--- DÉMONSTRATION ---\n";

            // Stocker quelques corrections de test
            $demos = [
                ['hash' => md5('demo1'), 'text' => 'Facture Swisscom services téléphonie mobile janvier 2024', 'original' => 'autre', 'corrected' => 'facture', 'correspondent' => 'Swisscom'],
                ['hash' => md5('demo2'), 'text' => 'Contrat de bail appartement Lausanne signé parties', 'original' => 'courrier', 'corrected' => 'contrat', 'correspondent' => null],
                ['hash' => md5('demo3'), 'text' => 'Devis rénovation salle de bain estimation travaux', 'original' => 'facture', 'corrected' => 'devis', 'correspondent' => 'Artisan SA'],
            ];

            foreach ($demos as $demo) {
                store_correction(
                    $demo['hash'],
                    $demo['text'],
                    ['type' => $demo['original'], 'confidence' => 0.4],
                    ['type' => $demo['corrected'], 'correspondent' => $demo['correspondent']]
                );
                echo "  Stocké: {$demo['corrected']}\n";
            }

            echo "\n  Test recherche similarité:\n";
            $testResult = get_trained_classification("facture téléphone mobile Swisscom");
            if ($testResult) {
                echo "  → Trouvé: {$testResult['type']} (sim: " . round($testResult['confidence'], 3) . ")\n";
            } else {
                echo "  → Aucun match\n";
            }

            echo "\n  Stats finales:\n";
            $stats = get_training_stats();
            echo "  Corrections: {$stats['total_corrections']}, Règles: {$stats['total_rules']}\n";
            break;

        default:
            echo "\nCommandes disponibles:\n";
            echo "  stats   - Afficher les statistiques\n";
            echo "  store   - Stocker une correction\n";
            echo "  search  - Chercher par similarité\n";
            echo "  cleanup - Nettoyer les vieilles données\n";
            echo "  demo    - Démonstration complète\n";
    }

    echo "\n";
}

return 'store_correction';

<?php
/**
 * K-Docs - AISearchService
 * Service de recherche intelligent avec RAG
 *
 * Architecture:
 * - Retrieval: MySQL FULLTEXT + Vecteurs MySQL (embeddings)
 * - Generation: CASCADE Claude > Ollama > Règles
 * - Context: Enrichi avec extraits pertinents
 */
namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Core\Config;
use KDocs\Helpers\AIHelper;

class AISearchService
{
    private AIProviderService $aiProvider;
    private ?EmbeddingService $embeddings = null;
    private $db;
    private bool $embeddingsAvailable = false;

    public function __construct()
    {
        $this->aiProvider = new AIProviderService();
        $this->db = Database::getInstance();

        // Initialiser les embeddings si disponibles
        if (Config::get('embeddings.enabled', false)) {
            try {
                $this->embeddings = new EmbeddingService();
                $this->embeddingsAvailable = $this->embeddings->isAvailable();
            } catch (\Exception $e) {
                error_log("AISearchService: EmbeddingService init failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Vérifie si la recherche sémantique est disponible
     */
    public function isSemanticSearchAvailable(): bool
    {
        return $this->embeddingsAvailable;
    }

    /**
     * Vérifie si la génération IA est disponible
     */
    public function isGenerationAvailable(): bool
    {
        return $this->aiProvider->isAIAvailable();
    }
    
    /**
     * Traite une question en langage naturel
     * Retourne une réponse + les documents trouvés
     */
    public function askQuestion(string $question): array
    {
        // 1. Convertir la question en filtres de recherche
        $filters = $this->questionToFilters($question);
        
        // 2. Exécuter la recherche
        $documents = $this->executeSearch($filters);
        
        // 3. Générer une réponse en langage naturel
        $answer = $this->generateAnswer($question, $documents, $filters);
        
        return [
            'answer' => $answer,
            'documents' => $documents,
            'filters_used' => $filters,
            'count' => count($documents)
        ];
    }
    
    /**
     * Convertit une question en filtres SQL via IA (Claude > Ollama)
     */
    private function questionToFilters(string $question): array
    {
        // Si aucune IA disponible, utiliser une recherche simple
        if (!$this->aiProvider->isAIAvailable()) {
            return ['text_search' => $question];
        }
        
        $systemPrompt = <<<PROMPT
Tu es un assistant qui convertit des questions en langage naturel en filtres de recherche JSON.

Base de données disponible avec ces champs :
- title : titre du document
- content : contenu OCR du document
- correspondent_id : ID du correspondant (expéditeur)
- document_type_id : ID du type de document
- document_date : date du document (YYYY-MM-DD)
- amount : montant (décimal)
- tags : liste de tags associés
- archive_serial_number : numéro ASN

Correspondants existants dans la base (utilise l'ID correspondant) :
{CORRESPONDENTS_LIST}

Types de documents existants :
{TYPES_LIST}

Tags existants :
{TAGS_LIST}

Filtres disponibles (retourne un JSON avec ces clés) :
- text_search : recherche full-text dans titre et contenu
- correspondent_name : nom partiel du correspondant (LIKE)
- type_name : nom partiel du type (LIKE)
- tag_names : liste de noms de tags
- date_from : date minimale (YYYY-MM-DD)
- date_to : date maximale (YYYY-MM-DD)
- amount_min : montant minimum
- amount_max : montant maximum
- sort_by : "date", "amount", "title"
- sort_dir : "asc" ou "desc"
- limit : nombre max de résultats
- aggregation : "count", "sum_amount", "avg_amount" (pour stats)

Question : {QUESTION}

Réponds UNIQUEMENT avec un JSON valide.
Exemple : {"text_search": "facture", "correspondent_name": "swisscom", "sort_by": "date", "sort_dir": "desc", "limit": 10}
PROMPT;

        try {
            // Récupérer les entités pour le contexte
            $correspondents = $this->db->query("SELECT id, name FROM correspondents")->fetchAll();
            $types = $this->db->query("SELECT id, label FROM document_types")->fetchAll();
            $tags = $this->db->query("SELECT id, name FROM tags")->fetchAll();
            
            $corrList = implode(", ", array_map(fn($c) => "{$c['name']} (ID:{$c['id']})", $correspondents));
            $typeList = implode(", ", array_map(fn($t) => "{$t['label']} (ID:{$t['id']})", $types));
            $tagList = implode(", ", array_column($tags, 'name'));
            
            $prompt = str_replace(
                ['{CORRESPONDENTS_LIST}', '{TYPES_LIST}', '{TAGS_LIST}', '{QUESTION}'],
                [$corrList, $typeList, $tagList, $question],
                $systemPrompt
            );

            // CASCADE: Claude > Ollama
            $response = $this->aiProvider->complete($prompt, ['max_tokens' => 1000]);
            if (!$response || empty($response['text'])) {
                return ['text_search' => $question]; // Fallback
            }

            // Parser le JSON avec AIHelper
            $filters = AIHelper::parseJsonResponse($response['text']);
            return $filters ?: ['text_search' => $question];
        } catch (\Exception $e) {
            error_log("AISearchService::questionToFilters error: " . $e->getMessage());
            return ['text_search' => $question]; // Fallback en cas d'erreur
        }
    }
    
    /**
     * Exécute la recherche avec les filtres
     */
    private function executeSearch(array $filters): array
    {
        $conditions = ["d.deleted_at IS NULL"];
        // Exclure les documents en attente de validation (pending)
        $conditions[] = "(d.status IS NULL OR d.status != 'pending')";
        $params = [];
        
        // Recherche full-text AMÉLIORÉE - cherche chaque mot avec OR
        if (!empty($filters['text_search'])) {
            $searchText = trim($filters['text_search']);
            $words = preg_split('/\s+/', $searchText);
            
            if (count($words) > 1) {
                // Multi-mots : chercher AU MOINS un mot (OR)
                $wordConditions = [];
                foreach ($words as $word) {
                    if (strlen($word) >= 2) {
                        $search = '%' . $word . '%';
                        $wordConditions[] = "(d.title LIKE ? OR d.content LIKE ? OR d.ocr_text LIKE ? OR d.original_filename LIKE ?)";
                        $params = array_merge($params, [$search, $search, $search, $search]);
                    }
                }
                if (!empty($wordConditions)) {
                    $conditions[] = "(" . implode(" OR ", $wordConditions) . ")";
                }
            } else {
                // Un seul mot
                $search = '%' . $searchText . '%';
                $conditions[] = "(d.title LIKE ? OR d.content LIKE ? OR d.ocr_text LIKE ? OR d.original_filename LIKE ?)";
                $params = array_merge($params, [$search, $search, $search, $search]);
            }
        }
        
        // Correspondent
        if (!empty($filters['correspondent_name'])) {
            $conditions[] = "EXISTS (SELECT 1 FROM correspondents c WHERE c.id = d.correspondent_id AND c.name LIKE ?)";
            $params[] = '%' . $filters['correspondent_name'] . '%';
        }
        
        // Type
        if (!empty($filters['type_name'])) {
            $conditions[] = "EXISTS (SELECT 1 FROM document_types dt WHERE dt.id = d.document_type_id AND dt.label LIKE ?)";
            $params[] = '%' . $filters['type_name'] . '%';
        }
        
        // Tags
        if (!empty($filters['tag_names']) && is_array($filters['tag_names'])) {
            foreach ($filters['tag_names'] as $tagName) {
                $conditions[] = "EXISTS (SELECT 1 FROM document_tags dtg JOIN tags t ON dtg.tag_id = t.id WHERE dtg.document_id = d.id AND t.name LIKE ?)";
                $params[] = '%' . $tagName . '%';
            }
        }
        
        // Dates
        if (!empty($filters['date_from'])) {
            $conditions[] = "d.document_date >= ?";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $conditions[] = "d.document_date <= ?";
            $params[] = $filters['date_to'];
        }
        
        // Montants
        if (!empty($filters['amount_min'])) {
            $conditions[] = "d.amount >= ?";
            $params[] = (float)$filters['amount_min'];
        }
        if (!empty($filters['amount_max'])) {
            $conditions[] = "d.amount <= ?";
            $params[] = (float)$filters['amount_max'];
        }
        
        // Construire la requête
        $where = implode(' AND ', $conditions);
        
        // Tri
        $sortBy = $filters['sort_by'] ?? 'document_date';
        $sortDir = strtoupper($filters['sort_dir'] ?? 'DESC');
        if (!in_array($sortDir, ['ASC', 'DESC'])) $sortDir = 'DESC';
        
        $sortColumn = 'd.document_date';
        if ($sortBy === 'amount') {
            $sortColumn = 'd.amount';
        } elseif ($sortBy === 'title') {
            $sortColumn = 'd.title';
        }
        
        // Limite
        $limit = min((int)($filters['limit'] ?? 20), 100);
        
        $sql = "
            SELECT d.*, 
                   c.name as correspondent_name,
                   dt.label as document_type_name
            FROM documents d
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            WHERE $where
            ORDER BY $sortColumn $sortDir
            LIMIT $limit
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $documents = $stmt->fetchAll();
        
        // Ajouter les tags, résumé et matches à chaque document
        foreach ($documents as &$doc) {
            $tagStmt = $this->db->prepare("
                SELECT t.name FROM tags t
                JOIN document_tags dt ON t.id = dt.tag_id
                WHERE dt.document_id = ?
            ");
            $tagStmt->execute([$doc['id']]);
            $doc['tags'] = array_column($tagStmt->fetchAll(), 'name');
            
            // Générer un résumé court (premiers 200 caractères)
            $content = ($doc['ocr_text'] ?? '') ?: ($doc['content'] ?? '');
            if (strlen($content) > 200) {
                $doc['summary'] = substr($content, 0, 200) . '...';
            } else {
                $doc['summary'] = $content ?: 'Aucun contenu disponible';
            }
            
            // Trouver les matches (lignes correspondantes)
            if (!empty($filters['text_search'])) {
                $doc['matches'] = $this->findMatches($doc, $filters['text_search']);
            } else {
                $doc['matches'] = [];
            }
        }
        
        return $documents;
    }
    
    /**
     * Trouve les lignes correspondantes dans un document
     */
    private function findMatches(array $document, string $searchText): array
    {
        $matches = [];
        $content = ($document['ocr_text'] ?? '') ?: ($document['content'] ?? '');
        
        if (empty($content)) {
            return $matches;
        }
        
        $words = preg_split('/\s+/', trim($searchText));
        $lines = explode("\n", $content);
        
        foreach ($lines as $lineNum => $line) {
            $lineNum++; // Numéro de ligne commence à 1
            $lineLower = mb_strtolower($line, 'UTF-8');
            
            foreach ($words as $word) {
                if (strlen($word) < 2) continue;
                $wordLower = mb_strtolower($word, 'UTF-8');
                
                if (mb_strpos($lineLower, $wordLower) !== false) {
                    // Trouver la position du mot dans la ligne
                    $pos = mb_strpos($lineLower, $wordLower);
                    $start = max(0, $pos - 50);
                    $end = min(mb_strlen($line), $pos + mb_strlen($word) + 50);
                    $excerpt = mb_substr($line, $start, $end - $start);
                    
                    // Ajouter "..." si nécessaire
                    if ($start > 0) $excerpt = '...' . $excerpt;
                    if ($end < mb_strlen($line)) $excerpt = $excerpt . '...';
                    
                    // Mettre en évidence le mot
                    $excerpt = preg_replace('/(' . preg_quote($word, '/') . ')/iu', '<mark>$1</mark>', $excerpt);
                    
                    $matches[] = [
                        'line' => $lineNum,
                        'text' => trim($line),
                        'excerpt' => $excerpt,
                        'word' => $word
                    ];
                    break; // Une seule correspondance par ligne
                }
            }
        }
        
        // Limiter à 5 matches maximum
        return array_slice($matches, 0, 5);
    }
    
    /**
     * Génère une réponse en langage naturel
     * CASCADE: Claude > Ollama > Règles simples
     */
    private function generateAnswer(string $question, array $documents, array $filters): string
    {
        $count = count($documents);

        if ($count === 0) {
            return "Je n'ai trouvé aucun document correspondant à votre recherche.";
        }

        // Calculer des statistiques
        $stats = $this->computeStats($documents, $filters);

        // Construire le contexte enrichi
        $context = $this->buildEnrichedContext($documents, $filters, $stats);

        // 3. RÈGLES SIMPLES (toujours disponible comme fallback final)
        $rulesAnswer = $this->generateRulesAnswer($count, $documents, $stats);

        // Si aucune IA disponible, retourner réponse règles
        if (!$this->aiProvider->isAIAvailable()) {
            return $rulesAnswer;
        }

        // 1. & 2. CASCADE: Claude > Ollama
        try {
            $prompt = <<<PROMPT
Tu es un assistant de gestion documentaire. Réponds à la question de l'utilisateur en te basant UNIQUEMENT sur les documents fournis.

Question: $question

$context

Instructions:
1. Réponds directement et de façon concise en français
2. Cite les documents pertinents par leur titre
3. Si des statistiques sont demandées, utilise les chiffres fournis
4. Reste factuel, ne suppose pas d'informations absentes
5. Si la réponse n'est pas dans les documents, dis-le clairement
PROMPT;

            $response = $this->aiProvider->complete($prompt, [
                'max_tokens' => 1500,
                'temperature' => 0.3
            ]);

            if ($response && !empty($response['text'])) {
                $provider = $response['provider'] ?? 'unknown';
                $answer = trim($response['text']);
                // Ajouter indicateur du provider utilisé
                return $answer . "\n\n_[Source: {$provider}]_";
            }
        } catch (\Exception $e) {
            error_log("AISearchService::generateAnswer AI error: " . $e->getMessage());
        }

        // Fallback sur règles si IA échoue
        return $rulesAnswer . "\n\n_[Source: rules]_";
    }

    /**
     * Calcule les statistiques des documents
     */
    private function computeStats(array $documents, array $filters): array
    {
        $stats = [
            'count' => count($documents),
        ];

        $amounts = array_filter(array_column($documents, 'amount'));
        if (!empty($amounts)) {
            $stats['total_amount'] = array_sum($amounts);
            $stats['avg_amount'] = $stats['total_amount'] / count($amounts);
            $stats['min_amount'] = min($amounts);
            $stats['max_amount'] = max($amounts);
        }

        // Dates
        $dates = array_filter(array_column($documents, 'document_date'));
        if (!empty($dates)) {
            sort($dates);
            $stats['date_range'] = [
                'from' => reset($dates),
                'to' => end($dates)
            ];
        }

        // Agrégation spécifique si demandée
        if (!empty($filters['aggregation'])) {
            $stats['aggregation_type'] = $filters['aggregation'];
        }

        return $stats;
    }

    /**
     * Construit un contexte enrichi avec extraits pertinents
     */
    private function buildEnrichedContext(array $documents, array $filters, array $stats): string
    {
        $context = "=== DOCUMENTS TROUVÉS ({$stats['count']}) ===\n\n";

        foreach (array_slice($documents, 0, 8) as $i => $doc) {
            $num = $i + 1;
            $title = $doc['title'] ?? $doc['original_filename'] ?? 'Sans titre';
            $context .= "--- Document $num: $title ---\n";
            $context .= "Correspondant: " . ($doc['correspondent_name'] ?? 'N/A') . "\n";
            $context .= "Date: " . ($doc['document_date'] ?? 'N/A') . "\n";

            if (!empty($doc['amount'])) {
                $context .= "Montant: " . number_format($doc['amount'], 2, '.', ' ') . " CHF\n";
            }

            if (!empty($doc['tags'])) {
                $tags = is_array($doc['tags']) ? implode(', ', $doc['tags']) : $doc['tags'];
                $context .= "Tags: $tags\n";
            }

            // Extrait pertinent (matches ou résumé)
            if (!empty($doc['matches'])) {
                $context .= "Extraits pertinents:\n";
                foreach (array_slice($doc['matches'], 0, 2) as $match) {
                    $text = strip_tags($match['excerpt'] ?? $match['text'] ?? '');
                    $context .= "  > " . mb_substr($text, 0, 150) . "\n";
                }
            } elseif (!empty($doc['summary'])) {
                $context .= "Résumé: " . mb_substr($doc['summary'], 0, 200) . "\n";
            }

            $context .= "\n";
        }

        // Statistiques
        if (isset($stats['total_amount'])) {
            $context .= "=== STATISTIQUES ===\n";
            $context .= "Total montants: " . number_format($stats['total_amount'], 2, '.', ' ') . " CHF\n";
            $context .= "Moyenne: " . number_format($stats['avg_amount'], 2, '.', ' ') . " CHF\n";
            if (isset($stats['date_range'])) {
                $context .= "Période: {$stats['date_range']['from']} à {$stats['date_range']['to']}\n";
            }
        }

        return $context;
    }

    /**
     * Génère une réponse basée sur des règles simples (fallback final)
     */
    private function generateRulesAnswer(int $count, array $documents, array $stats): string
    {
        $answer = "J'ai trouvé $count document(s) correspondant à votre recherche.";

        // Lister les premiers documents
        if ($count > 0 && $count <= 5) {
            $answer .= "\n\nDocuments trouvés :\n";
            foreach ($documents as $i => $doc) {
                $title = $doc['title'] ?? $doc['original_filename'] ?? 'Sans titre';
                $date = $doc['document_date'] ?? '';
                $amount = !empty($doc['amount']) ? ' - ' . number_format($doc['amount'], 2) . ' CHF' : '';
                $answer .= ($i + 1) . ". $title" . ($date ? " ($date)" : "") . "$amount\n";
            }
        } elseif ($count > 5) {
            $answer .= "\n\nPremiers documents :\n";
            foreach (array_slice($documents, 0, 5) as $i => $doc) {
                $title = $doc['title'] ?? $doc['original_filename'] ?? 'Sans titre';
                $answer .= ($i + 1) . ". $title\n";
            }
            $answer .= "... et " . ($count - 5) . " autres.\n";
        }

        // Ajouter statistiques si disponibles
        if (isset($stats['total_amount'])) {
            $answer .= "\nMontant total: " . number_format($stats['total_amount'], 2, '.', ' ') . " CHF";
        }

        return $answer;
    }
    
    /**
     * Recherche rapide (pour la barre de recherche)
     * Utilise recherche hybride: FULLTEXT + Vecteurs MySQL
     */
    public function quickSearch(string $query, int $limit = 10): array
    {
        // Recherche hybride si embeddings disponibles
        if ($this->isSemanticSearchAvailable()) {
            try {
                $results = $this->hybridSearchMySQL($query, $limit);
                if (!empty($results)) {
                    return $results;
                }
            } catch (\Exception $e) {
                error_log("quickSearch hybrid failed, falling back to SQL: " . $e->getMessage());
            }
        }

        // Fallback sur la recherche SQL classique
        return $this->quickSearchSQL($query, $limit);
    }

    /**
     * Recherche SQL FULLTEXT classique
     */
    private function quickSearchSQL(string $query, int $limit = 10): array
    {
        $search = '%' . $query . '%';

        $sql = "
            SELECT d.id, d.title, d.original_filename, d.document_date, d.amount,
                   c.name as correspondent_name
            FROM documents d
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            WHERE d.deleted_at IS NULL
            AND (d.status IS NULL OR d.status != 'pending')
            AND (d.title LIKE ? OR d.content LIKE ? OR d.ocr_text LIKE ?
                 OR d.original_filename LIKE ? OR c.name LIKE ?)
            ORDER BY d.document_date DESC
            LIMIT ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$search, $search, $search, $search, $search, $limit]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Recherche sémantique via embeddings MySQL
     */
    public function semanticSearch(string $query, int $limit = 10, array $filters = []): array
    {
        if (!$this->isSemanticSearchAvailable()) {
            return [];
        }

        try {
            return $this->vectorSearchMySQL($query, $limit, 0.3);
        } catch (\Exception $e) {
            error_log("semanticSearch error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Recherche hybride MySQL (FULLTEXT + Vecteurs)
     */
    public function hybridSearch(string $query, int $limit = 10, array $filters = [], float $semanticWeight = 0.6): array
    {
        return $this->hybridSearchMySQL($query, $limit, $semanticWeight);
    }

    /**
     * Recherche vectorielle dans MySQL
     * Utilise les embeddings stockés dans documents.embedding (BLOB)
     */
    private function vectorSearchMySQL(string $query, int $limit = 10, float $threshold = 0.3): array
    {
        if (!$this->embeddings) {
            return [];
        }

        // Générer embedding de la requête
        $queryVector = $this->embeddings->embed($query);
        if (!$queryVector) {
            return [];
        }

        // Charger les documents avec embeddings
        $stmt = $this->db->query("
            SELECT d.id, d.title, d.original_filename, d.document_date, d.amount,
                   d.embedding, c.name as correspondent_name
            FROM documents d
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            WHERE d.deleted_at IS NULL
            AND (d.status IS NULL OR d.status != 'pending')
            AND d.embedding IS NOT NULL
        ");

        $results = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $docVector = $this->unpackEmbedding($row['embedding']);
            if (!$docVector) continue;

            $similarity = AIHelper::cosineSimilarity($queryVector, $docVector);
            if ($similarity >= $threshold) {
                unset($row['embedding']); // Ne pas retourner le blob
                $row['_semantic_score'] = round($similarity, 4);
                $results[] = $row;
            }
        }

        // Trier par score décroissant
        usort($results, fn($a, $b) => $b['_semantic_score'] <=> $a['_semantic_score']);

        return array_slice($results, 0, $limit);
    }

    /**
     * Recherche hybride MySQL: combine FULLTEXT et vecteurs
     */
    private function hybridSearchMySQL(string $query, int $limit = 10, float $semanticWeight = 0.6): array
    {
        // 1. Recherche FULLTEXT
        $fulltextResults = $this->quickSearchSQL($query, $limit * 2);
        $fulltextIds = array_column($fulltextResults, 'id');

        // 2. Recherche sémantique
        $semanticResults = [];
        if ($this->isSemanticSearchAvailable()) {
            $semanticResults = $this->vectorSearchMySQL($query, $limit * 2, 0.25);
        }

        // 3. Fusion des scores
        $combined = [];
        $keywordWeight = 1 - $semanticWeight;

        // Ajouter résultats FULLTEXT
        foreach ($fulltextResults as $i => $doc) {
            $id = $doc['id'];
            $ftScore = 1 - ($i / count($fulltextResults)); // Score basé sur position
            $combined[$id] = [
                'doc' => $doc,
                'ft_score' => $ftScore,
                'sem_score' => 0,
            ];
        }

        // Fusionner avec résultats sémantiques
        foreach ($semanticResults as $doc) {
            $id = $doc['id'];
            if (isset($combined[$id])) {
                $combined[$id]['sem_score'] = $doc['_semantic_score'];
            } else {
                $combined[$id] = [
                    'doc' => $doc,
                    'ft_score' => 0,
                    'sem_score' => $doc['_semantic_score'],
                ];
            }
        }

        // Calculer score final
        foreach ($combined as $id => &$item) {
            $item['final_score'] = ($item['ft_score'] * $keywordWeight) + ($item['sem_score'] * $semanticWeight);
            $item['doc']['_search_score'] = round($item['final_score'], 4);
            $item['doc']['_semantic_score'] = round($item['sem_score'], 4);
        }

        // Trier par score final
        usort($combined, fn($a, $b) => $b['final_score'] <=> $a['final_score']);

        // Extraire les documents
        $results = array_map(fn($item) => $item['doc'], array_slice($combined, 0, $limit));

        return $results;
    }

    /**
     * Trouve des documents similaires à un document donné
     */
    public function findSimilarDocuments(int $documentId, int $limit = 5): array
    {
        if (!$this->isSemanticSearchAvailable()) {
            return [];
        }

        try {
            // Récupérer l'embedding du document source
            $stmt = $this->db->prepare("SELECT embedding FROM documents WHERE id = ? AND embedding IS NOT NULL");
            $stmt->execute([$documentId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row || !$row['embedding']) {
                return [];
            }

            $sourceVector = $this->unpackEmbedding($row['embedding']);
            if (!$sourceVector) {
                return [];
            }

            // Chercher documents similaires
            $stmt = $this->db->prepare("
                SELECT d.id, d.title, d.original_filename, d.document_date, d.amount,
                       d.embedding, c.name as correspondent_name
                FROM documents d
                LEFT JOIN correspondents c ON d.correspondent_id = c.id
                WHERE d.deleted_at IS NULL
                AND d.id != ?
                AND d.embedding IS NOT NULL
            ");
            $stmt->execute([$documentId]);

            $results = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $docVector = $this->unpackEmbedding($row['embedding']);
                if (!$docVector) continue;

                $similarity = AIHelper::cosineSimilarity($sourceVector, $docVector);
                if ($similarity >= 0.5) { // Seuil plus élevé pour similarité
                    unset($row['embedding']);
                    $row['_similarity'] = round($similarity, 4);
                    $results[] = $row;
                }
            }

            usort($results, fn($a, $b) => $b['_similarity'] <=> $a['_similarity']);

            return array_slice($results, 0, $limit);
        } catch (\Exception $e) {
            error_log("findSimilarDocuments error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Décompresse un embedding stocké en BLOB
     */
    private function unpackEmbedding(?string $blob): ?array
    {
        if (!$blob) return null;

        // Format: packed floats (32-bit)
        $unpacked = unpack('f*', $blob);
        if (!$unpacked) return null;

        return array_values($unpacked);
    }
    
    /**
     * Trouve un document contenant une référence spécifique
     */
    public function findReference(string $reference): array
    {
        $search = '%' . $reference . '%';
        
        $sql = "
            SELECT d.*, c.name as correspondent_name, dt.name as document_type_name
            FROM documents d
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            WHERE d.deleted_at IS NULL
            AND (d.status IS NULL OR d.status != 'pending')
              AND d.content LIKE ?
            ORDER BY d.document_date DESC
            LIMIT 20
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$search]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Résume un document via IA (CASCADE: Claude > Ollama)
     */
    public function summarizeDocument(int $documentId): ?string
    {
        $stmt = $this->db->prepare("SELECT * FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $doc = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$doc) {
            return null;
        }

        // Utiliser ocr_text ou content
        $content = $doc['ocr_text'] ?? $doc['content'] ?? '';
        if (empty(trim($content))) {
            return null;
        }

        $content = mb_substr($content, 0, 8000); // Limiter pour tokens

        $prompt = <<<PROMPT
Résume ce document de manière concise (3-5 phrases) en français :

Titre: {$doc['title']}
Contenu:
$content

Fournis uniquement le résumé, sans introduction ni commentaire.
PROMPT;

        // CASCADE: Claude > Ollama
        $response = $this->aiProvider->complete($prompt, ['max_tokens' => 500]);
        if (!$response || empty($response['text'])) {
            return null;
        }

        return trim($response['text']);
    }

    /**
     * Retourne le statut du service RAG
     */
    public function getStatus(): array
    {
        $aiStatus = $this->aiProvider->getStatus();

        return [
            'embeddings_available' => $this->embeddingsAvailable,
            'generation_available' => $this->aiProvider->isAIAvailable(),
            'active_provider' => $aiStatus['active_provider'] ?? 'none',
            'providers' => [
                'claude' => $aiStatus['claude'] ?? [],
                'ollama' => $aiStatus['ollama'] ?? [],
            ],
            'features' => [
                'semantic_search' => $this->embeddingsAvailable,
                'hybrid_search' => $this->embeddingsAvailable,
                'rag_answers' => $this->aiProvider->isAIAvailable(),
                'document_summary' => $this->aiProvider->isAIAvailable(),
            ],
        ];
    }
}

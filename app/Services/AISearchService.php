<?php
namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Core\Config;

class AISearchService
{
    private ClaudeService $claude;
    private ?VectorSearchService $vectorSearch = null;
    private $db;
    private bool $useSemanticSearch = false;

    public function __construct()
    {
        $this->claude = new ClaudeService();
        $this->db = Database::getInstance();

        // Initialiser la recherche sémantique si disponible
        $embeddingsEnabled = Config::get('embeddings.enabled', false);
        if ($embeddingsEnabled) {
            try {
                $this->vectorSearch = new VectorSearchService();
                $this->useSemanticSearch = $this->vectorSearch->isAvailable();
            } catch (\Exception $e) {
                error_log("AISearchService: VectorSearchService init failed: " . $e->getMessage());
                $this->useSemanticSearch = false;
            }
        }
    }

    /**
     * Vérifie si la recherche sémantique est disponible
     */
    public function isSemanticSearchAvailable(): bool
    {
        return $this->useSemanticSearch && $this->vectorSearch !== null;
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
     * Convertit une question en filtres SQL via Claude
     */
    private function questionToFilters(string $question): array
    {
        // Si Claude n'est pas configuré, utiliser une recherche simple
        if (!$this->claude->isConfigured()) {
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
            
            $response = $this->claude->sendMessage($prompt);
            if (!$response) {
                return ['text_search' => $question]; // Fallback
            }
            
            $text = $this->claude->extractText($response);
            
            // Parser le JSON
            $text = preg_replace('/^```json\s*/', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
            
            $filters = json_decode($text, true);
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
     */
    private function generateAnswer(string $question, array $documents, array $filters): string
    {
        if (empty($documents)) {
            return "Je n'ai trouvé aucun document correspondant à votre recherche.";
        }
        
        // Si Claude n'est pas configuré, retourner une réponse simple
        if (!$this->claude->isConfigured()) {
            $count = count($documents);
            $answer = "J'ai trouvé $count document(s) correspondant à votre recherche.";
            
            // Ajouter quelques détails
            if ($count > 0 && $count <= 5) {
                $answer .= "\n\nDocuments trouvés :\n";
                foreach ($documents as $i => $doc) {
                    $title = $doc['title'] ?? $doc['original_filename'] ?? 'Sans titre';
                    $answer .= ($i + 1) . ". $title\n";
                }
            }
            
            return $answer;
        }
        
        // Calculer des statistiques si demandé
        $stats = [];
        if (!empty($filters['aggregation'])) {
            switch ($filters['aggregation']) {
                case 'sum_amount':
                    $total = array_sum(array_column($documents, 'amount'));
                    $stats['total'] = number_format($total, 2, '.', ' ') . ' CHF';
                    break;
                case 'avg_amount':
                    $amounts = array_filter(array_column($documents, 'amount'));
                    $avg = count($amounts) > 0 ? array_sum($amounts) / count($amounts) : 0;
                    $stats['average'] = number_format($avg, 2, '.', ' ') . ' CHF';
                    break;
            }
        }
        
        try {
            // Construire le contexte pour Claude
            $context = "Documents trouvés (" . count($documents) . ") :\n\n";
            foreach (array_slice($documents, 0, 10) as $i => $doc) {
                $context .= ($i + 1) . ". " . ($doc['title'] ?? $doc['original_filename']) . "\n";
                $context .= "   - Correspondant: " . ($doc['correspondent_name'] ?? 'N/A') . "\n";
                $context .= "   - Date: " . ($doc['document_date'] ?? 'N/A') . "\n";
                $context .= "   - Montant: " . ($doc['amount'] ? number_format($doc['amount'], 2) . ' CHF' : 'N/A') . "\n";
                if (!empty($doc['tags'])) {
                    $context .= "   - Tags: " . implode(', ', $doc['tags']) . "\n";
                }
                $context .= "\n";
            }
            
            if (!empty($stats)) {
                $context .= "Statistiques:\n";
                foreach ($stats as $key => $value) {
                    $context .= "- $key: $value\n";
                }
            }
            
            $prompt = <<<PROMPT
Question de l'utilisateur: $question

$context

Génère une réponse concise et utile en français qui:
1. Répond directement à la question
2. Cite les documents pertinents
3. Donne des statistiques si demandé (totaux, moyennes)
4. Reste factuel et précis

Réponds directement, sans formatage markdown excessif.
PROMPT;

            $response = $this->claude->sendMessage($prompt);
            if (!$response) {
                // Fallback simple
                return "J'ai trouvé " . count($documents) . " document(s) correspondant à votre recherche.";
            }
            
            return $this->claude->extractText($response);
        } catch (\Exception $e) {
            error_log("AISearchService::generateAnswer error: " . $e->getMessage());
            return "J'ai trouvé " . count($documents) . " document(s) correspondant à votre recherche.";
        }
    }
    
    /**
     * Recherche rapide (pour la barre de recherche)
     * Utilise la recherche hybride si Qdrant est disponible
     */
    public function quickSearch(string $query, int $limit = 10): array
    {
        // Utiliser la recherche hybride si disponible
        if ($this->isSemanticSearchAvailable()) {
            try {
                $results = $this->vectorSearch->hybridSearch($query, $limit, [], 0.6);
                if (!empty($results)) {
                    // Formater pour compatibilité avec l'ancien format
                    return array_map(function ($item) {
                        $doc = $item['document'];
                        return [
                            'id' => $doc['id'],
                            'title' => $doc['title'],
                            'original_filename' => $doc['original_filename'],
                            'document_date' => $doc['document_date'] ?? null,
                            'amount' => $doc['amount'] ?? null,
                            'correspondent_name' => $doc['correspondent_name'] ?? null,
                            '_search_score' => $item['score'] ?? 0,
                            '_semantic_score' => $item['semantic_score'] ?? 0,
                        ];
                    }, $results);
                }
            } catch (\Exception $e) {
                error_log("quickSearch semantic failed, falling back to SQL: " . $e->getMessage());
            }
        }

        // Fallback sur la recherche SQL classique
        return $this->quickSearchSQL($query, $limit);
    }

    /**
     * Recherche SQL classique (fallback)
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
              AND (d.title LIKE ? OR d.content LIKE ? OR d.original_filename LIKE ?
                   OR c.name LIKE ?)
            ORDER BY d.document_date DESC
            LIMIT ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$search, $search, $search, $search, $limit]);

        return $stmt->fetchAll();
    }

    /**
     * Recherche sémantique pure (sans fallback)
     */
    public function semanticSearch(string $query, int $limit = 10, array $filters = []): array
    {
        if (!$this->isSemanticSearchAvailable()) {
            return [];
        }

        try {
            return $this->vectorSearch->search($query, $limit, $filters);
        } catch (\Exception $e) {
            error_log("semanticSearch error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Recherche hybride (sémantique + keyword)
     */
    public function hybridSearch(string $query, int $limit = 10, array $filters = [], float $semanticWeight = 0.7): array
    {
        if (!$this->isSemanticSearchAvailable()) {
            // Fallback sur recherche SQL pure
            return $this->executeSearch(['text_search' => $query, 'limit' => $limit]);
        }

        try {
            return $this->vectorSearch->hybridSearch($query, $limit, $filters, $semanticWeight);
        } catch (\Exception $e) {
            error_log("hybridSearch error: " . $e->getMessage());
            // Fallback sur recherche SQL
            return $this->executeSearch(['text_search' => $query, 'limit' => $limit]);
        }
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
            return $this->vectorSearch->findSimilar($documentId, $limit);
        } catch (\Exception $e) {
            error_log("findSimilarDocuments error: " . $e->getMessage());
            return [];
        }
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
     * Résume un document
     */
    public function summarizeDocument(int $documentId): ?string
    {
        $stmt = $this->db->prepare("SELECT * FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $doc = $stmt->fetch();
        
        if (!$doc || empty($doc['content'])) {
            return null;
        }
        
        $content = substr($doc['content'], 0, 10000); // Limiter
        
        $prompt = <<<PROMPT
Résume ce document de manière concise (3-5 phrases) :

Titre: {$doc['title']}
Type: Document administratif
Contenu:
$content

Résumé:
PROMPT;

        $response = $this->claude->sendMessage($prompt);
        if (!$response) {
            return null;
        }
        
        return $this->claude->extractText($response);
    }
}

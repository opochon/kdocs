<?php
/**
 * K-Docs - NaturalLanguageQueryService
 * Service de conversion de questions en langage naturel en requêtes de recherche
 */

namespace KDocs\Services;

use KDocs\Search\SearchQuery;
use KDocs\Search\SearchQueryBuilder;

class NaturalLanguageQueryService
{
    private ClaudeService $claudeService;
    private SearchService $searchService;
    
    public function __construct()
    {
        $this->claudeService = new ClaudeService();
        $this->searchService = new SearchService();
    }
    
    /**
     * Process a natural language question and return search results
     *
     * @param string $question The natural language question
     * @param array $options Additional search options:
     *   - scope: 'all', 'name', or 'content'
     *   - date_from: Date string (YYYY-MM-DD)
     *   - date_to: Date string (YYYY-MM-DD)
     *   - folder_id: Limit search to specific folder
     */
    public function query(string $question, array $options = []): \KDocs\Search\SearchResult
    {
        // Convert question to search query using AI
        $searchQuery = $this->questionToSearchQuery($question);

        if ($searchQuery === null) {
            // Fall back to simple text search
            $searchQuery = new SearchQuery();
            $searchQuery->text = $question;
        }

        // Apply additional options
        if (!empty($options['scope'])) {
            $searchQuery->searchScope = $options['scope'];
        }
        if (!empty($options['date_from'])) {
            $searchQuery->dateFrom = $options['date_from'];
        }
        if (!empty($options['date_to'])) {
            $searchQuery->dateTo = $options['date_to'];
        }
        if (!empty($options['folder_id'])) {
            $searchQuery->folderId = (int)$options['folder_id'];
        }

        // Execute search
        $result = $this->searchService->advancedSearch($searchQuery);

        // Generate AI response summary
        $result->aiResponse = $this->generateResponseSummary($question, $result);

        return $result;
    }
    
    /**
     * Convert a natural language question to a SearchQuery using AI
     */
    public function questionToSearchQuery(string $question): ?SearchQuery
    {
        if (!$this->claudeService->isConfigured()) {
            return null;
        }
        
        $prompt = $this->buildConversionPrompt($question);
        
        try {
            $response = $this->claudeService->sendMessage($prompt);

            if (empty($response)) {
                return null;
            }

            // Extract text from Claude response (sendMessage returns array, not string)
            $responseText = $this->claudeService->extractText($response);

            if (empty($responseText)) {
                return null;
            }

            // Try to extract JSON from response
            $data = $this->parseJsonResponse($responseText);
            
            if ($data === null) {
                return null;
            }
            
            return $this->dataToSearchQuery($data);
        } catch (\Exception $e) {
            error_log('NL query conversion failed: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Generate a natural language response summary
     */
    public function generateResponseSummary(string $question, \KDocs\Search\SearchResult $result): string
    {
        if ($result->total === 0) {
            return "Je n'ai trouvé aucun document correspondant à votre recherche.";
        }

        $questionLower = mb_strtolower($question);

        // Detect counting questions (combien de fois, nombre de, count)
        if (preg_match('/combien\s+(de\s+fois|d\'occurrences?)|nombre\s+de\s+fois/ui', $questionLower)) {
            return $this->generateCountingResponse($question, $result);
        }

        // Detect quantity questions (combien de documents/factures/etc)
        if (preg_match('/combien\s+de\s+(\w+)/ui', $questionLower, $matches)) {
            return $this->generateQuantityResponse($question, $result, $matches[1]);
        }

        // Default summary response
        return $this->generateDefaultSummary($question, $result);
    }

    /**
     * Generate response for "combien de fois" questions
     */
    private function generateCountingResponse(string $question, \KDocs\Search\SearchResult $result): string
    {
        // Extract the search term from the question
        $searchTerm = $result->query;
        if (empty($searchTerm)) {
            // Try to extract from question
            if (preg_match('/(?:mot|terme|texte|expression)\s+["\']?(\w+)["\']?/ui', $question, $matches)) {
                $searchTerm = $matches[1];
            } elseif (preg_match('/combien\s+de\s+fois\s+(?:le\s+mot\s+)?["\']?(\w+)["\']?/ui', $question, $matches)) {
                $searchTerm = $matches[1];
            }
        }

        if (empty($searchTerm)) {
            return $this->generateDefaultSummary($question, $result);
        }

        // Count occurrences in all documents
        $totalOccurrences = 0;
        $docOccurrences = [];

        foreach ($result->documents as $doc) {
            $content = ($doc['content'] ?? '') . ' ' . ($doc['ocr_text'] ?? '') . ' ' . ($doc['title'] ?? '');
            $count = mb_substr_count(mb_strtolower($content), mb_strtolower($searchTerm));
            if ($count > 0) {
                $totalOccurrences += $count;
                $docOccurrences[] = [
                    'title' => $doc['title'] ?? $doc['filename'] ?? 'Sans titre',
                    'count' => $count,
                    'id' => $doc['id']
                ];
            }
        }

        // Sort by count descending
        usort($docOccurrences, fn($a, $b) => $b['count'] <=> $a['count']);

        $summary = "Le mot \"**{$searchTerm}**\" apparaît **{$totalOccurrences} fois** dans **" . count($docOccurrences) . " document(s)**.";

        if (!empty($docOccurrences)) {
            $summary .= "\n\nRépartition :";
            foreach (array_slice($docOccurrences, 0, 5) as $doc) {
                $summary .= "\n• {$doc['title']} : {$doc['count']} occurrence(s)";
            }
            if (count($docOccurrences) > 5) {
                $summary .= "\n• ... et " . (count($docOccurrences) - 5) . " autre(s) document(s)";
            }
        }

        return $summary;
    }

    /**
     * Generate response for "combien de documents/factures" questions
     */
    private function generateQuantityResponse(string $question, \KDocs\Search\SearchResult $result, string $itemType): string
    {
        $count = $result->total;
        $itemType = mb_strtolower(trim($itemType));

        // Remove trailing 's' if present for singularization
        $itemType = rtrim($itemType, 's');

        // Pluralize correctly in French
        $label = $count <= 1 ? $itemType : $itemType . 's';

        $summary = "J'ai trouvé **{$count} {$label}**";

        // Add type breakdown if available
        $types = [];
        foreach ($result->documents as $doc) {
            $type = $doc['document_type_name'] ?? 'Non classé';
            $types[$type] = ($types[$type] ?? 0) + 1;
        }

        if (count($types) > 1) {
            arsort($types);
            $summary .= " :\n";
            foreach ($types as $type => $typeCount) {
                $summary .= "\n• {$type} : {$typeCount}";
            }
        } else {
            $summary .= ".";
        }

        // Add date range
        $dates = array_filter(array_map(function($d) {
            return !empty($d['created_at']) ? new \DateTime($d['created_at']) : null;
        }, $result->documents));

        if (count($dates) >= 2) {
            usort($dates, fn($a, $b) => $a <=> $b);
            $oldest = reset($dates)->format('d/m/Y');
            $newest = end($dates)->format('d/m/Y');
            if ($oldest !== $newest) {
                $summary .= "\n\n📅 Période : du {$oldest} au {$newest}";
            }
        }

        return $summary;
    }

    /**
     * Generate default summary
     */
    private function generateDefaultSummary(string $question, \KDocs\Search\SearchResult $result): string
    {
        $summary = "J'ai trouvé **{$result->total} document(s)**";

        if ($result->total === 1 && !empty($result->documents)) {
            $doc = $result->documents[0];
            $summary .= " : \"" . ($doc['title'] ?? 'Sans titre') . "\"";
            if (!empty($doc['created_at'])) {
                $date = new \DateTime($doc['created_at']);
                $summary .= " du " . $date->format('d/m/Y');
            }
        } else if ($result->total > 1) {
            // Types breakdown
            $types = [];
            foreach ($result->documents as $doc) {
                $type = $doc['document_type_name'] ?? null;
                if ($type) {
                    $types[$type] = ($types[$type] ?? 0) + 1;
                }
            }
            if (!empty($types)) {
                arsort($types);
                $typeParts = [];
                foreach (array_slice($types, 0, 3, true) as $type => $count) {
                    $typeParts[] = "{$count} {$type}";
                }
                $summary .= " (" . implode(', ', $typeParts) . ")";
            }

            // Date range
            $dates = array_filter(array_map(function($d) {
                return !empty($d['created_at']) ? new \DateTime($d['created_at']) : null;
            }, $result->documents));

            if (!empty($dates)) {
                usort($dates, fn($a, $b) => $a <=> $b);
                $oldest = reset($dates)->format('d/m/Y');
                $newest = end($dates)->format('d/m/Y');
                if ($oldest !== $newest) {
                    $summary .= ", du {$oldest} au {$newest}";
                }
            }
        }

        $summary .= ".";

        return $summary;
    }
    
    /**
     * Build the prompt for converting question to search filters
     */
    private function buildConversionPrompt(string $question): string
    {
        $currentDate = date('Y-m-d');
        $currentYear = date('Y');
        $currentMonth = date('m');
        
        return <<<PROMPT
Tu es un assistant qui convertit des questions en français sur des documents en filtres de recherche JSON.

Question utilisateur: "{$question}"

Date actuelle: {$currentDate}

Convertis cette question en filtres de recherche JSON. Voici les filtres disponibles:

- text: recherche textuelle dans le contenu et titre
- correspondent_name: nom du correspondant/expéditeur (partiel OK)
- document_type_name: type de document (facture, contrat, etc.)
- tag_names: liste de tags ["tag1", "tag2"]
- created_after: date de début au format YYYY-MM-DD
- created_before: date de fin au format YYYY-MM-DD
- category: catégorie (assurance, banque, energie, telecom, sante, impots, etc.)
- sort: champ de tri (created_at, added_at, title)
- sort_dir: direction (asc, desc)
- limit: nombre max de résultats (défaut 25)
- with_aggregations: true pour calculer des totaux

Exemples de conversions:
- "Dernière facture Swisscom" → {"correspondent_name": "swisscom", "document_type_name": "facture", "sort": "created_at", "sort_dir": "desc", "limit": 1}
- "Documents de 2024" → {"created_after": "2024-01-01", "created_before": "2024-12-31"}
- "Factures énergie ce mois" → {"category": "energie", "document_type_name": "facture", "created_after": "{$currentYear}-{$currentMonth}-01"}
- "Tout de la banque" → {"category": "banque"}

Réponds UNIQUEMENT avec le JSON des filtres, sans explication.
PROMPT;
    }
    
    /**
     * Parse JSON from AI response
     */
    private function parseJsonResponse(string $response): ?array
    {
        // Try to extract JSON from response (might be wrapped in markdown code blocks)
        $json = $response;
        
        // Remove markdown code blocks if present
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $response, $matches)) {
            $json = $matches[1];
        }
        
        // Try to find JSON object
        if (preg_match('/\{.*\}/s', $json, $matches)) {
            $json = $matches[0];
        }
        
        $data = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Failed to parse JSON from AI response: ' . json_last_error_msg());
            return null;
        }
        
        return $data;
    }
    
    /**
     * Convert AI response data to SearchQuery
     */
    private function dataToSearchQuery(array $data): SearchQuery
    {
        $builder = SearchQueryBuilder::create();
        
        if (!empty($data['text'])) {
            $builder->whereText($data['text']);
        }
        
        if (!empty($data['correspondent_name'])) {
            $builder->whereCorrespondentName($data['correspondent_name']);
        }
        
        if (!empty($data['correspondent_id'])) {
            $builder->whereCorrespondent((int) $data['correspondent_id']);
        }
        
        if (!empty($data['document_type_name'])) {
            $builder->whereDocumentTypeName($data['document_type_name']);
        }
        
        if (!empty($data['document_type_id'])) {
            $builder->whereDocumentType((int) $data['document_type_id']);
        }
        
        if (!empty($data['tag_names'])) {
            foreach ($data['tag_names'] as $tagName) {
                $builder->whereTagName($tagName);
            }
        }
        
        if (!empty($data['tag_ids'])) {
            $builder->whereHasTags($data['tag_ids'], $data['tags_match_all'] ?? false);
        }
        
        if (!empty($data['created_after'])) {
            $builder->whereCreatedAfter($data['created_after']);
        }
        
        if (!empty($data['created_before'])) {
            $builder->whereCreatedBefore($data['created_before']);
        }
        
        if (!empty($data['added_after'])) {
            $builder->whereAddedAfter($data['added_after']);
        }
        
        if (!empty($data['added_before'])) {
            $builder->whereAddedBefore($data['added_before']);
        }
        
        if (!empty($data['category'])) {
            $builder->whereCategory($data['category']);
        }
        
        if (!empty($data['mime_type'])) {
            $builder->whereMimeType($data['mime_type']);
        }
        
        // Sorting
        $sort = $data['sort'] ?? 'created_at';
        $sortDir = $data['sort_dir'] ?? 'desc';
        $builder->orderBy($sort, $sortDir);
        
        // Pagination
        $limit = min(100, max(1, (int) ($data['limit'] ?? 25)));
        $page = max(1, (int) ($data['page'] ?? 1));
        $builder->page($page, $limit);
        
        // Aggregations
        if (!empty($data['with_aggregations'])) {
            $builder->withAggregations($data['aggregations'] ?? ['sum', 'count']);
        }
        
        return $builder->build();
    }
}

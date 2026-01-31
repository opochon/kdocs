<?php
/**
 * K-DOCS - MySQL FULLTEXT Search Provider
 * 
 * Provider de recherche par défaut utilisant MySQL FULLTEXT.
 * Toujours disponible, ne nécessite aucune dépendance externe.
 * 
 * Supporte:
 * - Recherche booléenne: +mot1 +mot2 (AND), mot1 mot2 (OR), -mot (NOT)
 * - Recherche par phrase: "phrase exacte"
 * - Wildcard: mot* (préfixe)
 * - Scoring par pertinence natif MySQL
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use PDO;

class MySQLFullTextProvider implements SearchProviderInterface
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getName(): string
    {
        return 'mysql_fulltext';
    }

    public function isAvailable(): bool
    {
        // Vérifier que l'index FULLTEXT existe
        try {
            $stmt = $this->db->query("
                SHOW INDEX FROM documents 
                WHERE Index_type = 'FULLTEXT' AND Key_name = 'idx_documents_fulltext'
            ");
            return $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            // Si pas d'index, on peut quand même faire du LIKE (fallback)
            return true;
        }
    }

    public function getCapabilities(): array
    {
        return [
            'fulltext' => true,
            'semantic' => false,
            'fuzzy' => false,
            'boolean' => true,
            'phrase' => true,
            'wildcard' => true,
        ];
    }

    public function search(string $query, array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $startTime = microtime(true);

        // Si requête vide, retourner les documents récents
        if (empty(trim($query))) {
            return $this->getRecentDocuments($filters, $limit, $offset);
        }

        // Parser la requête utilisateur en format BOOLEAN MODE
        $booleanQuery = $this->parseQueryToBooleanMode($query);

        // Vérifier si l'index FULLTEXT existe
        $hasFulltextIndex = $this->hasFulltextIndex();

        if ($hasFulltextIndex && !empty($booleanQuery)) {
            $result = $this->searchWithFullText($booleanQuery, $query, $filters, $limit, $offset);
        } else {
            // Fallback sur LIKE si pas d'index
            $result = $this->searchWithLike($query, $filters, $limit, $offset);
        }

        $result['search_time'] = microtime(true) - $startTime;
        $result['provider'] = $this->getName();

        return $result;
    }

    /**
     * Recherche avec FULLTEXT INDEX
     */
    private function searchWithFullText(string $booleanQuery, string $originalQuery, array $filters, int $limit, int $offset): array
    {
        $params = [];
        $whereConditions = ['d.deleted_at IS NULL'];

        // Ajouter les filtres
        $filterSql = $this->buildFilterConditions($filters, $params);
        if (!empty($filterSql)) {
            $whereConditions[] = $filterSql;
        }

        // Construire la requête MATCH AGAINST
        $params['query'] = $booleanQuery;

        $sql = "
            SELECT 
                d.*,
                c.name AS correspondent_name,
                dt.label AS document_type_label,
                MATCH(d.title, d.ocr_text, d.content) AGAINST(:query IN BOOLEAN MODE) AS relevance_score
            FROM documents d
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            WHERE " . implode(' AND ', $whereConditions) . "
            AND MATCH(d.title, d.ocr_text, d.content) AGAINST(:query IN BOOLEAN MODE)
            ORDER BY relevance_score DESC, d.created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $countSql = "
            SELECT COUNT(*) 
            FROM documents d
            WHERE " . implode(' AND ', $whereConditions) . "
            AND MATCH(d.title, d.ocr_text, d.content) AGAINST(:query IN BOOLEAN MODE)
        ";

        // Exécuter la requête principale
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':query', $booleanQuery, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($params as $key => $value) {
            if ($key !== 'query') {
                $stmt->bindValue(':' . $key, $value);
            }
        }
        $stmt->execute();
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Compter le total
        $countStmt = $this->db->prepare($countSql);
        $countStmt->bindValue(':query', $booleanQuery, PDO::PARAM_STR);
        foreach ($params as $key => $value) {
            if ($key !== 'query') {
                $countStmt->bindValue(':' . $key, $value);
            }
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        // Enrichir avec les excerpts
        $documents = $this->addExcerpts($documents, $originalQuery);

        return [
            'documents' => $documents,
            'total' => $total,
            'query_type' => 'fulltext',
        ];
    }

    /**
     * Fallback recherche avec LIKE (si pas d'index FULLTEXT)
     */
    private function searchWithLike(string $query, array $filters, int $limit, int $offset): array
    {
        $params = [];
        $whereConditions = ['d.deleted_at IS NULL'];

        // Filtres
        $filterSql = $this->buildFilterConditions($filters, $params);
        if (!empty($filterSql)) {
            $whereConditions[] = $filterSql;
        }

        // Recherche LIKE sur plusieurs colonnes
        $searchTerms = $this->extractSearchTerms($query);
        if (!empty($searchTerms)) {
            $termConditions = [];
            foreach ($searchTerms as $i => $term) {
                $paramName = "term_{$i}";
                $params[$paramName] = '%' . $term . '%';
                $termConditions[] = "(d.title LIKE :{$paramName} OR d.ocr_text LIKE :{$paramName} OR d.content LIKE :{$paramName} OR d.original_filename LIKE :{$paramName})";
            }
            $whereConditions[] = '(' . implode(' AND ', $termConditions) . ')';
        }

        $sql = "
            SELECT 
                d.*,
                c.name AS correspondent_name,
                dt.label AS document_type_label,
                0 AS relevance_score
            FROM documents d
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            WHERE " . implode(' AND ', $whereConditions) . "
            ORDER BY d.created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $countSql = "SELECT COUNT(*) FROM documents d WHERE " . implode(' AND ', $whereConditions);

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countStmt = $this->db->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue(':' . $key, $value);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        // Enrichir avec les excerpts
        $documents = $this->addExcerpts($documents, $query);

        // Calculer un score basique
        foreach ($documents as &$doc) {
            $doc['relevance_score'] = $this->calculateBasicScore($doc, $searchTerms);
        }
        usort($documents, fn($a, $b) => $b['relevance_score'] <=> $a['relevance_score']);

        return [
            'documents' => $documents,
            'total' => $total,
            'query_type' => 'like_fallback',
        ];
    }

    /**
     * Documents récents (pas de recherche)
     */
    private function getRecentDocuments(array $filters, int $limit, int $offset): array
    {
        $params = [];
        $whereConditions = ['d.deleted_at IS NULL'];

        $filterSql = $this->buildFilterConditions($filters, $params);
        if (!empty($filterSql)) {
            $whereConditions[] = $filterSql;
        }

        $sql = "
            SELECT 
                d.*,
                c.name AS correspondent_name,
                dt.label AS document_type_label,
                0 AS relevance_score
            FROM documents d
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            WHERE " . implode(' AND ', $whereConditions) . "
            ORDER BY d.created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countSql = "SELECT COUNT(*) FROM documents d WHERE " . implode(' AND ', $whereConditions);
        $countStmt = $this->db->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue(':' . $key, $value);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        return [
            'documents' => $documents,
            'total' => $total,
            'query_type' => 'recent',
        ];
    }

    /**
     * Convertit une requête utilisateur en format BOOLEAN MODE MySQL
     * 
     * Exemples:
     * "facture swisscom" → "+facture +swisscom" (AND implicite)
     * "facture OR devis" → "facture devis" (OR)
     * '"facture janvier"' → '"facture janvier"' (phrase exacte)
     * "facture -annulée" → "+facture -annulée" (NOT)
     */
    private function parseQueryToBooleanMode(string $query): string
    {
        $query = trim($query);
        if (empty($query)) {
            return '';
        }

        // Détecter les phrases entre guillemets
        $phrases = [];
        $query = preg_replace_callback('/"([^"]+)"/', function($matches) use (&$phrases) {
            $placeholder = '__PHRASE_' . count($phrases) . '__';
            $phrases[$placeholder] = '"' . $matches[1] . '"';
            return $placeholder;
        }, $query);

        // Gérer les opérateurs explicites
        $query = preg_replace('/\bAND\b/i', '+', $query);
        $query = preg_replace('/\bOR\b/i', '', $query);
        $query = preg_replace('/\bNOT\b/i', '-', $query);

        // Extraire les termes
        $terms = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);

        $result = [];
        foreach ($terms as $term) {
            // Restaurer les phrases
            if (isset($phrases[$term])) {
                $result[] = $phrases[$term];
                continue;
            }

            // Ignorer les termes trop courts (sauf opérateurs)
            if (strlen($term) < 2 && !in_array($term[0] ?? '', ['+', '-'])) {
                continue;
            }

            // Si pas de préfixe +/-, ajouter + (AND implicite)
            if (!in_array($term[0], ['+', '-', '"', '*'])) {
                $term = '+' . $term;
            }

            // Ajouter wildcard si pas de * final et pas de phrase
            if (substr($term, -1) !== '*' && $term[0] !== '"') {
                $term .= '*';
            }

            $result[] = $term;
        }

        return implode(' ', $result);
    }

    /**
     * Extrait les termes de recherche (pour LIKE fallback)
     */
    private function extractSearchTerms(string $query): array
    {
        // Supprimer les guillemets et opérateurs
        $query = preg_replace('/["+\-*]/', ' ', $query);
        $query = preg_replace('/\b(AND|OR|NOT)\b/i', ' ', $query);
        
        $terms = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
        
        // Filtrer les termes trop courts
        return array_filter($terms, fn($t) => mb_strlen($t) >= 2);
    }

    /**
     * Construit les conditions SQL pour les filtres
     */
    private function buildFilterConditions(array $filters, array &$params): string
    {
        $conditions = [];

        if (!empty($filters['correspondent_id'])) {
            $conditions[] = 'd.correspondent_id = :correspondent_id';
            $params['correspondent_id'] = $filters['correspondent_id'];
        }

        if (!empty($filters['document_type_id'])) {
            $conditions[] = 'd.document_type_id = :document_type_id';
            $params['document_type_id'] = $filters['document_type_id'];
        }

        if (!empty($filters['date_from'])) {
            $conditions[] = 'DATE(d.created_at) >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $conditions[] = 'DATE(d.created_at) <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }

        if (!empty($filters['folder_id'])) {
            $conditions[] = 'd.folder_id = :folder_id';
            $params['folder_id'] = $filters['folder_id'];
        }

        if (!empty($filters['logical_folder_id'])) {
            $conditions[] = 'd.logical_folder_id = :logical_folder_id';
            $params['logical_folder_id'] = $filters['logical_folder_id'];
        }

        if (!empty($filters['validation_status'])) {
            $conditions[] = 'd.validation_status = :validation_status';
            $params['validation_status'] = $filters['validation_status'];
        }

        if (!empty($filters['status'])) {
            $conditions[] = 'd.status = :status';
            $params['status'] = $filters['status'];
        }

        // Tags (IN clause)
        if (!empty($filters['tag_ids']) && is_array($filters['tag_ids'])) {
            $tagPlaceholders = [];
            foreach ($filters['tag_ids'] as $i => $tagId) {
                $paramName = "tag_id_{$i}";
                $tagPlaceholders[] = ":{$paramName}";
                $params[$paramName] = $tagId;
            }
            $conditions[] = "d.id IN (SELECT document_id FROM document_tags WHERE tag_id IN (" . implode(',', $tagPlaceholders) . "))";
        }

        return implode(' AND ', $conditions);
    }

    /**
     * Ajoute des excerpts avec highlighting
     */
    private function addExcerpts(array $documents, string $query): array
    {
        $searchTerms = $this->extractSearchTerms($query);
        
        foreach ($documents as &$doc) {
            $text = $doc['ocr_text'] ?? $doc['content'] ?? '';
            $doc['excerpts'] = $this->extractExcerpts($text, $searchTerms, 2);
        }
        
        return $documents;
    }

    /**
     * Extrait des excerpts avec contexte autour des termes trouvés
     */
    private function extractExcerpts(string $text, array $terms, int $maxExcerpts = 2): array
    {
        if (empty($text) || empty($terms)) {
            return [];
        }

        $excerpts = [];
        $textLower = mb_strtolower($text);
        $contextLength = 100;

        foreach ($terms as $term) {
            $termLower = mb_strtolower($term);
            $pos = mb_strpos($textLower, $termLower);
            
            if ($pos !== false && count($excerpts) < $maxExcerpts) {
                $start = max(0, $pos - $contextLength);
                $end = min(mb_strlen($text), $pos + mb_strlen($term) + $contextLength);
                
                $excerpt = mb_substr($text, $start, $end - $start);
                $excerpt = preg_replace('/\s+/', ' ', trim($excerpt));
                
                // Highlight
                $excerpt = preg_replace(
                    '/(' . preg_quote($term, '/') . ')/iu',
                    '<mark>$1</mark>',
                    $excerpt
                );
                
                if ($start > 0) $excerpt = '...' . $excerpt;
                if ($end < mb_strlen($text)) $excerpt .= '...';
                
                $excerpts[] = $excerpt;
            }
        }

        return $excerpts;
    }

    /**
     * Calcule un score basique pour le fallback LIKE
     */
    private function calculateBasicScore(array $doc, array $terms): int
    {
        $score = 0;
        $title = mb_strtolower($doc['title'] ?? '');
        $content = mb_strtolower($doc['ocr_text'] ?? $doc['content'] ?? '');

        foreach ($terms as $term) {
            $termLower = mb_strtolower($term);
            
            // Match dans le titre: 30 points
            if (mb_strpos($title, $termLower) !== false) {
                $score += 30;
            }
            
            // Match dans le contenu: 10 points par occurrence (max 50)
            $count = mb_substr_count($content, $termLower);
            $score += min(50, $count * 10);
        }

        return $score;
    }

    /**
     * Vérifie si l'index FULLTEXT existe
     */
    private function hasFulltextIndex(): bool
    {
        static $hasIndex = null;
        
        if ($hasIndex === null) {
            try {
                $stmt = $this->db->query("
                    SHOW INDEX FROM documents 
                    WHERE Index_type = 'FULLTEXT'
                ");
                $hasIndex = $stmt->rowCount() > 0;
            } catch (\Exception $e) {
                $hasIndex = false;
            }
        }
        
        return $hasIndex;
    }

    /**
     * Suggestions d'autocomplétion
     */
    public function suggest(string $partial, int $limit = 10): array
    {
        if (mb_strlen($partial) < 2) {
            return [];
        }

        $suggestions = [];
        $pattern = '%' . $partial . '%';

        // Titres de documents
        $stmt = $this->db->prepare("
            SELECT DISTINCT title, 'document' as type
            FROM documents
            WHERE title LIKE :pattern AND deleted_at IS NULL
            LIMIT :limit
        ");
        $stmt->bindValue(':pattern', $pattern);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        foreach ($stmt->fetchAll() as $row) {
            $suggestions[] = ['text' => $row['title'], 'type' => 'document'];
        }

        // Correspondants
        $stmt = $this->db->prepare("
            SELECT name, 'correspondent' as type
            FROM correspondents
            WHERE name LIKE :pattern
            LIMIT :limit
        ");
        $stmt->bindValue(':pattern', $pattern);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        foreach ($stmt->fetchAll() as $row) {
            $suggestions[] = ['text' => $row['name'], 'type' => 'correspondent'];
        }

        // Tags
        $stmt = $this->db->prepare("
            SELECT name, 'tag' as type
            FROM tags
            WHERE name LIKE :pattern
            LIMIT :limit
        ");
        $stmt->bindValue(':pattern', $pattern);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        foreach ($stmt->fetchAll() as $row) {
            $suggestions[] = ['text' => $row['name'], 'type' => 'tag'];
        }

        return array_slice($suggestions, 0, $limit);
    }
}

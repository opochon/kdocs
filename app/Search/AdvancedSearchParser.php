<?php
/**
 * K-Docs - AdvancedSearchParser
 * Parse search queries with operators: AND, OR, "exact phrase", wildcards * ?
 *
 * Examples:
 *   facture AND 2024           -> must contain both
 *   facture OR devis           -> must contain either
 *   "contrat de bail"          -> exact phrase
 *   fact*                      -> wildcard (facture, facturation, etc)
 *   t?st                       -> single char wildcard (test, tost, etc)
 *   facture AND (2024 OR 2025) -> grouped conditions
 */

namespace KDocs\Search;

class AdvancedSearchParser
{
    /**
     * Parse a search query into SQL conditions
     *
     * @param string $query The search query
     * @param string $scope Search scope: 'name', 'content', 'all'
     * @return array ['sql' => string, 'params' => array, 'terms' => array]
     */
    public function parse(string $query, string $scope = 'all'): array
    {
        $query = trim($query);

        if (empty($query)) {
            return ['sql' => '1=1', 'params' => [], 'terms' => []];
        }

        // Extract quoted phrases first
        $phrases = [];
        $query = preg_replace_callback('/"([^"]+)"/', function($match) use (&$phrases) {
            $placeholder = '__PHRASE_' . count($phrases) . '__';
            $phrases[$placeholder] = $match[1];
            return $placeholder;
        }, $query);

        // Tokenize
        $tokens = $this->tokenize($query);

        // Parse into expression tree
        $tree = $this->parseExpression($tokens, $phrases);

        // Generate SQL
        return $this->generateSql($tree, $scope, $phrases);
    }

    /**
     * Tokenize the query string
     */
    private function tokenize(string $query): array
    {
        $tokens = [];
        $query = preg_replace('/\s+/', ' ', $query);

        // Split by spaces but keep operators together
        $parts = preg_split('/\s+/', $query);

        foreach ($parts as $part) {
            $upper = strtoupper($part);
            if ($upper === 'AND' || $upper === 'ET') {
                $tokens[] = ['type' => 'AND'];
            } elseif ($upper === 'OR' || $upper === 'OU') {
                $tokens[] = ['type' => 'OR'];
            } elseif ($upper === 'NOT' || $upper === 'NON' || $upper === '-') {
                $tokens[] = ['type' => 'NOT'];
            } elseif ($part === '(') {
                $tokens[] = ['type' => 'LPAREN'];
            } elseif ($part === ')') {
                $tokens[] = ['type' => 'RPAREN'];
            } elseif (!empty($part)) {
                $tokens[] = ['type' => 'TERM', 'value' => $part];
            }
        }

        return $tokens;
    }

    /**
     * Parse tokens into expression tree
     */
    private function parseExpression(array $tokens, array $phrases): array
    {
        if (empty($tokens)) {
            return ['type' => 'EMPTY'];
        }

        // Simple case: single term or implicit AND between terms
        $terms = [];
        $currentOp = 'AND';
        $negate = false;

        foreach ($tokens as $token) {
            switch ($token['type']) {
                case 'AND':
                    $currentOp = 'AND';
                    break;
                case 'OR':
                    $currentOp = 'OR';
                    break;
                case 'NOT':
                    $negate = true;
                    break;
                case 'TERM':
                    $value = $token['value'];

                    // Restore phrase if placeholder
                    if (isset($phrases[$value])) {
                        $value = $phrases[$value];
                        $isPhrase = true;
                    } else {
                        $isPhrase = false;
                    }

                    // Check for wildcards
                    $hasWildcard = strpos($value, '*') !== false || strpos($value, '?') !== false;

                    $term = [
                        'type' => 'TERM',
                        'value' => $value,
                        'phrase' => $isPhrase,
                        'wildcard' => $hasWildcard,
                        'negate' => $negate
                    ];

                    if (empty($terms)) {
                        $terms[] = $term;
                    } else {
                        $terms[] = [
                            'type' => $currentOp,
                            'left' => array_pop($terms),
                            'right' => $term
                        ];
                    }

                    $negate = false;
                    $currentOp = 'AND'; // Default to AND between terms
                    break;
            }
        }

        return count($terms) === 1 ? $terms[0] : ['type' => 'GROUP', 'items' => $terms];
    }

    /**
     * Generate SQL from expression tree
     */
    private function generateSql(array $tree, string $scope, array $phrases): array
    {
        $params = [];
        $terms = [];
        $paramIndex = 0;

        $sql = $this->nodeToSql($tree, $scope, $params, $terms, $paramIndex);

        if (empty($sql) || $sql === '()') {
            $sql = '1=1';
        }

        return ['sql' => $sql, 'params' => $params, 'terms' => $terms];
    }

    /**
     * Convert a node to SQL
     */
    private function nodeToSql(array $node, string $scope, array &$params, array &$terms, int &$paramIndex): string
    {
        switch ($node['type']) {
            case 'EMPTY':
                return '1=1';

            case 'TERM':
                return $this->termToSql($node, $scope, $params, $terms, $paramIndex);

            case 'AND':
                $left = $this->nodeToSql($node['left'], $scope, $params, $terms, $paramIndex);
                $right = $this->nodeToSql($node['right'], $scope, $params, $terms, $paramIndex);
                return "($left AND $right)";

            case 'OR':
                $left = $this->nodeToSql($node['left'], $scope, $params, $terms, $paramIndex);
                $right = $this->nodeToSql($node['right'], $scope, $params, $terms, $paramIndex);
                return "($left OR $right)";

            case 'GROUP':
                $parts = [];
                foreach ($node['items'] as $item) {
                    $parts[] = $this->nodeToSql($item, $scope, $params, $terms, $paramIndex);
                }
                return '(' . implode(' AND ', $parts) . ')';

            default:
                return '1=1';
        }
    }

    /**
     * Convert a search term to SQL
     */
    private function termToSql(array $term, string $scope, array &$params, array &$terms, int &$paramIndex): string
    {
        $value = $term['value'];
        $isPhrase = $term['phrase'] ?? false;
        $hasWildcard = $term['wildcard'] ?? false;
        $negate = $term['negate'] ?? false;

        // Track terms for highlighting
        $terms[] = $value;

        // Prepare search value
        if ($hasWildcard) {
            // Convert wildcards: * -> %, ? -> _
            $searchValue = str_replace(['*', '?'], ['%', '_'], $value);
        } elseif ($isPhrase) {
            // Exact phrase
            $searchValue = '%' . $value . '%';
        } else {
            // Standard term search
            $searchValue = '%' . $value . '%';
        }

        $paramName = 'search_' . $paramIndex++;
        $params[$paramName] = $searchValue;

        // Build field conditions based on scope
        $fields = [];
        switch ($scope) {
            case 'name':
                $fields = ['d.title', 'd.filename'];
                break;
            case 'content':
                $fields = ['d.content', 'd.ocr_text'];
                break;
            case 'all':
            default:
                $fields = ['d.title', 'd.filename', 'd.content', 'd.ocr_text'];
                break;
        }

        $conditions = [];
        foreach ($fields as $field) {
            $conditions[] = "$field LIKE :$paramName";
        }

        $sql = '(' . implode(' OR ', $conditions) . ')';

        if ($negate) {
            $sql = "NOT $sql";
        }

        return $sql;
    }

    /**
     * Get plain terms for highlighting (without wildcards/operators)
     */
    public function getHighlightTerms(string $query): array
    {
        $terms = [];

        // Extract quoted phrases
        preg_match_all('/"([^"]+)"/', $query, $matches);
        foreach ($matches[1] as $phrase) {
            $terms[] = $phrase;
        }

        // Remove quoted phrases and operators
        $query = preg_replace('/"[^"]+"/', '', $query);
        $query = preg_replace('/\b(AND|OR|NOT|ET|OU|NON)\b/i', '', $query);

        // Get remaining words
        $words = preg_split('/\s+/', trim($query));
        foreach ($words as $word) {
            $word = trim($word, '*?');
            if (strlen($word) >= 2) {
                $terms[] = $word;
            }
        }

        return array_unique($terms);
    }
}
